<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Skeylup\OwlogsAgent\Contracts\HasLogContext;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Symfony\Component\HttpFoundation\Response;

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

        // Tracing IDs
        if ($fields['trace_id'] ?? true) {
            Context::add('trace_id', (string) Str::ulid());
        }

        if ($fields['span_id'] ?? true) {
            Context::add('span_id', Context::get('trace_id') ?? (string) Str::ulid());
        }

        if ($fields['origin'] ?? true) {
            Context::add('origin', 'http');
        }

        // App info
        if ($fields['app_name'] ?? true) {
            Context::add('app_name', (string) config('app.name'));
        }

        if ($fields['app_env'] ?? true) {
            Context::add('app_env', (string) config('app.env'));
        }

        if ($fields['app_url'] ?? true) {
            Context::add('app_url', (string) config('app.url'));
        }

        // Request info
        if ($fields['uri'] ?? true) {
            Context::add('uri', $request->method().' '.$request->fullUrl());
        }

        if ($fields['ip'] ?? true) {
            Context::add('ip', $request->ip());
        }

        if ($fields['user_agent'] ?? true) {
            Context::add('user_agent', Str::limit((string) $request->userAgent(), 200, ''));
        }

        // Attempt early resolution — may be null if auth hasn't run yet.
        if ($fields['user_id'] ?? true) {
            $userId = $request->user()?->getKey();
            if ($userId !== null) {
                Context::add('user_id', $userId);
            }
        }

        if ($fields['git_sha'] ?? true) {
            $gitSha = self::resolveGitSha();
            if ($gitSha !== null) {
                Context::add('git_sha', $gitSha);
            }
        }

        // Route info
        if ($fields['route_name'] ?? true) {
            $routeName = $request->route()?->getName();
            if ($routeName !== null) {
                Context::add('route_name', $routeName);
            }
        }

        if ($fields['route_action'] ?? true) {
            $action = $request->route()?->getActionName();
            if ($action !== null && $action !== 'Closure') {
                Context::add('route_action', $action);
            }
        }

        // Custom URI resolver
        $this->applyUriResolver($request, $fields);

        // Capture request input for POST/PUT/PATCH (sanitized)
        if ($fields['request_input'] ?? true) {
            $this->captureRequestInput($request);
        }

        $response = $next($request);

        // Route info — re-resolve after $next()
        if (($fields['route_name'] ?? true) && ! Context::has('route_name')) {
            $routeName = $request->route()?->getName();
            if ($routeName !== null) {
                Context::add('route_name', $routeName);
            }
        }

        if (($fields['route_action'] ?? true) && ! Context::has('route_action')) {
            $action = $request->route()?->getActionName();
            if ($action !== null && $action !== 'Closure') {
                Context::add('route_action', $action);
            }
        }

        // User & tenant — resolved after $next() so auth middleware has run
        $user = $request->user();

        if ($fields['user_id'] ?? true) {
            $userId = $user?->getKey();
            if ($userId !== null) {
                Context::add('user_id', $userId);
            }
        }

        if ($user instanceof HasLogContext) {
            Context::add('user_context', $user->toLogContext());
            Context::add('user_label', $user->getLogContextLabel());
        }

        // Duration
        if ($fields['duration_ms'] ?? true) {
            $durationMs = (int) round((hrtime(true) - $startTime) / 1_000_000);
            Context::add('duration_ms', $durationMs);

            Context::push('measures', [
                'label' => 'request',
                'duration_ms' => (float) $durationMs,
                'meta' => [],
            ]);
        }

        // Response header for traceability
        if ($fields['trace_id'] ?? true) {
            $response->headers->set('X-Trace-Id', Context::get('trace_id'));
        }

        return $response;
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

        $input = $request->except([
            'password', 'password_confirmation', 'current_password',
            '_token', '_method',
            'components',
        ]);

        if ($input === []) {
            return;
        }

        $sensitivePatterns = ['secret', 'token', 'key', 'authorization', 'cookie', 'credit_card'];
        array_walk_recursive($input, function (&$value, $key) use ($sensitivePatterns) {
            foreach ($sensitivePatterns as $pattern) {
                if (is_string($key) && Str::contains(strtolower($key), $pattern)) {
                    $value = '********';
                    break;
                }
            }
        });

        $json = json_encode($input, JSON_UNESCAPED_UNICODE);
        if ($json !== false && mb_strlen($json) > 4096) {
            $json = mb_substr($json, 0, 4096);
        }

        Context::add('request_input', $json);
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
