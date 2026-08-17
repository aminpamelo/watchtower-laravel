<?php

namespace Pantau\Watchtower\Jobs;

use Pantau\Watchtower\Support\CircuitBreaker;
use Pantau\Watchtower\Watchtower;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one batch to the Watchtower server and reacts to each response
 * code exactly as the contract in Section 4.1 requires.
 */
class SendEventBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public array $payload, public bool $alreadySplit = false)
    {
        $this->onQueue((string) config('watchtower.transport.queue', 'watchtower'));

        $connection = config('watchtower.transport.connection');

        if ($connection !== null) {
            $this->onConnection($connection);
        }
    }

    public function handle(CircuitBreaker $breaker): void
    {
        try {
            if ($breaker->isOpen() || Watchtower::isDisabled() || Cache::has('watchtower:sdk:disabled')) {
                return;
            }

            $endpoint = (string) config('watchtower.endpoint');
            $token = (string) config('watchtower.token');

            if ($endpoint === '' || $token === '') {
                return;
            }

            try {
                $response = Http::withToken($token)
                    ->withHeaders(['X-Watchtower-Sdk' => 'pantau/watchtower-laravel@'.Watchtower::VERSION])
                    ->timeout((int) config('watchtower.transport.timeout', 5))
                    ->acceptJson()
                    ->post($endpoint, $this->payload);
            } catch (Throwable) {
                $breaker->recordFailure();

                if ($this->attempts() < $this->tries) {
                    $this->release(30);
                }

                return;
            }

            $status = $response->status();

            if ($status === 202) {
                $breaker->recordSuccess();

                return;
            }

            if ($status === 400) {
                Log::debug('Watchtower: payload rejected by server', ['status' => 400]);

                return;
            }

            if ($status === 401) {
                // Invalid token: disable the SDK until restart (Section
                // 4.1). The cache flag also quiets other processes for a
                // while without surviving a config fix forever.
                Watchtower::disable();
                Cache::put('watchtower:sdk:disabled', 1, 600);
                Log::debug('Watchtower: token rejected, sending disabled');

                return;
            }

            if ($status === 413) {
                $this->splitAndRetry();

                return;
            }

            if ($status === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?: config('watchtower.circuit_breaker.cooldown_seconds', 60));
                $breaker->open($retryAfter);

                return;
            }

            if ($status >= 500) {
                $breaker->recordFailure();

                if ($this->attempts() < $this->tries) {
                    $this->release(30);
                }
            }
        } catch (Throwable) {
            // Transport failures are swallowed: monitoring must never take
            // the host application down (Section 3.1).
        }
    }

    private function splitAndRetry(): void
    {
        if ($this->alreadySplit) {
            return;
        }

        $events = $this->payload['events'] ?? [];

        if (count($events) <= 1) {
            return;
        }

        foreach (array_chunk($events, (int) ceil(count($events) / 2)) as $chunk) {
            self::dispatch(array_merge($this->payload, ['events' => $chunk]), true);
        }
    }
}
