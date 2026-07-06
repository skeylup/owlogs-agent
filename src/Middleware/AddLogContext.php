<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Skeylup\OwlogsAgent\Compat\ContextShim;
use Skeylup\OwlogsAgent\Compat\IdShim;
use Skeylup\OwlogsAgent\Contracts\HasLogContext;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Support\Redactor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AddLogContext
{
    /**
     * Handle an incoming request.
     *
     * Adds contextual data to Laravel's Context facade, which is automatically
     * appended as metadata to every log entry written during this request.
     *
     * Octane-safe: Context is reset between requests by the framework.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('owlogs.enabled', true)) {
            return $next($request);
        }

        if ($this->isIgnored($request)) {
            return RemoteHandler::suppressedWhile(fn () => $next($request));
        }

        $fields = config('owlogs.fields', []);
        $startTime = hrtime(true);

        // Defensive clear: Laravel flushes Context between Octane requests via
        // ContextServiceProvider, but any path that bypasses that reset would
        // leak measures/breadcrumbs from the previous request into this one.
        ContextShim::forgetHidden('measures');
        ContextShim::forgetHidden('breadcrumbs');

        // Tracing IDs — span_id is the per-execution identity; trace_id is
        // the cross-execution correlation. Keeping them distinct lets the
        // viewer build a parent/child tree when a request dispatches a job:
        // the job inherits the HTTP span_id as its parent_span_id via the
        // queue payload (see OwlogsAgentServiceProvider::registerQueueContext).
        if ($fields['trace_id'] ?? true) {
            ContextShim::addHidden('trace_id', IdShim::ulid());
        }

        if ($fields['span_id'] ?? true) {
            ContextShim::addHidden('span_id', IdShim::ulid());
        }

        if ($fields['origin'] ?? true) {
            ContextShim::addHidden('origin', 'http');
        }

        // App info
        if ($fields['app_name'] ?? true) {
            ContextShim::addHidden('app_name', (string) config('app.name'));
        }

        if ($fields['app_env'] ?? true) {
            ContextShim::addHidden('app_env', (string) config('app.env'));
        }

        if ($fields['app_url'] ?? true) {
            ContextShim::addHidden('app_url', (string) config('app.url'));
        }

        // Request info
        if ($fields['uri'] ?? true) {
            ContextShim::addHidden('uri', $request->method().' '.$request->fullUrl());
        }

        // Dedicated method column — lets the server filter by HTTP verb without
        // splitting `uri` (which gets rewritten by url_resolver for Livewire).
        if ($fields['http_method'] ?? true) {
            ContextShim::addHidden('http_method', $request->method());
        }

        if ($fields['ip'] ?? true) {
            ContextShim::addHidden('ip', $request->ip());
        }

        if ($fields['user_agent'] ?? true) {
            ContextShim::addHidden('user_agent', Str::limit((string) $request->userAgent(), 200, ''));
        }

        // Attempt early resolution — may be null if auth hasn't run yet.
        if ($fields['user_id'] ?? true) {
            $userId = $request->user()?->getKey();
            if ($userId !== null) {
                ContextShim::addHidden('user_id', $userId);
            }
        }

        if ($fields['git_sha'] ?? true) {
            $gitSha = self::resolveGitSha();
            if ($gitSha !== null) {
                ContextShim::addHidden('git_sha', $gitSha);
            }
        }

        // Route info
        if ($fields['route_name'] ?? true) {
            $routeName = $request->route()?->getName();
            if ($routeName !== null) {
                ContextShim::addHidden('route_name', $routeName);
            }
        }

        if ($fields['route_action'] ?? true) {
            $action = $request->route()?->getActionName();
            if ($action !== null && $action !== 'Closure') {
                ContextShim::addHidden('route_action', $action);
            }
        }

        // Custom URI resolver
        $this->applyUriResolver($request, $fields);

        // Capture request input for POST/PUT/PATCH (sanitized)
        if ($fields['request_input'] ?? true) {
            $this->captureRequestInput($request);
        }

        // Defensive try/catch: in production Illuminate\Routing\Pipeline already
        // converts pipeline exceptions into rendered responses (and tags them
        // via Response::withException), so this catch only fires when an
        // exception escapes the pipeline (handler unbound) or when the middleware
        // is invoked directly outside a pipeline (tests). Either way we surface
        // the rejection then re-throw so Laravel's handling is unchanged.
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->logRejection($request, $e, $this->statusForException($e));

            throw $e;
        }

        // Route info — re-resolve after $next()
        if (($fields['route_name'] ?? true) && ! ContextShim::hasHidden('route_name')) {
            $routeName = $request->route()?->getName();
            if ($routeName !== null) {
                ContextShim::addHidden('route_name', $routeName);
            }
        }

        if (($fields['route_action'] ?? true) && ! ContextShim::hasHidden('route_action')) {
            $action = $request->route()?->getActionName();
            if ($action !== null && $action !== 'Closure') {
                ContextShim::addHidden('route_action', $action);
            }
        }

        // User & tenant — resolved after $next() so auth middleware has run
        $user = $request->user();

        if ($fields['user_id'] ?? true) {
            $userId = $user?->getKey();
            if ($userId !== null) {
                ContextShim::addHidden('user_id', $userId);
            }
        }

        if ($user instanceof HasLogContext) {
            ContextShim::addHidden('user_context', $user->toLogContext());
            ContextShim::addHidden('user_label', $user->getLogContextLabel());
        }

        // Duration
        if ($fields['duration_ms'] ?? true) {
            $durationMs = (int) round((hrtime(true) - $startTime) / 1_000_000);
            ContextShim::addHidden('duration_ms', $durationMs);

            ContextShim::pushHidden('measures', [
                'label' => 'request',
                'duration_ms' => (float) $durationMs,
                'meta' => [],
            ]);
        }

        // Response header for traceability
        if ($fields['trace_id'] ?? true) {
            $response->headers->set('X-Trace-Id', ContextShim::getHidden('trace_id'));
        }

        $this->logFailedResponse($request, $response);

        return $response;
    }

    /**
     * Log a non-success response so it surfaces in Owlogs.
     *
     * Distinguishes a framework rejection (a thrown exception the routing
     * pipeline rendered into a response, tagged via Response::withException)
     * from a status the application deliberately returned.
     */
    private function logFailedResponse(Request $request, Response $response): void
    {
        $status = $response->getStatusCode();
        $exception = $this->responseException($response);

        // Response rendered from a thrown exception (auth / throttle / abort /
        // validation). Server errors (5xx) are already report()-ed by Laravel
        // and captured via the log stack, so we leave them out here.
        if ($exception !== null) {
            if ($status < 500) {
                $this->logRejection($request, $exception, $status);
            }

            return;
        }

        // Non-2xx status returned directly by the application.
        $config = config('owlogs.auto_log', []);

        if (! ($config['http_response'] ?? true)) {
            return;
        }

        if ($status < (int) ($config['http_response_min_status'] ?? 400)) {
            return;
        }

        Log::channel('owlogs')?->{$this->levelForStatus($status)}(
            'http.response: '.$status.' '.$request->method().' '.$request->path(),
            ['status' => $status, 'method' => $request->method(), 'path' => $request->path()],
        );
    }

    /**
     * Log a request rejected by the middleware/pipeline (e.g. auth, throttle,
     * authorization, CSRF, validation). Skips 5xx — those are server errors
     * Laravel reports through its own exception channel.
     */
    private function logRejection(Request $request, \Throwable $exception, int $status): void
    {
        if (! (config('owlogs.auto_log.middleware_rejection') ?? true)) {
            return;
        }

        if ($status >= 500) {
            return;
        }

        Log::channel('owlogs')?->{$this->levelForStatus($status)}(
            'http.rejected: '.$status.' '.$request->method().' '.$request->path().' — '.class_basename($exception),
            [
                'status' => $status,
                'method' => $request->method(),
                'path' => $request->path(),
                'exception_class' => get_class($exception),
                'exception_file' => $exception->getFile().':'.$exception->getLine(),
            ],
        );
    }

    /**
     * The exception the routing pipeline attached to a rendered error response,
     * or null when the response was produced without an exception.
     */
    private function responseException(Response $response): ?\Throwable
    {
        return (isset($response->exception) && $response->exception instanceof \Throwable)
            ? $response->exception
            : null;
    }

    /**
     * Resolve the HTTP status an exception maps to, mirroring Laravel's handler.
     */
    private function statusForException(\Throwable $e): int
    {
        return match (true) {
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            $e instanceof ValidationException => $e->status,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => $e->status() ?? 403,
            $e instanceof TokenMismatchException => 419,
            default => 500,
        };
    }

    /**
     * Map an HTTP status to a PSR log level.
     */
    private function levelForStatus(int $status): string
    {
        return match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            $status >= 300 => 'notice',
            default => 'info',
        };
    }

    private function isIgnored(Request $request): bool
    {
        $patterns = (array) config('owlogs.ignored_uris', []);

        if ($patterns === []) {
            return false;
        }

        $path = ltrim($request->path(), '/');

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            if (Str::is(ltrim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    private function applyUriResolver(Request $request, array $fields): void
    {
        $resolver = config('owlogs.uri_resolver');

        if ($resolver !== null) {
            if (is_string($resolver) && class_exists($resolver)) {
                $resolver = app($resolver);
            }
            if (is_callable($resolver)) {
                $resolver($request, $fields);
            }
        }
    }

    private function captureRequestInput(Request $request): void
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return;
        }

        // Structural noise only — `_token`/`_method` are framework plumbing
        // and `components` is Livewire's full snapshot payload. PII masking
        // is handled by the shared Redactor, driven by
        // config('owlogs.redaction') (password & friends are masked there).
        $input = $request->except(['_token', '_method', 'components']);

        if ($input === []) {
            return;
        }

        $input = app(Redactor::class)->redact($input);

        $json = json_encode($input, JSON_UNESCAPED_UNICODE);
        if ($json !== false && mb_strlen($json) > 4096) {
            $json = mb_substr($json, 0, 4096);
        }

        ContextShim::addHidden('request_input', $json);
    }

    /**
     * Resolve the git SHA once and cache it for the process lifetime.
     * Safe under Octane because it only changes on deploy.
     */
    public static function resolveGitSha(): ?string
    {
        static $sha = null;
        static $resolved = false;

        if (! $resolved) {
            $resolved = true;
            $headFile = base_path('.git/HEAD');

            if (file_exists($headFile)) {
                $head = trim((string) file_get_contents($headFile));

                if (str_starts_with($head, 'ref: ')) {
                    $refFile = base_path('.git/'.substr($head, 5));
                    if (file_exists($refFile)) {
                        $sha = substr(trim((string) file_get_contents($refFile)), 0, 8);
                    }
                } else {
                    $sha = substr($head, 0, 8);
                }
            }
        }

        return $sha;
    }
}
