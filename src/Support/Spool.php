<?php

namespace Bedaie\Watchtower\Support;

use Illuminate\Support\Str;
use Throwable;

/**
 * Disk fallback used when the queue itself is unavailable (Section 3.5).
 * Batches are written as JSON files and replayed by watchtower:flush-spool.
 */
class Spool
{
    public const MAX_FILES = 500;

    public function __construct(private string $directory) {}

    public function write(array $payload): bool
    {
        try {
            if (! is_dir($this->directory) && ! @mkdir($this->directory, 0755, true) && ! is_dir($this->directory)) {
                return false;
            }

            if (count($this->files()) >= self::MAX_FILES) {
                return false;
            }

            $path = $this->directory.'/'.now()->format('YmdHis').'-'.Str::random(8).'.json';

            return @file_put_contents($path, json_encode($payload)) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string> Absolute paths, oldest first.
     */
    public function files(): array
    {
        try {
            $files = glob($this->directory.'/*.json');

            if ($files === false) {
                return [];
            }

            sort($files);

            return $files;
        } catch (Throwable) {
            return [];
        }
    }

    public function read(string $path): ?array
    {
        try {
            $contents = @file_get_contents($path);

            if ($contents === false) {
                return null;
            }

            $payload = json_decode($contents, true);

            return is_array($payload) ? $payload : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function delete(string $path): void
    {
        @unlink($path);
    }
}
