<?php

namespace Bedaie\Watchtower\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Stops outbound sending when the Watchtower server is unreachable
 * (Section 3.5). After the cooldown the next flush is allowed through as a
 * half open probe; success closes the circuit again.
 */
class CircuitBreaker
{
    private const OPEN_KEY = 'watchtower:circuit:open';

    private const FAILURES_KEY = 'watchtower:circuit:failures';

    public function isOpen(): bool
    {
        try {
            return Cache::has(self::OPEN_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    public function recordFailure(): void
    {
        try {
            $failures = (int) Cache::increment(self::FAILURES_KEY);

            if ($failures === 1) {
                // Give the counter a window so isolated failures age out.
                Cache::put(self::FAILURES_KEY, 1, 300);
            }

            if ($failures >= $this->failureThreshold()) {
                $this->open($this->cooldownSeconds());
            }
        } catch (Throwable) {
            // The breaker must never break the application.
        }
    }

    public function recordSuccess(): void
    {
        try {
            Cache::forget(self::FAILURES_KEY);
            Cache::forget(self::OPEN_KEY);
        } catch (Throwable) {
        }
    }

    public function open(int $seconds): void
    {
        try {
            Cache::put(self::OPEN_KEY, 1, max(1, $seconds));
            Cache::forget(self::FAILURES_KEY);
        } catch (Throwable) {
        }
    }

    private function failureThreshold(): int
    {
        return (int) config('watchtower.circuit_breaker.failure_threshold', 3);
    }

    private function cooldownSeconds(): int
    {
        return (int) config('watchtower.circuit_breaker.cooldown_seconds', 60);
    }
}
