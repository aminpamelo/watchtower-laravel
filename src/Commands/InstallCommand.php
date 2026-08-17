<?php

namespace Pantau\Watchtower\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'watchtower:install';

    protected $description = 'Publish the Watchtower config and add env keys';

    private const ENV_BLOCK = <<<'ENV'

WATCHTOWER_ENABLED=true
WATCHTOWER_ENDPOINT=
WATCHTOWER_TOKEN=
WATCHTOWER_ENVIRONMENT="${APP_ENV}"
WATCHTOWER_QUEUE=watchtower
ENV;

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'watchtower-config']);

        foreach (['.env', '.env.example'] as $file) {
            $path = base_path($file);

            if (! file_exists($path)) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            if (str_contains($contents, 'WATCHTOWER_ENDPOINT')) {
                $this->line("Kunci Watchtower sudah wujud dalam {$file}.");

                continue;
            }

            file_put_contents($path, rtrim($contents)."\n".self::ENV_BLOCK."\n");
            $this->info("Kunci Watchtower ditambah ke {$file}.");
        }

        $this->newLine();
        $this->info('Pemasangan selesai. Langkah seterusnya:');
        $this->line('  1. Isi WATCHTOWER_ENDPOINT dan WATCHTOWER_TOKEN dalam .env');
        $this->line('  2. Pastikan worker queue "watchtower" berjalan');
        $this->line('  3. Uji dengan: php artisan watchtower:test');

        return self::SUCCESS;
    }
}
