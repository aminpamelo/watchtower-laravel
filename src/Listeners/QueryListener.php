<?php

namespace Pantau\Watchtower\Listeners;

use Pantau\Watchtower\Recorder;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * Records a breadcrumb for every query and a slow_query event above the
 * threshold. Only the prepared SQL is used; bindings are never touched
 * (Section 3.7 rule 3).
 */
class QueryListener
{
    public function __construct(private Recorder $recorder) {}

    public function handle(QueryExecuted $event): void
    {
        try {
            $this->recorder->addBreadcrumb('query', $event->sql, [
                'duration_ms' => (int) round($event->time),
                'connection' => $event->connectionName,
            ]);

            $threshold = (float) config('watchtower.capture.slow_query_threshold_ms', 500);

            if (config('watchtower.capture.slow_queries', true) && $event->time >= $threshold) {
                $this->recorder->recordSlowQuery(
                    $event->sql,
                    $event->time,
                    $event->connectionName,
                    $this->findCaller(),
                );
            }
        } catch (Throwable) {
        }
    }

    /**
     * First frame outside vendor code; only computed for slow queries to
     * keep the per query overhead negligible.
     */
    private function findCaller(): ?array
    {
        $basePath = rtrim(base_path(), '/').'/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || ! str_starts_with($file, $basePath)) {
                continue;
            }

            $relative = substr($file, strlen($basePath));

            if (str_starts_with($relative, 'vendor/')
                || str_starts_with($relative, 'bootstrap/')
                || str_contains($relative, 'watchtower-laravel/src/')) {
                continue;
            }

            return ['file' => $relative, 'line' => (int) ($frame['line'] ?? 0)];
        }

        return null;
    }
}
