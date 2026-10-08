<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Model\Website;

/**
 * Synthetic storefront mount on the standard project Host: /~site/{Website.code}.
 *
 * Not a WebsiteDomain row. DetectWebsite recognizes it before domain/default match.
 * Theme editor/capture builds the same path when the admin Host is the project Host.
 */
final class ProjectHostSiteMount
{
    public const PREFIX_SEGMENT = '~site';

    public const PATH_PREFIX = '/~site';

    /**
     * URL-safe website code for synthetic mounts (lowercase).
     * Matches WebsiteSubPathValidator segment shape without tilde.
     */
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/D';

    /**
     * Whether $code may appear as /~site/{code}.
     */
    public static function isMountableCode(string $code): bool
    {
        $code = \strtolower(\trim($code));
        if ($code === '' || $code === self::PREFIX_SEGMENT) {
            return false;
        }

        return \preg_match(self::CODE_PATTERN, $code) === 1;
    }

    /**
     * Absolute mount path `/~site/{code}`, or '' when code is not mountable.
     * Default website may still use `/~site/default` when explicitly requested;
     * Theme leaves default mount-less on bare project Host.
     */
    public static function mountPathForCode(string $code): string
    {
        $code = \strtolower(\trim($code));
        if (!self::isMountableCode($code)) {
            return '';
        }

        return self::PATH_PREFIX . '/' . $code;
    }

    /**
     * Parse request path for a synthetic project-Host site mount.
     *
     * @return array{code: string, mount: string}|null null when path is not under /~site
     * @throws \InvalidArgumentException when path is under /~site but code is missing/invalid
     */
    public static function parseMountFromPath(string $path): ?array
    {
        $path = '/' . \trim(\str_replace('\\', '/', $path), '/');
        if ($path === '//') {
            $path = '/';
        }
        if ($path === '/') {
            return null;
        }

        $prefix = self::PATH_PREFIX;
        if ($path !== $prefix && !\str_starts_with($path, $prefix . '/')) {
            return null;
        }

        $remainder = $path === $prefix ? '' : \substr($path, \strlen($prefix));
        $remainder = \ltrim((string)$remainder, '/');
        $code = $remainder === '' ? '' : \explode('/', $remainder, 2)[0];
        $code = \strtolower(\trim($code));
        if ($code === '' || !self::isMountableCode($code)) {
            throw new \InvalidArgumentException('Invalid project-host site mount path.');
        }

        return [
            'code' => $code,
            'mount' => self::PATH_PREFIX . '/' . $code,
        ];
    }

    /**
     * Theme/editor: non-default websites on the project Host use /~site/{code}.
     */
    public static function editorMountPath(int $websiteId, string $websiteCode): string
    {
        if ($websiteId === Website::ID_DEFAULT
            && \strtolower(\trim($websiteCode)) === Website::CODE_DEFAULT
        ) {
            return '';
        }

        return self::mountPathForCode($websiteCode);
    }

    /**
     * True when a WebsiteDomain.sub_path collides with the reserved synthetic prefix.
     */
    public static function conflictsWithDomainSubPath(string $subPath): bool
    {
        $normalized = '/' . \trim(\str_replace('\\', '/', $subPath), '/');
        if ($normalized === '/' || $normalized === '') {
            return false;
        }

        return $normalized === self::PATH_PREFIX
            || \str_starts_with($normalized, self::PATH_PREFIX . '/');
    }
}
