<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Shared explicit opt-out for setup-triggered static publication.
 *
 * This is a temporary operational switch. It skips the publish work; it does
 * not make the underlying static tree or publish path faster.
 */
final class StaticPublishGate
{
    public const ENV_SKIP = 'WELINE_SKIP_STATIC_DEPLOY';
    public const OPTION_SKIP = '--skip-static-deploy';

    /**
     * @param array<string, mixed> $args
     * @return string|null skip reason, or null when publication should run
     */
    public static function skipReason(array $args = []): ?string
    {
        if (self::envFlag(self::ENV_SKIP)) {
            return self::ENV_SKIP . '=1';
        }

        if (!empty($args['skip-static-deploy']) || !empty($args['skip_static_deploy'])) {
            return self::OPTION_SKIP;
        }

        $argv = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? []);
        foreach ((array) $argv as $arg) {
            $arg = (string) $arg;
            if ($arg === self::OPTION_SKIP || str_starts_with($arg, self::OPTION_SKIP . '=')) {
                return self::OPTION_SKIP;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function isSkipped(array $args = []): bool
    {
        return self::skipReason($args) !== null;
    }

    private static function envFlag(string $name): bool
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
