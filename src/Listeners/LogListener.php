<?php

namespace Bedaie\Watchtower\Listeners;

use Bedaie\Watchtower\Recorder;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

/**
 * Log messages become breadcrumbs (info and above) and configured levels
 * become log events. Messages carrying an exception are skipped because
 * the exception reporter already captured them.
 */
class LogListener
{
    private const BREADCRUMB_LEVELS = ['info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function __construct(private Recorder $recorder) {}

    public function handle(MessageLogged $event): void
    {
        try {
            if (($event->context['exception'] ?? null) instanceof Throwable) {
                return;
            }

            if (in_array($event->level, self::BREADCRUMB_LEVELS, true)) {
                $this->recorder->addBreadcrumb('log', $event->message, ['level' => $event->level]);
            }

            $captureLevels = config('watchtower.capture.logs', []);

            if (in_array($event->level, $captureLevels, true)) {
                $this->recorder->recordLog($event->level, $event->message, $event->context);
            }
        } catch (Throwable) {
        }
    }
}
