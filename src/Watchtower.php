<?php

namespace Bedaie\Watchtower;

class Watchtower
{
    public const VERSION = '1.0.0';

    /**
     * Set when the server rejects the token with 401. Static, so it lasts
     * exactly until the process restarts (Section 4.1).
     */
    private static bool $disabled = false;

    public static function disable(): void
    {
        self::$disabled = true;
    }

    public static function isDisabled(): bool
    {
        return self::$disabled;
    }

    public static function enable(): void
    {
        self::$disabled = false;
    }
}
