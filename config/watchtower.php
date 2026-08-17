<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [
    'enabled' => env('WATCHTOWER_ENABLED', true),
    'endpoint' => env('WATCHTOWER_ENDPOINT'),
    'token' => env('WATCHTOWER_TOKEN'),
    'environment' => env('WATCHTOWER_ENVIRONMENT', env('APP_ENV')),

    'release' => [
        // How the current code version is detected, tried in order.
        'env' => env('WATCHTOWER_RELEASE'),
        'git_head' => true,
        'forge_deployment' => true,
    ],

    'capture' => [
        'exceptions' => true,
        'failed_jobs' => true,
        'scheduled_tasks' => true,
        'slow_queries' => true,
        'slow_query_threshold_ms' => 500,
        'slow_requests' => true,
        'slow_request_threshold_ms' => 2000,
        'logs' => ['error', 'critical', 'alert', 'emergency'],

        // Nightwatch style metric signals.
        'requests' => true,
        'commands' => true,
        'jobs' => true,
        'outgoing_requests' => true,
        'mail' => true,
        'notifications' => true,
        'cache_stats' => true,
        'scheduled_runs' => true,
    ],

    'ignore_exceptions' => [
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        ValidationException::class,
        NotFoundHttpException::class,
        TokenMismatchException::class,
    ],

    'ignore_paths' => [
        'horizon*',
        'telescope*',
        '_debugbar*',
        'up',
    ],

    'scrub' => [
        'keys' => [
            'password', 'password_confirmation', 'current_password',
            'token', 'api_token', '_token', 'access_token', 'refresh_token',
            'secret', 'authorization', 'cookie', 'csrf',
            'credit_card', 'card_number', 'cvv', 'cvc',
            'ic', 'nric', 'no_kp', 'mykad',
            'phone', 'telefon', 'no_telefon',
            'bank_account', 'no_akaun',
        ],
        'patterns' => [
            // Malaysian identity card numbers.
            '/\b\d{6}-\d{2}-\d{4}\b/',
            // Credit card numbers.
            '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/',
        ],
        'scrub_emails' => false,
    ],

    'breadcrumbs' => [
        'enabled' => true,
        'max' => 25,
    ],

    'sampling' => [
        'exceptions' => 1.0,
        'slow_queries' => 1.0,
        'requests' => 1.0,
    ],

    'transport' => [
        'queue' => env('WATCHTOWER_QUEUE', 'watchtower'),
        'connection' => env('WATCHTOWER_QUEUE_CONNECTION', null),
        'timeout' => 5,
        'max_batch_size' => 50,
        'max_payload_bytes' => 524288,
        'max_buffer_events' => 100,
    ],

    'circuit_breaker' => [
        'failure_threshold' => 3,
        'cooldown_seconds' => 60,
    ],
];
