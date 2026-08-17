<?php

namespace Bedaie\Watchtower\Commands;

use Bedaie\Watchtower\Support\CircuitBreaker;
use Bedaie\Watchtower\Support\Spool;
use Bedaie\Watchtower\Watchtower;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FlushSpoolCommand extends Command
{
    protected $signature = 'watchtower:flush-spool';

    protected $description = 'Resend batches that were spooled to disk while the queue was down';

    public function handle(Spool $spool, CircuitBreaker $breaker): int
    {
        $files = $spool->files();

        if ($files === []) {
            $this->info('Spool kosong.');

            return self::SUCCESS;
        }

        $endpoint = (string) config('watchtower.endpoint');
        $token = (string) config('watchtower.token');

        if ($endpoint === '' || $token === '') {
            $this->error('WATCHTOWER_ENDPOINT atau WATCHTOWER_TOKEN belum ditetapkan.');

            return self::FAILURE;
        }

        $sent = 0;

        foreach ($files as $file) {
            if ($breaker->isOpen()) {
                $this->warn('Circuit breaker terbuka, berhenti dahulu.');
                break;
            }

            $payload = $spool->read($file);

            if ($payload === null) {
                $spool->delete($file);

                continue;
            }

            try {
                $response = Http::withToken($token)
                    ->withHeaders(['X-Watchtower-Sdk' => 'bedaie/watchtower-laravel@'.Watchtower::VERSION])
                    ->timeout((int) config('watchtower.transport.timeout', 5))
                    ->acceptJson()
                    ->post($endpoint, $payload);
            } catch (Throwable) {
                $breaker->recordFailure();
                break;
            }

            $status = $response->status();

            if ($status === 202) {
                $breaker->recordSuccess();
                $spool->delete($file);
                $sent++;

                continue;
            }

            if (in_array($status, [400, 413], true)) {
                // Permanently unsendable, drop it.
                $spool->delete($file);

                continue;
            }

            if ($status === 401) {
                // Section 4.1: discard the buffer and disable the SDK.
                Watchtower::disable();
                Cache::put('watchtower:sdk:disabled', 1, 600);
                $spool->delete($file);
                $this->error('Token ditolak oleh server (401). Penghantaran dihentikan.');
                break;
            }

            if ($status === 429) {
                $retryAfter = (int) ($response->header('Retry-After')
                    ?: config('watchtower.circuit_breaker.cooldown_seconds', 60));
                $breaker->open($retryAfter);
                $this->warn("Server meminta berhenti selama {$retryAfter} saat (429).");
                break;
            }

            $breaker->recordFailure();
            break;
        }

        $this->info("Selesai. {$sent} batch dihantar semula.");

        return self::SUCCESS;
    }
}
