<?php

namespace Bedaie\Watchtower\Middleware;

use Bedaie\Watchtower\Recorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registers the recorder for the request and flushes after the response
 * has been sent (Section 3.5). Nothing here may slow down or break the
 * request itself.
 */
class WatchtowerMiddleware
{
    public function __construct(private Recorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->recorder->startRequest($request);
        } catch (Throwable) {
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->recorder->setStatusCode($response->getStatusCode());

            if ($response instanceof RedirectResponse) {
                $this->recorder->addBreadcrumb('redirect', (string) $response->getTargetUrl());
            }

            $durationMs = $this->requestDurationMs($request);
            $threshold = (int) config('watchtower.capture.slow_request_threshold_ms', 2000);

            if ($durationMs !== null
                && $durationMs >= $threshold
                && config('watchtower.capture.slow_requests', true)) {
                $this->recorder->recordSlowRequest($durationMs);
            }

            // Every request also produces a lightweight metric event; the
            // recorder applies the capture flag, ignore list, and sampling.
            $this->recorder->recordRequest($durationMs);

            $this->recorder->flush();
        } catch (Throwable) {
        }
    }

    private function requestDurationMs(Request $request): ?int
    {
        $start = defined('LARAVEL_START')
            ? LARAVEL_START
            : $request->server('REQUEST_TIME_FLOAT');

        if (! is_float($start) && ! is_numeric($start)) {
            return null;
        }

        return (int) round((microtime(true) - (float) $start) * 1000);
    }
}
