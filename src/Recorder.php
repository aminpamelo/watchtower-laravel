<?php

namespace Bedaie\Watchtower;

use Bedaie\Watchtower\Jobs\SendEventBatch;
use Bedaie\Watchtower\Support\CircuitBreaker;
use Bedaie\Watchtower\Support\ReleaseDetector;
use Bedaie\Watchtower\Support\Scrubber;
use Bedaie\Watchtower\Support\Spool;
use Bedaie\Watchtower\Support\StackTraceParser;
use ErrorException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * In memory event buffer that lives for the duration of one request, job,
 * or console command (Section 3.4). Every public method is wrapped in
 * try catch: the SDK must never crash or slow the host application.
 */
class Recorder
{
    private ?string $traceId = null;

    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var list<array<string, mixed>> */
    private array $breadcrumbs = [];

    private ?float $startedAt = null;

    private ?Request $request = null;

    private ?string $consoleCommand = null;

    private ?string $jobClass = null;

    private ?int $statusCode = null;

    private bool $ignoreCurrentContext = false;

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    private int $cacheWrites = 0;

    public function __construct(
        private Scrubber $scrubber,
        private StackTraceParser $stackTraceParser,
        private ReleaseDetector $releaseDetector,
        private CircuitBreaker $circuitBreaker,
        private Spool $spool,
    ) {}

    public function startRequest(Request $request): void
    {
        try {
            $this->reset();
            $this->request = $request;
            $this->startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
            $this->ignoreCurrentContext = $this->pathIsIgnored($request->path());
        } catch (Throwable) {
        }
    }

    public function startConsole(string $command): void
    {
        try {
            $this->reset();
            $this->consoleCommand = $command;
            $this->startedAt = microtime(true);
        } catch (Throwable) {
        }
    }

    public function startJob(string $jobClass): void
    {
        try {
            $this->reset();
            $this->jobClass = $jobClass;
            $this->startedAt = microtime(true);
        } catch (Throwable) {
        }
    }

    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    public function traceId(): string
    {
        return $this->traceId ??= Str::uuid7()->toString();
    }

    public function addBreadcrumb(string $type, string $message, array $data = []): void
    {
        try {
            if (! config('watchtower.breadcrumbs.enabled', true)) {
                return;
            }

            // Breadcrumbs travel with exception payloads, so they must be
            // scrubbed exactly like every other outbound value (Section 3.7).
            $this->breadcrumbs[] = [
                'at' => $this->timestamp(),
                'type' => $type,
                'message' => mb_substr($this->scrubber->scrubString($message), 0, 500),
                'data' => $this->scrubber->scrub($data),
            ];

            $max = (int) config('watchtower.breadcrumbs.max', 25);

            if (count($this->breadcrumbs) > $max) {
                $this->breadcrumbs = array_slice($this->breadcrumbs, -$max);
            }
        } catch (Throwable) {
        }
    }

    public function recordException(Throwable $exception, bool $handled = false): void
    {
        try {
            if (! config('watchtower.capture.exceptions', true)
                || $this->ignoreCurrentContext
                || $this->exceptionIsIgnored($exception)
                || ! $this->sampled('exceptions')) {
                return;
            }

            $event = [
                'id' => Str::uuid7()->toString(),
                'type' => 'exception',
                'occurred_at' => $this->timestamp(),
                'trace_id' => $this->traceId(),
                'duration_ms' => $this->elapsedMs(),
                'exception' => $this->exceptionPayload($exception, $handled),
                'stack' => $this->stackTraceParser->parse($exception),
                'context' => $this->buildContext(),
                'breadcrumbs' => $this->breadcrumbs,
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];

            $this->push($event);
        } catch (Throwable) {
        }
    }

    public function recordSlowQuery(string $sql, float $durationMs, string $connection, ?array $caller): void
    {
        try {
            if ($this->ignoreCurrentContext || ! $this->sampled('slow_queries')) {
                return;
            }

            $this->push([
                'id' => Str::uuid7()->toString(),
                'type' => 'slow_query',
                'occurred_at' => $this->timestamp(),
                'trace_id' => $this->traceId(),
                'duration_ms' => (int) round($durationMs),
                'query' => [
                    'sql' => mb_substr($sql, 0, 2000),
                    'connection' => $connection,
                    'caller' => $caller,
                ],
                'context' => $this->buildContext(includeInput: false),
            ]);
        } catch (Throwable) {
        }
    }

    public function recordFailedJob(array $job, Throwable $exception): void
    {
        try {
            if (! config('watchtower.capture.failed_jobs', true)) {
                return;
            }

            $this->push([
                'id' => Str::uuid7()->toString(),
                'type' => 'failed_job',
                'occurred_at' => $this->timestamp(),
                'trace_id' => $this->traceId(),
                'job' => $job,
                'exception' => $this->exceptionPayload($exception, false),
                'stack' => $this->stackTraceParser->parse($exception),
                'breadcrumbs' => $this->breadcrumbs,
            ]);
        } catch (Throwable) {
        }
    }

    public function recordFailedScheduledTask(array $task): void
    {
        try {
            if (! config('watchtower.capture.scheduled_tasks', true)) {
                return;
            }

            $this->push([
                'id' => Str::uuid7()->toString(),
                'type' => 'failed_scheduled_task',
                'occurred_at' => $this->timestamp(),
                'trace_id' => $this->traceId(),
                'task' => $task,
            ]);
        } catch (Throwable) {
        }
    }

    public function recordSlowRequest(int $durationMs): void
    {
        try {
            if (! config('watchtower.capture.slow_requests', true) || $this->ignoreCurrentContext) {
                return;
            }

            $this->push([
                'id' => Str::uuid7()->toString(),
                'type' => 'slow_request',
                'occurred_at' => $this->timestamp(),
                'trace_id' => $this->traceId(),
                'duration_ms' => $durationMs,
                'context' => $this->buildContext(includeInput: false),
            ]);
        } catch (Throwable) {
        }
    }

    public function recordLog(string $level, string $message, array $context = []): void
    {
        try {
            if ($this->ignoreCurrentContext) {
                return;
            }

            $this->push([
                'id' => Str::uuid7()->toString(),
                'type' => 'log',
                'occurred_at' => $this->timestamp(),
                'trace_id' => $this->traceId(),
                'log' => [
                    'level' => $level,
                    'message' => mb_substr($this->scrubber->scrubString($message), 0, 4000),
                    'context' => $this->scrubber->scrub($context),
                ],
                'context' => $this->buildContext(includeInput: false),
            ]);
        } catch (Throwable) {
        }
    }

    public function recordRequest(?int $durationMs): void
    {
        try {
            if (! config('watchtower.capture.requests', true)
                || $this->ignoreCurrentContext
                || ! $this->sampled('requests')) {
                return;
            }

            $request = $this->request ?? $this->resolveRequest();

            if ($request === null) {
                return;
            }

            $routeName = $request->route()?->getName();

            // Lightweight context only: no input, no headers. The server
            // groups metric events by the top level name field.
            $this->recordMetric('request', $routeName ?: $request->path(), $durationMs, [
                'context' => [
                    'type' => 'http',
                    'url' => mb_substr($request->fullUrl(), 0, 1000),
                    'method' => $request->method(),
                    'route_name' => $routeName,
                    'status_code' => $this->statusCode,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    public function recordCommand(string $command, int $exitCode): void
    {
        try {
            if (! config('watchtower.capture.commands', true)) {
                return;
            }

            $this->recordMetric('command', $command, $this->elapsedMs(), [
                'command' => [
                    'name' => $command,
                    'exit_code' => $exitCode,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    public function recordJobProcessed(string $jobClass, ?string $queue, string $status): void
    {
        try {
            if (! config('watchtower.capture.jobs', true)) {
                return;
            }

            $this->recordMetric('job_processed', $jobClass, $this->elapsedMs(), [
                'job' => [
                    'class' => $jobClass,
                    'queue' => $queue,
                    'status' => $status,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    public function recordOutgoingRequest(string $host, string $method, int $statusCode, ?int $durationMs): void
    {
        try {
            if (! config('watchtower.capture.outgoing_requests', true)) {
                return;
            }

            $this->recordMetric('outgoing_request', $host, $durationMs, [
                'http' => [
                    'host' => $host,
                    'method' => $method,
                    'status_code' => $statusCode,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    public function recordMailSent(string $mailable, int $recipients): void
    {
        try {
            if (! config('watchtower.capture.mail', true)) {
                return;
            }

            $this->recordMetric('mail_sent', $mailable, null, [
                'mail' => [
                    'mailable' => $mailable,
                    'recipients' => $recipients,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    public function recordNotificationSent(string $class, string $channel): void
    {
        try {
            if (! config('watchtower.capture.notifications', true)) {
                return;
            }

            $this->recordMetric('notification_sent', $class, null, [
                'notification' => [
                    'class' => $class,
                    'channel' => $channel,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    public function recordScheduledRun(string $command, ?int $exitCode, ?int $durationMs, string $status): void
    {
        try {
            if (! config('watchtower.capture.scheduled_runs', true)) {
                return;
            }

            $this->recordMetric('scheduled_run', $command, $durationMs, [
                'task' => [
                    'command' => $command,
                    'exit_code' => $exitCode,
                    'status' => $status,
                ],
            ]);
        } catch (Throwable) {
        }
    }

    public function incrementCacheHits(): void
    {
        $this->cacheHits++;
    }

    public function incrementCacheMisses(): void
    {
        $this->cacheMisses++;
    }

    public function incrementCacheWrites(): void
    {
        $this->cacheWrites++;
    }

    /**
     * Hand the buffer to the transport (Section 3.5 step 4). Never throws.
     */
    public function flush(): void
    {
        try {
            // Aggregated cache counters become a single event per flush so
            // the trail stays small even on cache heavy code paths.
            $this->pushCacheStats();

            if ($this->events === []) {
                $this->reset();

                return;
            }

            $events = $this->events;
            $this->reset();

            if ($this->circuitBreaker->isOpen() || $this->sdkDisabled()) {
                return;
            }

            $batchSize = max(1, (int) config('watchtower.transport.max_batch_size', 50));

            foreach (array_chunk($events, $batchSize) as $chunk) {
                $payload = $this->envelope($chunk);

                try {
                    SendEventBatch::dispatch($payload);
                } catch (Throwable) {
                    // Queue unavailable: fall back to the disk spool.
                    $this->spool->write($payload);
                }
            }
        } catch (Throwable) {
        }
    }

    public function envelope(array $events): array
    {
        return [
            'sdk' => ['name' => 'bedaie/watchtower-laravel', 'version' => Watchtower::VERSION],
            'environment' => (string) config('watchtower.environment'),
            'release' => $this->releaseDetector->detect(),
            'server' => [
                'hostname' => (string) gethostname(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ],
            'sent_at' => $this->timestamp(),
            'events' => $events,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function bufferedEvents(): array
    {
        return $this->events;
    }

    /** @return list<array<string, mixed>> */
    public function bufferedBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }

    public function reset(): void
    {
        $this->events = [];
        $this->breadcrumbs = [];
        $this->traceId = null;
        $this->startedAt = null;
        $this->request = null;
        $this->consoleCommand = null;
        $this->jobClass = null;
        $this->statusCode = null;
        $this->ignoreCurrentContext = false;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->cacheWrites = 0;
    }

    /**
     * Shared shape for the lightweight metric events. The server groups
     * these by the top level name field, so it is always present.
     */
    private function recordMetric(string $type, ?string $name, ?int $durationMs, array $sections): void
    {
        $this->push([
            'id' => Str::uuid7()->toString(),
            'type' => $type,
            'occurred_at' => $this->timestamp(),
            'trace_id' => $this->traceId(),
            'name' => $name,
            'duration_ms' => $durationMs,
            ...$sections,
        ]);
    }

    private function pushCacheStats(): void
    {
        if (! config('watchtower.capture.cache_stats', true)) {
            return;
        }

        if ($this->cacheHits === 0 && $this->cacheMisses === 0 && $this->cacheWrites === 0) {
            return;
        }

        $this->recordMetric('cache_stats', null, null, [
            'cache' => [
                'hits' => $this->cacheHits,
                'misses' => $this->cacheMisses,
                'writes' => $this->cacheWrites,
            ],
        ]);

        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->cacheWrites = 0;
    }

    private function push(array $event): void
    {
        $this->events[] = $event;

        // Hard cap so long running commands cannot exhaust memory
        // (Section 14 item 5). Oldest events are dropped first.
        $max = (int) config('watchtower.transport.max_buffer_events', 100);

        if (count($this->events) > $max) {
            $this->events = array_slice($this->events, -$max);
        }
    }

    private function exceptionPayload(Throwable $exception, bool $handled): array
    {
        $payload = [
            'class' => $exception::class,
            'message' => mb_substr($this->scrubber->scrubString($exception->getMessage()), 0, 4000),
            'code' => (string) $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'handled' => $handled,
            'previous' => [],
        ];

        if ($exception instanceof ErrorException) {
            $payload['severity'] = $exception->getSeverity();
        }

        $previous = $exception->getPrevious();
        $depth = 0;

        while ($previous !== null && $depth < 3) {
            $payload['previous'][] = [
                'class' => $previous::class,
                'message' => mb_substr($this->scrubber->scrubString($previous->getMessage()), 0, 1000),
                'file' => $previous->getFile(),
                'line' => $previous->getLine(),
            ];

            $previous = $previous->getPrevious();
            $depth++;
        }

        return $payload;
    }

    private function buildContext(bool $includeInput = true): array
    {
        if ($this->consoleCommand !== null) {
            return ['type' => 'console', 'command' => $this->consoleCommand];
        }

        if ($this->jobClass !== null) {
            return ['type' => 'job', 'job_class' => $this->jobClass];
        }

        $request = $this->request ?? $this->resolveRequest();

        if ($request === null) {
            return ['type' => 'other'];
        }

        $context = [
            'type' => 'http',
            'url' => mb_substr($request->fullUrl(), 0, 1000),
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'controller_action' => $request->route()?->getActionName(),
            'status_code' => $this->statusCode,
            'ip_hash' => $this->hashIp($request->ip()),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'user' => $this->currentUser(),
        ];

        if ($includeInput) {
            $context['input'] = $this->scrubber->scrub($this->truncateValues($request->input()));
            $context['headers'] = $this->scrubber->scrubHeaders($this->flattenHeaders($request));
        }

        return $context;
    }

    private function resolveRequest(): ?Request
    {
        try {
            if (app()->runningInConsole()) {
                return null;
            }

            return app('request');
        } catch (Throwable) {
            return null;
        }
    }

    private function currentUser(): ?array
    {
        try {
            $user = Auth::user();

            if ($user === null) {
                return null;
            }

            $identifier = $user->email ?? null;

            return [
                'id' => $user->getAuthIdentifier(),
                'identifier' => $identifier !== null ? mb_substr((string) $identifier, 0, 255) : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash('sha256', $ip.(string) config('app.key'));
    }

    private function flattenHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return $headers;
    }

    private function truncateValues(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $result = [];
        $count = 0;

        foreach ($input as $key => $value) {
            if ($count >= 100) {
                break;
            }

            if (is_array($value)) {
                $result[$key] = $this->truncateValues($value);
            } elseif (is_string($value)) {
                $result[$key] = mb_substr($value, 0, 1000);
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } else {
                $result[$key] = '[objek]';
            }

            $count++;
        }

        return $result;
    }

    private function exceptionIsIgnored(Throwable $exception): bool
    {
        foreach (config('watchtower.ignore_exceptions', []) as $ignoredClass) {
            if ($exception instanceof $ignoredClass) {
                return true;
            }
        }

        return false;
    }

    private function pathIsIgnored(string $path): bool
    {
        foreach (config('watchtower.ignore_paths', []) as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function sampled(string $type): bool
    {
        $rate = (float) config('watchtower.sampling.'.$type, 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $rate;
    }

    private function sdkDisabled(): bool
    {
        try {
            return Watchtower::isDisabled() || Cache::has('watchtower:sdk:disabled');
        } catch (Throwable) {
            return false;
        }
    }

    private function elapsedMs(): ?int
    {
        if ($this->startedAt === null) {
            return null;
        }

        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }

    private function timestamp(): string
    {
        return now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}
