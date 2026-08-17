<?php

namespace Bedaie\Watchtower\Support;

/**
 * Client side scrubbing (Section 3.7). Runs on request input, headers, and
 * log context before anything leaves the application. Query bindings are
 * never sent at all, so they never reach this class.
 */
class Scrubber
{
    public const REPLACEMENT = '[DITAPIS]';

    /** Headers that are always dropped entirely, never just masked. */
    private const DROPPED_HEADERS = ['authorization', 'cookie', 'x-csrf-token', 'x-xsrf-token', 'php-auth-pw'];

    /** @var list<string> */
    private array $keys;

    /** @var list<string> */
    private array $patterns;

    private bool $scrubEmails;

    public function __construct(?array $keys = null, ?array $patterns = null, ?bool $scrubEmails = null)
    {
        $this->keys = $keys ?? config('watchtower.scrub.keys', []);
        $this->patterns = $patterns ?? config('watchtower.scrub.patterns', []);
        $this->scrubEmails = $scrubEmails ?? (bool) config('watchtower.scrub.scrub_emails', false);
    }

    public function scrub(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->keyMatches($key)) {
                $result[$key] = self::REPLACEMENT;

                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->scrub($value);
            } elseif (is_string($value)) {
                $result[$key] = $this->scrubString($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Scrub request headers: sensitive headers are removed completely, the
     * rest go through normal key and pattern scrubbing.
     */
    public function scrubHeaders(array $headers): array
    {
        $filtered = [];

        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), self::DROPPED_HEADERS, true)) {
                continue;
            }

            $filtered[$name] = $value;
        }

        return $this->scrub($filtered);
    }

    public function scrubString(string $value): string
    {
        foreach ($this->patterns as $pattern) {
            $value = preg_replace($pattern, self::REPLACEMENT, $value) ?? $value;
        }

        if ($this->scrubEmails) {
            $value = preg_replace(
                '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
                self::REPLACEMENT,
                $value
            ) ?? $value;
        }

        return $value;
    }

    /**
     * Case insensitive partial matching, but short terms such as "ic" only
     * match whole tokens so keys like "is_application" survive. Tokens are
     * split on both separators and camelCase boundaries so "cardCvv" and
     * "noIc" are caught too.
     */
    private function keyMatches(string $key): bool
    {
        $withBoundaries = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key) ?? $key;
        $lower = strtolower($withBoundaries);
        $tokens = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($this->keys as $sensitive) {
            $term = strtolower($sensitive);

            if ($lower === $term || in_array($term, $tokens, true)) {
                return true;
            }

            if ((strlen($term) >= 5 || str_contains($term, '_')) && str_contains($lower, $term)) {
                return true;
            }
        }

        return false;
    }
}
