<?php

namespace Bedaie\Watchtower\Support;

use Throwable;

/**
 * Converts a throwable into the frame structure from Section 4.3: relative
 * paths, an is_application flag, and code snippets for the first
 * application frames only (Section 14 item 7).
 */
class StackTraceParser
{
    private const MAX_FRAMES = 50;

    private const MAX_SNIPPET_FRAMES = 5;

    private const SNIPPET_CONTEXT_LINES = 5;

    /** Path prefixes that are never application code (Section 14 item 8). */
    private const NON_APPLICATION_PREFIXES = ['vendor/', 'bootstrap/cache/', 'storage/'];

    public function __construct(private string $basePath) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(Throwable $exception): array
    {
        $frames = [];

        // The crash site itself is the first frame; getTrace() starts one
        // level above it.
        $frames[] = $this->buildFrame(
            $exception->getFile(),
            $exception->getLine(),
            null,
            null,
        );

        foreach ($exception->getTrace() as $trace) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }

            if (! isset($trace['file'])) {
                continue;
            }

            $frames[] = $this->buildFrame(
                $trace['file'],
                (int) ($trace['line'] ?? 0),
                $trace['function'] ?? null,
                $trace['class'] ?? null,
            );
        }

        return $this->attachSnippets($frames);
    }

    private function buildFrame(string $file, int $line, ?string $function, ?string $class): array
    {
        $relative = $this->relativePath($file);

        return [
            'file' => $relative,
            'line' => $line,
            'function' => $function,
            'class' => $class,
            'is_application' => $this->isApplicationPath($relative),
            'code_snippet' => null,
            'absolute_file' => $file,
        ];
    }

    /**
     * Attach snippets to the first application frames, then drop the
     * internal absolute path key so it never leaves the process.
     */
    private function attachSnippets(array $frames): array
    {
        $snippets = 0;

        foreach ($frames as $index => $frame) {
            if ($frame['is_application'] && $snippets < self::MAX_SNIPPET_FRAMES) {
                $frames[$index]['code_snippet'] = $this->readSnippet($frame['absolute_file'], $frame['line']);
                $snippets++;
            }

            unset($frames[$index]['absolute_file']);
        }

        return array_values($frames);
    }

    private function readSnippet(string $file, int $line): ?array
    {
        try {
            if ($line < 1 || ! is_file($file) || ! is_readable($file)) {
                return null;
            }

            $lines = @file($file, FILE_IGNORE_NEW_LINES);

            if ($lines === false) {
                return null;
            }

            $startIndex = max(0, $line - 1 - self::SNIPPET_CONTEXT_LINES);
            $endIndex = min(count($lines) - 1, $line - 1 + self::SNIPPET_CONTEXT_LINES);

            if ($startIndex > $endIndex) {
                return null;
            }

            return [
                'start_line' => $startIndex + 1,
                'lines' => array_slice($lines, $startIndex, $endIndex - $startIndex + 1),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public function relativePath(string $path): string
    {
        $base = rtrim($this->basePath, '/').'/';

        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }

    public function isApplicationPath(string $relativePath): bool
    {
        if (str_starts_with($relativePath, '/')) {
            // Still absolute, so it lives outside the project: not app code.
            return false;
        }

        foreach (self::NON_APPLICATION_PREFIXES as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return false;
            }
        }

        return true;
    }
}
