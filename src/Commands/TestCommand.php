<?php

namespace Bedaie\Watchtower\Commands;

use Bedaie\Watchtower\Recorder;
use Bedaie\Watchtower\Watchtower;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'watchtower:test';

    protected $description = 'Send a test exception to the Watchtower server synchronously';

    public function handle(Recorder $recorder): int
    {
        $endpoint = (string) config('watchtower.endpoint');
        $token = (string) config('watchtower.token');

        if ($endpoint === '' || $token === '') {
            $this->error('WATCHTOWER_ENDPOINT atau WATCHTOWER_TOKEN belum ditetapkan.');

            return self::FAILURE;
        }

        $exception = new RuntimeException('Ujian penghantaran Watchtower pada '.now()->toDateTimeString());

        $recorder->startConsole('watchtower:test');
        $recorder->recordException($exception, handled: true);

        $events = $recorder->bufferedEvents();

        if ($events === []) {
            $this->error('Gagal membina event ujian. Semak WATCHTOWER_ENABLED.');

            return self::FAILURE;
        }

        $payload = $recorder->envelope($events);
        $recorder->reset();

        try {
            $response = Http::withToken($token)
                ->withHeaders(['X-Watchtower-Sdk' => 'bedaie/watchtower-laravel@'.Watchtower::VERSION])
                ->timeout((int) config('watchtower.transport.timeout', 5))
                ->acceptJson()
                ->post($endpoint, $payload);
        } catch (Throwable $error) {
            $this->error('Gagal menghubungi server: '.$error->getMessage());

            return self::FAILURE;
        }

        if ($response->status() === 202) {
            $this->info('Berjaya. Server menerima event ujian (202).');

            return self::SUCCESS;
        }

        $this->error('Server membalas dengan status '.$response->status().'.');

        return self::FAILURE;
    }
}
