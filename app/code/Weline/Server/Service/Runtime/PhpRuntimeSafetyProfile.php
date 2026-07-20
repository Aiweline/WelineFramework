<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Console\PhpCliRuntimePreflight;

/**
 * Server-facing compatibility facade for the Framework CLI runtime preflight.
 *
 * The dependency-free implementation lives in Framework so bin/w can enforce it
 * before application bootstrap without creating Framework -> Server coupling.
 */
final class PhpRuntimeSafetyProfile
{
    public const PROFILE_WINDOWS_ARM64_X64_NOOPCACHE = PhpCliRuntimePreflight::PROFILE;

    /** @deprecated Use PROFILE_WINDOWS_ARM64_X64_NOOPCACHE. */
    public const PROFILE_WINDOWS_ARM64_X64_NOJIT = self::PROFILE_WINDOWS_ARM64_X64_NOOPCACHE;

    public const ENV_PROFILE = PhpCliRuntimePreflight::ENV_WLS_PROFILE;

    /** @return array<string, mixed> */
    public static function applyForWlsProcessTree(): array
    {
        return PhpCliRuntimePreflight::applyForDescendants(self::resolveProjectRoot());
    }

    /** @return array<string, mixed> */
    public static function detect(): array
    {
        return PhpCliRuntimePreflight::inspect();
    }

    public static function requiresJitIsolation(): bool
    {
        return !empty(PhpCliRuntimePreflight::inspect()['requires_jit_isolation']);
    }

    public static function requiresNativeExtensionIsolation(): bool
    {
        return \PHP_OS_FAMILY === 'Windows' && self::requiresJitIsolation();
    }

    public static function isJitEnabled(): bool
    {
        return PhpCliRuntimePreflight::isJitEnabled();
    }

    private static function resolveProjectRoot(): string
    {
        return \defined('BP')
            ? \rtrim((string)\BP, '/\\')
            : \dirname(__DIR__, 6);
    }
}
