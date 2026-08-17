<?php

namespace Pantau\Watchtower\Listeners;

use Pantau\Watchtower\Recorder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Throwable;

class ScheduledTaskListener
{
    public function __construct(private Recorder $recorder) {}

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        try {
            $command = $this->commandName($event->task);

            $this->recorder->recordFailedScheduledTask([
                'command' => $command,
                'expression' => $event->task->expression,
                'exit_code' => 1,
                'duration_ms' => null,
                'output_tail' => mb_substr($event->exception->getMessage(), 0, 2000),
            ]);

            $this->recorder->recordScheduledRun($command, 1, null, 'failed');

            // Scheduled tasks run inside schedule:run, so flush right away
            // instead of waiting for the command to finish.
            $this->recorder->flush();
        } catch (Throwable) {
        }
    }

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        try {
            $exitCode = $event->task->exitCode;
            $command = $this->commandName($event->task);
            $durationMs = (int) round($event->runtime * 1000);
            $failed = $exitCode !== null && $exitCode !== 0;

            // Every run produces a metric event, successful or not.
            $this->recorder->recordScheduledRun(
                $command,
                $exitCode,
                $durationMs,
                $failed ? 'failed' : 'success',
            );

            if ($failed) {
                $this->recorder->recordFailedScheduledTask([
                    'command' => $command,
                    'expression' => $event->task->expression,
                    'exit_code' => $exitCode,
                    'duration_ms' => $durationMs,
                    'output_tail' => $this->outputTail($event->task),
                ]);
            }

            $this->recorder->flush();
        } catch (Throwable) {
        }
    }

    private function commandName(object $task): string
    {
        $command = (string) ($task->command ?? '');

        if ($command === '' && method_exists($task, 'getSummaryForDisplay')) {
            $command = (string) $task->getSummaryForDisplay();
        }

        return mb_substr($command, 0, 500);
    }

    private function outputTail(object $task): ?string
    {
        try {
            $outputPath = $task->output ?? null;

            if (! is_string($outputPath)
                || $outputPath === ''
                || str_contains($outputPath, 'null')
                || ! is_readable($outputPath)) {
                return null;
            }

            $contents = (string) @file_get_contents($outputPath);

            return $contents === '' ? null : mb_substr($contents, -2000);
        } catch (Throwable) {
            return null;
        }
    }
}
