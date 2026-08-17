<?php

namespace Bedaie\Watchtower;

use Bedaie\Watchtower\Commands\FlushSpoolCommand;
use Bedaie\Watchtower\Commands\InstallCommand;
use Bedaie\Watchtower\Commands\TestCommand;
use Bedaie\Watchtower\Listeners\BreadcrumbListener;
use Bedaie\Watchtower\Listeners\JobListener;
use Bedaie\Watchtower\Listeners\LogListener;
use Bedaie\Watchtower\Listeners\QueryListener;
use Bedaie\Watchtower\Listeners\ScheduledTaskListener;
use Bedaie\Watchtower\Middleware\WatchtowerMiddleware;
use Bedaie\Watchtower\Support\CircuitBreaker;
use Bedaie\Watchtower\Support\ReleaseDetector;
use Bedaie\Watchtower\Support\Scrubber;
use Bedaie\Watchtower\Support\Spool;
use Bedaie\Watchtower\Support\StackTraceParser;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class WatchtowerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watchtower.php', 'watchtower');

        $this->app->singleton(Scrubber::class);
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(StackTraceParser::class, fn () => new StackTraceParser(base_path()));
        $this->app->singleton(ReleaseDetector::class, fn () => new ReleaseDetector(base_path()));
        $this->app->singleton(Spool::class, fn () => new Spool(storage_path('app/watchtower-spool')));
        $this->app->singleton(Recorder::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/watchtower.php' => config_path('watchtower.php'),
            ], 'watchtower-config');

            $this->commands([
                InstallCommand::class,
                TestCommand::class,
                FlushSpoolCommand::class,
            ]);
        }

        if (! $this->shouldCapture()) {
            return;
        }

        $this->registerExceptionReporting();
        $this->registerEventListeners();
        $this->registerMiddleware();
        $this->registerSpoolSchedule();
    }

    private function shouldCapture(): bool
    {
        return (bool) config('watchtower.enabled')
            && filled(config('watchtower.endpoint'))
            && filled(config('watchtower.token'));
    }

    private function registerExceptionReporting(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $exception): void {
                try {
                    $this->app->make(Recorder::class)->recordException($exception);
                } catch (Throwable) {
                }
            });
        });
    }

    private function registerEventListeners(): void
    {
        Event::listen(QueryExecuted::class, [QueryListener::class, 'handle']);

        Event::listen(JobQueued::class, [JobListener::class, 'handleJobQueued']);
        Event::listen(JobProcessing::class, [JobListener::class, 'handleJobProcessing']);
        Event::listen(JobProcessed::class, [JobListener::class, 'handleJobProcessed']);
        Event::listen(JobFailed::class, [JobListener::class, 'handleJobFailed']);

        Event::listen(ScheduledTaskFailed::class, [ScheduledTaskListener::class, 'handleFailed']);
        Event::listen(ScheduledTaskFinished::class, [ScheduledTaskListener::class, 'handleFinished']);

        Event::listen(MessageLogged::class, [LogListener::class, 'handle']);

        Event::listen(CacheHit::class, [BreadcrumbListener::class, 'handleCacheHit']);
        Event::listen(CacheMissed::class, [BreadcrumbListener::class, 'handleCacheMissed']);
        Event::listen(KeyWritten::class, [BreadcrumbListener::class, 'handleCacheKeyWritten']);
        Event::listen(MessageSending::class, [BreadcrumbListener::class, 'handleMailSending']);
        Event::listen(MessageSent::class, [BreadcrumbListener::class, 'handleMailSent']);
        Event::listen(NotificationSending::class, [BreadcrumbListener::class, 'handleNotificationSending']);
        Event::listen(NotificationSent::class, [BreadcrumbListener::class, 'handleNotificationSent']);
        Event::listen(ResponseReceived::class, [BreadcrumbListener::class, 'handleHttpResponse']);
        Event::listen(ConnectionFailed::class, [BreadcrumbListener::class, 'handleHttpConnectionFailed']);
        Event::listen(Login::class, [BreadcrumbListener::class, 'handleLogin']);
        Event::listen(Logout::class, [BreadcrumbListener::class, 'handleLogout']);
        Event::listen(Failed::class, [BreadcrumbListener::class, 'handleAuthFailed']);

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            try {
                $this->app->make(Recorder::class)->startConsole((string) $event->command);
            } catch (Throwable) {
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            try {
                $recorder = $this->app->make(Recorder::class);
                $command = (string) $event->command;

                // The command metric must land in the buffer before the
                // flush below hands it to the transport. The SDK's own
                // commands are never measured.
                if ($command !== '' && ! str_starts_with($command, 'watchtower')) {
                    $recorder->recordCommand($command, $event->exitCode);
                }

                $recorder->flush();
            } catch (Throwable) {
            }
        });
    }

    private function registerMiddleware(): void
    {
        try {
            if (! $this->app->runningInConsole() && $this->app->bound(HttpKernel::class)) {
                $kernel = $this->app->make(HttpKernel::class);

                if (method_exists($kernel, 'pushMiddleware')) {
                    $kernel->pushMiddleware(WatchtowerMiddleware::class);
                }
            }
        } catch (Throwable) {
        }
    }

    private function registerSpoolSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('watchtower:flush-spool')->everyFiveMinutes();
        });
    }
}
