<?php

namespace Bedaie\Watchtower\Support;

use Throwable;

/**
 * Detects the current code version (Section 3.3 release block), tried in
 * order: explicit env value, git HEAD, Forge deployment marker.
 */
class ReleaseDetector
{
    private string|false|null $cached = false;

    public function __construct(private string $basePath) {}

    public function detect(): ?string
    {
        if ($this->cached !== false) {
            return $this->cached;
        }

        return $this->cached = $this->resolve();
    }

    private function resolve(): ?string
    {
        try {
            $fromEnv = config('watchtower.release.env');

            if (is_string($fromEnv) && $fromEnv !== '') {
                return substr($fromEnv, 0, 64);
            }

            if (config('watchtower.release.git_head', true)) {
                $sha = $this->fromGitHead();

                if ($sha !== null) {
                    return $sha;
                }
            }

            if (config('watchtower.release.forge_deployment', true)) {
                return $this->fromForgeDeployment();
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function fromGitHead(): ?string
    {
        $headFile = $this->basePath.'/.git/HEAD';

        if (! is_readable($headFile)) {
            return null;
        }

        $head = trim((string) @file_get_contents($headFile));

        if ($head === '') {
            return null;
        }

        if (! str_starts_with($head, 'ref:')) {
            return $this->validSha($head);
        }

        $refPath = $this->basePath.'/.git/'.trim(substr($head, 4));

        if (is_readable($refPath)) {
            return $this->validSha(trim((string) @file_get_contents($refPath)));
        }

        $packedRefs = $this->basePath.'/.git/packed-refs';

        if (is_readable($packedRefs)) {
            $ref = trim(substr($head, 4));

            foreach (explode("\n", (string) @file_get_contents($packedRefs)) as $line) {
                if (str_ends_with($line, ' '.$ref)) {
                    return $this->validSha(explode(' ', $line)[0]);
                }
            }
        }

        return null;
    }

    private function fromForgeDeployment(): ?string
    {
        // Forge writes the deployed commit into this marker when configured
        // with the default deployment script.
        $marker = $this->basePath.'/.forge-deployment';

        if (is_readable($marker)) {
            return $this->validSha(trim((string) @file_get_contents($marker)));
        }

        return null;
    }

    private function validSha(string $value): ?string
    {
        return preg_match('/^[0-9a-f]{7,40}$/i', $value) === 1
            ? substr($value, 0, 64)
            : null;
    }
}
