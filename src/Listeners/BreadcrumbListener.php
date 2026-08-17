<?php

namespace Pantau\Watchtower\Listeners;

use Pantau\Watchtower\Recorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;

/**
 * Collects the small context events from the table in Section 3.6. These
 * are only ever sent alongside an exception, never on their own.
 */
class BreadcrumbListener
{
    public function __construct(private Recorder $recorder) {}

    public function handleCacheHit(CacheHit $event): void
    {
        $this->cacheBreadcrumb('hit', $event->key);

        try {
            if (! $this->isOwnCacheKey($event->key)) {
                $this->recorder->incrementCacheHits();
            }
        } catch (Throwable) {
        }
    }

    public function handleCacheMissed(CacheMissed $event): void
    {
        $this->cacheBreadcrumb('miss', $event->key);

        try {
            if (! $this->isOwnCacheKey($event->key)) {
                $this->recorder->incrementCacheMisses();
            }
        } catch (Throwable) {
        }
    }

    public function handleCacheKeyWritten(KeyWritten $event): void
    {
        try {
            if (! $this->isOwnCacheKey($event->key)) {
                $this->recorder->incrementCacheWrites();
            }
        } catch (Throwable) {
        }
    }

    public function handleMailSending(MessageSending $event): void
    {
        try {
            // Notification mails expose their notification class in the view
            // data; plain mailables do not, so fall back to the subject.
            // Recipient addresses are never recorded, only the count.
            $notification = $event->data['__laravel_notification'] ?? null;

            $label = is_object($notification)
                ? $notification::class
                : (string) ($event->message->getSubject() ?? 'mail');

            $this->recorder->addBreadcrumb('mail', $label !== '' ? $label : 'mail', [
                'recipients' => count($event->message->getTo() ?? []),
            ]);
        } catch (Throwable) {
        }
    }

    public function handleMailSent(MessageSent $event): void
    {
        try {
            // Prefer the originating notification class as the metric name.
            // The mail channel stores it as a class string in the view data;
            // plain mailables fall back to the subject line.
            $notification = $event->data['__laravel_notification'] ?? null;

            if (is_object($notification)) {
                $name = $notification::class;
            } elseif (is_string($notification) && $notification !== '') {
                $name = $notification;
            } else {
                $name = (string) ($event->message->getSubject() ?? '');
            }

            $this->recorder->recordMailSent(
                $name !== '' ? $name : 'mail',
                count($event->message->getTo() ?? []),
            );
        } catch (Throwable) {
        }
    }

    public function handleNotificationSending(NotificationSending $event): void
    {
        try {
            $this->recorder->addBreadcrumb('notification', $event->notification::class, [
                'channel' => $event->channel,
            ]);
        } catch (Throwable) {
        }
    }

    public function handleHttpResponse(ResponseReceived $event): void
    {
        try {
            $url = (string) $event->request->url();
            $host = parse_url($url, PHP_URL_HOST) ?: $url;

            // Skip our own transport calls to the Watchtower server.
            if ($this->isWatchtowerHost($host)) {
                return;
            }

            $this->recorder->addBreadcrumb('http', $event->request->method().' '.$host, [
                'status' => $event->response->status(),
            ]);

            $transferTime = $event->response->transferStats?->getTransferTime();

            $this->recorder->recordOutgoingRequest(
                $host,
                $event->request->method(),
                $event->response->status(),
                $transferTime !== null ? (int) round($transferTime * 1000) : null,
            );
        } catch (Throwable) {
        }
    }

    public function handleHttpConnectionFailed(ConnectionFailed $event): void
    {
        try {
            $url = (string) $event->request->url();
            $host = parse_url($url, PHP_URL_HOST) ?: $url;

            if ($this->isWatchtowerHost($host)) {
                return;
            }

            $this->recorder->addBreadcrumb('http', $event->request->method().' '.$host, [
                'status' => 'gagal',
            ]);

            // Connection failures never received a response, so the status
            // code is recorded as zero.
            $this->recorder->recordOutgoingRequest($host, $event->request->method(), 0, null);
        } catch (Throwable) {
        }
    }

    public function handleNotificationSent(NotificationSent $event): void
    {
        try {
            $this->recorder->recordNotificationSent(
                $event->notification::class,
                (string) $event->channel,
            );
        } catch (Throwable) {
        }
    }

    public function handleLogin(Login $event): void
    {
        try {
            $this->recorder->addBreadcrumb('auth', 'login', [
                'user_id' => $event->user->getAuthIdentifier(),
            ]);
        } catch (Throwable) {
        }
    }

    public function handleLogout(Logout $event): void
    {
        try {
            $this->recorder->addBreadcrumb('auth', 'logout', [
                'user_id' => $event->user?->getAuthIdentifier(),
            ]);
        } catch (Throwable) {
        }
    }

    public function handleAuthFailed(Failed $event): void
    {
        try {
            $this->recorder->addBreadcrumb('auth', 'gagal', [
                'user_id' => $event->user?->getAuthIdentifier(),
            ]);
        } catch (Throwable) {
        }
    }

    private function isWatchtowerHost(string $host): bool
    {
        $endpointHost = parse_url((string) config('watchtower.endpoint'), PHP_URL_HOST);

        return $endpointHost !== null && $host === $endpointHost;
    }

    /**
     * The SDK's own cache traffic would flood the trail and the counters.
     */
    private function isOwnCacheKey(string $key): bool
    {
        return str_starts_with($key, 'watchtower:');
    }

    private function cacheBreadcrumb(string $result, string $key): void
    {
        try {
            if ($this->isOwnCacheKey($key)) {
                return;
            }

            $this->recorder->addBreadcrumb('cache', $result, [
                'key' => mb_substr($key, 0, 80),
            ]);
        } catch (Throwable) {
        }
    }
}
