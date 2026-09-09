<?php

declare(strict_types=1);

namespace Weline\Blog\Api\Uri;

final class BlogNamespace
{
    public const PREFIX = 'blog';

    public const SKIP_REASON = 'blog_namespace_owner';

    public static function isBlogIdentifier(string $identifier): bool
    {
        $identifier = trim(strtolower($identifier), '/ ');
        if ($identifier === self::PREFIX) {
            return true;
        }

        return str_starts_with($identifier, self::PREFIX . '/');
    }

    public static function slugFromIdentifier(string $identifier): string
    {
        $identifier = trim(strtolower($identifier), '/ ');
        if ($identifier === self::PREFIX) {
            return '';
        }
        if (!str_starts_with($identifier, self::PREFIX . '/')) {
            return '';
        }

        return trim(substr($identifier, strlen(self::PREFIX) + 1), '/');
    }

    public static function identifierFromSlug(string $slug): string
    {
        $slug = trim(strtolower($slug), '/ ');
        if ($slug === '') {
            return self::PREFIX;
        }

        return self::PREFIX . '/' . $slug;
    }

    public static function publicPath(string $slug = ''): string
    {
        $slug = trim(strtolower($slug), '/ ');
        if ($slug === '') {
            return '/' . self::PREFIX;
        }

        return '/' . self::PREFIX . '/' . $slug;
    }

    public static function categoryPublicPath(string $slug = ''): string
    {
        $slug = trim(strtolower($slug), '/ ');
        if ($slug === '') {
            return '/' . self::PREFIX . '/category';
        }

        return '/' . self::PREFIX . '/category/' . $slug;
    }

    public static function rssPublicPath(): string
    {
        return '/' . self::PREFIX . '/rss.xml';
    }

    public static function categoryRssPublicPath(string $slug): string
    {
        $slug = trim(strtolower($slug), '/ ');
        if ($slug === '') {
            return self::rssPublicPath();
        }

        return self::categoryPublicPath($slug) . '/rss.xml';
    }

    public static function isReservedSlug(string $slug): bool
    {
        $slug = trim(strtolower($slug), '/ ');

        return $slug === 'category' || $slug === 'rss.xml';
    }

    /**
     * @return array{owner:string,identifier_prefix:string,reason:string}
     */
    public static function skipPayload(): array
    {
        return [
            'owner' => 'Weline_Blog',
            'identifier_prefix' => self::PREFIX,
            'reason' => self::SKIP_REASON,
        ];
    }
}
