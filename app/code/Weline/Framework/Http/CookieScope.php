<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * Framework-owned Cookie name/Path resolution.
 *
 * Default behavior is identity (name unchanged, Path kept). Multi-site / mount
 * isolation is NOT a Framework concern — modules contribute via
 * {@see self::EVENT_RESOLVE} using framework-neutral fields only
 * (`name_suffix`, `mount_path`, …). Do not put Website model semantics here.
 *
 * Protocol and authentication-realm cookies (`__Host-` / `__Secure-` /
 * Worker bootstrap / backend remember-device) stay exact: browser prefix
 * rules and realm bridges require Path=/ and the wire name unchanged.
 */
final class CookieScope
{
    public const EVENT_RESOLVE = 'Weline_Framework_Http::cookie_scope_resolve';

    /** @var (callable(): array<string, mixed>)|null */
    private static $policyResolverOverride = null;

    /**
     * Test-only override for policy resolution (cleared in tearDown).
     *
     * @param (callable(): array<string, mixed>)|null $resolver
     */
    public static function setPolicyResolverOverride(?callable $resolver): void
    {
        self::$policyResolverOverride = $resolver;
    }

    /**
     * @return array{
     *     active: bool,
     *     name_suffix: string,
     *     name_suffix_pattern: string,
     *     mount_path: string,
     *     expire_unscoped_aliases: bool,
     *     revision: string
     * }
     */
    public static function policy(): array
    {
        return self::resolvePolicy();
    }

    /**
     * Qualify a cookie name for the active HTTP cookie scope.
     * Idempotent when the name already ends with the resolved suffix.
     */
    public static function qualifyName(string $name): string
    {
        $name = \trim($name);
        if ($name === '' || self::isProtocolCookie($name)) {
            return $name;
        }

        $policy = self::policy();
        $suffix = $policy['name_suffix'];
        if ($suffix === '') {
            return $name;
        }

        if (\str_ends_with($name, $suffix)) {
            return $name;
        }

        $pattern = $policy['name_suffix_pattern'];
        if ($pattern !== '' && @\preg_match($pattern, $name) === 1) {
            return (string)\preg_replace($pattern, $suffix, $name);
        }

        return $name . $suffix;
    }

    /**
     * Rewrite Path=/ (or empty) onto the resolved mount path when isolation is active.
     * Explicit non-root paths are kept as-is.
     */
    public static function resolvePath(string $requestedPath = '/'): string
    {
        $requestedPath = \trim($requestedPath);
        if ($requestedPath === '') {
            $requestedPath = '/';
        }

        $policy = self::policy();
        if (!$policy['active']) {
            return $requestedPath;
        }

        if ($requestedPath === '/' || $requestedPath === '') {
            return $policy['mount_path'];
        }

        return $requestedPath;
    }

    public static function shouldExpireUnscopedAliases(): bool
    {
        return self::policy()['expire_unscoped_aliases'];
    }

    /**
     * Cookies that must keep Path=/ and an exact wire name (no scope suffix).
     */
    public static function isProtocolCookie(string $name): bool
    {
        $name = \trim($name);
        if ($name === '') {
            return false;
        }
        if (\str_starts_with($name, '__Host-') || \str_starts_with($name, '__Secure-')) {
            return true;
        }
        if (\preg_match('/^w_backend_ut(?:_[1-9][0-9]{0,4})?$/D', $name) === 1) {
            return true;
        }

        return \str_starts_with($name, 'Weline-Worker-Backend-Bootstrap-')
            || \str_starts_with($name, 'Weline-Worker-Scope-Bootstrap-');
    }

    /**
     * @deprecated Use {@see isProtocolCookie()}
     */
    public static function isWorkerProtocolCookie(string $name): bool
    {
        return self::isProtocolCookie($name);
    }

    /**
     * @return array{
     *     active: bool,
     *     name_suffix: string,
     *     mount_path: string,
     *     expire_unscoped_aliases: bool,
     *     revision: string
     * }
     */
    private static function resolvePolicy(): array
    {
        if (self::$policyResolverOverride !== null) {
            try {
                $data = (self::$policyResolverOverride)();
            } catch (\Throwable) {
                $data = [];
            }
            if (!\is_array($data)) {
                $data = [];
            }

            return self::normalizePolicy($data + [
                'active' => false,
                'name_suffix' => '',
                'name_suffix_pattern' => '',
                'mount_path' => '/',
                'expire_unscoped_aliases' => false,
                'revision' => '',
            ]);
        }

        $data = [
            'active' => false,
            'name_suffix' => '',
            'name_suffix_pattern' => '',
            'mount_path' => '/',
            'expire_unscoped_aliases' => false,
            'revision' => '',
        ];

        try {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventsManager->dispatch(self::EVENT_RESOLVE, $data);
        } catch (\Throwable) {
            // Event failure must not break cookie emission.
        }

        unset($data['observers']);

        return self::normalizePolicy($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     active: bool,
     *     name_suffix: string,
     *     name_suffix_pattern: string,
     *     mount_path: string,
     *     expire_unscoped_aliases: bool,
     *     revision: string
     * }
     */
    private static function normalizePolicy(array $data): array
    {
        $mount = \trim((string)($data['mount_path'] ?? '/'));
        if ($mount === '') {
            $mount = '/';
        }
        $mount = '/' . \trim($mount, '/');
        if ($mount !== '/') {
            $mount = \rtrim($mount, '/');
        }

        $suffix = \trim((string)($data['name_suffix'] ?? ''));
        if ($suffix !== '' && !\str_starts_with($suffix, '_')) {
            $suffix = '_' . $suffix;
        }

        $pattern = \trim((string)($data['name_suffix_pattern'] ?? ''));

        return [
            'active' => (bool)($data['active'] ?? false),
            'name_suffix' => $suffix,
            'name_suffix_pattern' => $pattern,
            'mount_path' => $mount,
            'expire_unscoped_aliases' => (bool)($data['expire_unscoped_aliases'] ?? false),
            'revision' => \trim((string)($data['revision'] ?? '')),
        ];
    }
}
