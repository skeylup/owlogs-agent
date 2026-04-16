<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Queue job that ships a buffered batch of log rows to the Owlogs server.
 *
 * Retries on 5xx / network failures, abandons on 4xx (client errors, bad key).
 */
class SendLogsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    private const DEFAULT_INGEST_URL = 'https://www.owlogs.com/api/owlogs/ingest';

    /** @var list<int> backoff in seconds between retries */
    public array $backoff = [2, 5, 15, 60, 180];

    /**
     * @param  list<array<string, mixed>>  $logs
     */
    public function __construct(public array $logs)
    {
        $this->onQueue((string) config('owlogs.transport.queue', 'default'));

        $connection = config('owlogs.transport.connection');
        if ($connection !== null) {
            $this->onConnection((string) $connection);
        }
    }

    public function handle(): void
    {
        $apiKey = (string) config('owlogs.api_key');

        if ($apiKey === '') {
            // No API key configured — abandon quietly (useful for local dev).
            return;
        }

        $timeout = (int) config('owlogs.transport.timeout_s', 30);
        $compression = (bool) config('owlogs.transport.compression', true);
        $url = (string) (config('owlogs.transport.ingest_url') ?: self::DEFAULT_INGEST_URL);

        $headers = [
            'X-Api-Key' => $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'owlogs-agent/1.0',
        ];

        $body = json_encode(['logs' => $this->logs], JSON_UNESCAPED_UNICODE);

        if ($compression && $body !== false) {
            $body = gzencode($body, 6);
            $headers['Content-Encoding'] = 'gzip';
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->withBody($body, 'application/json')
                ->post($url);

            if ($response->successful()) {
                return;
            }

            $status = $response->status();

            // 4xx client errors — do not retry
            if ($status >= 400 && $status < 500) {
                $this->fail(new \RuntimeException("Owlogs ingestion rejected with status {$status}: ".$response->body()));

                return;
            }

            // 5xx / other — retry
            throw new \RuntimeException("Owlogs ingestion failed with status {$status}");
        } catch (Throwable $e) {
            // Let the queue layer retry (until $tries exhausted)
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        // Last-resort: log locally so ops can see something failed.
        // We use the default log channel (file) to avoid infinite loop.
        try {
            logger()->channel(config('logging.default'))->warning(
                'Owlogs SendLogsJob failed permanently',
                [
                    'error' => $exception->getMessage(),
                    'logs_count' => count($this->logs),
                ]
            );
        } catch (Throwable) {
            // swallow
        }
    }
}
