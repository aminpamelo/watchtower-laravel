<?php

namespace Bedaie\Watchtower\Listeners;

use Bedaie\Watchtower\Recorder;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Throwable;

/**
 * Queue lifecycle hooks: each job gets its own recorder scope, a flush
 * happens after every job, and permanent failures become failed_job events.
 */
class JobListener
{
    public function __construct(private Recorder $recorder) {}

    public function handleJobQueued(JobQueued $event): void
    {
        try {
            $jobName = is_object($event->job) ? $event->job::class : (string) $event->job;

            if (str_starts_with($jobName, 'Bedaie\\Watchtower\\')) {
                return;
            }

            $this->recorder->addBreadcrumb('job', $jobName, [
                'queue' => $event->queue ?? null,
            ]);
        } catch (Throwable) {
        }
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        try {
            if ($this->isOwnJob($event->job->resolveName())) {
                return;
            }

            $this->recorder->startJob($event->job->resolveName());
        } catch (Throwable) {
        }
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        try {
            if ($this->isOwnJob($event->job->resolveName())) {
                return;
            }

            $this->recorder->recordJobProcessed(
                $event->job->resolveName(),
                $event->job->getQueue(),
                'processed',
            );

            $this->recorder->flush();
        } catch (Throwable) {
        }
    }

    public function handleJobFailed(JobFailed $event): void
    {
        try {
            if ($this->isOwnJob($event->job->resolveName())) {
                return;
            }

            $this->recorder->recordFailedJob([
                'class' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempts' => $event->job->attempts(),
                'uuid' => $event->job->uuid(),
            ], $event->exception);

            $this->recorder->recordJobProcessed(
                $event->job->resolveName(),
                $event->job->getQueue(),
                'failed',
            );

            $this->recorder->flush();
        } catch (Throwable) {
        }
    }

    /**
     * The SDK must never monitor its own transport jobs, otherwise a dead
     * server would generate an endless feedback loop of failed_job events.
     */
    private function isOwnJob(string $jobClass): bool
    {
        return str_starts_with($jobClass, 'Bedaie\\Watchtower\\');
    }
}
