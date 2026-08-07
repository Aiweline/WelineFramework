<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Runtime\RequestContext;

/**
 * Cookie isolation by website (host + mount path) for storefront AND backend.
 *
 * Browser cookies are scoped by Domain+Path, but a Path=/ cookie is still sent
 * to every sub-path on the same host. Path-mounted websites therefore need both:
 * 1) Cookie Path bound to the website mount path
 * 2) Cookie name qualified by website_id so a parent Path=/ cookie cannot be reused
 *
 * Backend / rest_backend use the same name/path qualification once website context
 * is installed, so each site keeps an independent admin session.
 */
final class WebsiteCookieScope
{
    private const NAME_SUFFIX_PATTERN = '/_w\d+$/';

    /** @deprecated Use {@see isIsolationActive()} */
    public static function isStorefrontIsolationActive(): bool
    {
        return self::isIsolationActive();
    }

    public static function isIsolationActive(): bool
    {
        if (RequestContext::getId() === null || RequestContext::getId() === '') {
            return false;
        }

        $websiteUrl = '';
        try {
            $websiteUrl = (string)RequestContext::getWelineWebsiteUrl();
        } catch (\Throwable) {
            $websiteUrl = '';
        }
        if ($websiteUrl === '') {
            return false;
        }

        $websiteId = self::websiteId();
        return $websiteId >= 0;
    }

    public static function websiteId(): int
    {
        if (RequestContext::getId() === null || RequestContext::getId() === '') {
            return -1;
        }

        try {
            return (int)RequestContext::getWelineWebsiteId();
        } catch (\Throwable) {
            return -1;
        }
    }

    /**
     * Cookie Path for the current website mount (`/` or `/aisite_accept_ok`).
     */
    public static function path(): string
    {
        if (!self::isIsolationActive()) {
            return '/';
        }

        $websiteUrl = '';
        try {
            $websiteUrl = (string)RequestContext::getWelineWebsiteUrl();
        } catch (\Throwable) {
            $websiteUrl = '';
        }

        if ($websiteUrl === '') {
            return '/';
        }

        $path = (string)(\parse_url($websiteUrl, \PHP_URL_PATH) ?: '/');
        $path = '/' . \trim($path, '/');

        return $path === '/' ? '/' : \rtrim($path, '/');
    }

    /**
     * Rewrite Path=/ (or empty) cookies onto the website mount path.
     * Explicit non-root paths are kept as-is.
     */
    public static function resolvePath(string $requestedPath = '/'): string
    {
        $requestedPath = \trim($requestedPath);
        if ($requestedPath === '') {
            $requestedPath = '/';
        }

        if (!self::isIsolationActive()) {
            return $requestedPath === '' ? '/' : $requestedPath;
        }

        if ($requestedPath === '/' || $requestedPath === '') {
            return self::path();
        }

        return $requestedPath;
    }

    public static function nameSuffix(): string
    {
        if (!self::isIsolationActive()) {
            return '';
        }

        $websiteUrl = '';
        try {
            $websiteUrl = (string)RequestContext::getWelineWebsiteUrl();
        } catch (\Throwable) {
            $websiteUrl = '';
        }
        // Website not installed yet (pre DetectWebsite / ScopeIdentity): keep legacy names.
        if ($websiteUrl === '') {
            return '';
        }

        $websiteId = self::websiteId();
        if ($websiteId < 0) {
            return '';
        }

        return '_w' . $websiteId;
    }

    /**
     * Qualify a cookie name for the current website.
     * Idempotent when the name already ends with `_w{id}`.
     */
    public static function qualifyName(string $name): string
    {
        $name = \trim($name);
        if ($name === '') {
            return '';
        }

        $suffix = self::nameSuffix();
        if ($suffix === '') {
            return $name;
        }

        if (\str_ends_with($name, $suffix)) {
            return $name;
        }

        // Replace a stale `_wNNN` from another website with the current one.
        if (\preg_match(self::NAME_SUFFIX_PATTERN, $name) === 1) {
            return (string)\preg_replace(self::NAME_SUFFIX_PATTERN, $suffix, $name);
        }

        return $name . $suffix;
    }
}
