<?php

declare(strict_types=1);

namespace Weline\Widget\Cache;

use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\View\Cache\TemplateFragmentOutputCache;

/**
 * 部件 HTML 输出缓存（guest-safe；进程 SWR 袋用 Framework TemplateFragmentOutputCache）。
 *
 * TTL：正整数秒；缺省 / 0 / off → 不缓存。
 * share：默认 false（键含 layout_name，按布局隔离）；true → 布局段为 `*`，跨布局共享袋。
 * 禁止键含 Session；调用方须带 node_uid / config 指纹。
 */
final class WidgetOutputCache
{
    public const CACHE_VERSION = '20261010-widget-output-ttl-v3';

    /** Layout-key sentinel when {@see normalizeShare} is true. */
    public const SHARED_LAYOUT_TOKEN = '*';

    /** RequestContext key: current page layout name (`{layout_type}.{layout_option}`). */
    public const REQUEST_LAYOUT_NAME_KEY = 'theme.current_layout_name';

    /**
     * Normalize cache attribute / @widget.cache value to TTL seconds.
     * Rejects legacy static/on/true — only positive int (or numeric string).
     */
    public static function normalizeTtl(mixed $raw): int
    {
        if ($raw === null || $raw === false || $raw === '') {
            return 0;
        }
        if (\is_string($raw)) {
            $token = \strtolower(\trim($raw));
            if ($token === '' || $token === 'off' || $token === 'false' || $token === 'no'
                || $token === 'static' || $token === 'on' || $token === 'true') {
                return 0;
            }
        }
        $ttl = (int)$raw;

        return $ttl < 1 ? 0 : min(86400, $ttl);
    }

    /**
     * Normalize @widget.share / share="…" — default false (layout-scoped keys).
     */
    public static function normalizeShare(mixed $raw): bool
    {
        if ($raw === true || $raw === 1) {
            return true;
        }
        if (\is_string($raw)) {
            $token = \strtolower(\trim($raw));

            return \in_array($token, ['1', 'true', 'yes', 'on', 'share', 'shared'], true);
        }

        return false;
    }

    /**
     * Resolve share flag from explicit parts, then config bag / meta.
     *
     * @param array<string, mixed> $parts
     * @param array<string, mixed> $bag
     */
    public static function resolveShare(array $parts = [], array $bag = []): bool
    {
        if (\array_key_exists('share', $parts) && $parts['share'] !== null) {
            return self::normalizeShare($parts['share']);
        }

        return self::normalizeShare(
            $bag['_cache_share'] ?? $bag['cache_share'] ?? $bag['_share'] ?? $bag['share'] ?? false,
        );
    }

    /**
     * Canonical layout name for cache keys: prefer explicit name, else `{type}.{option}`.
     *
     * @param array<string, mixed> $parts
     * @param array<string, mixed> $bag Dictionary / entry / request bag
     */
    public static function resolveLayoutName(array $parts = [], array $bag = []): string
    {
        $explicit = \trim((string)($parts['layout_name'] ?? $bag['_layout_name'] ?? $bag['layout_name'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $type = \trim((string)($parts['layout_type'] ?? $bag['_layout_type'] ?? $bag['layout_type'] ?? $bag['page_type'] ?? ''));
        $option = \trim((string)($parts['layout_option'] ?? $bag['_layout_option'] ?? $bag['layout_option'] ?? ''));
        if (($type === '' || $option === '') && \class_exists(\Weline\Framework\Runtime\RequestContext::class)) {
            try {
                $fromRequest = \trim((string)\Weline\Framework\Runtime\RequestContext::get(self::REQUEST_LAYOUT_NAME_KEY, ''));
                if ($fromRequest !== '' && \str_contains($fromRequest, '.')) {
                    [$reqType, $reqOption] = \explode('.', $fromRequest, 2);
                    if ($type === '') {
                        $type = \trim($reqType);
                    }
                    if ($option === '') {
                        $option = \trim($reqOption);
                    }
                } elseif ($fromRequest !== '' && $type === '') {
                    $type = $fromRequest;
                }
                if ($option === '' && \class_exists(\Weline\Theme\Api\Layout\LayoutIdentity::class)) {
                    $identity = \Weline\Framework\Runtime\RequestContext::get(
                        \Weline\Theme\Api\Layout\LayoutIdentity::REQUEST_CONTEXT_KEY,
                    );
                    if ($identity instanceof \Weline\Theme\Api\Layout\LayoutIdentity) {
                        $option = \trim((string)$identity->layoutOption);
                    }
                }
            } catch (\Throwable) {
            }
        }
        if ($type !== '' && $option !== '') {
            return $type . '.' . $option;
        }
        if ($type !== '') {
            return $type;
        }

        return $option;
    }

    /**
     * @param array<string, mixed> $configForDigest Stable widget config bag (node params).
     * @param array{
     *   identity?: string,
     *   template_path?: string,
     *   node_uid?: string,
     *   product_id?: int,
     *   offer_id?: int,
     *   ttl?: int,
     *   share?: bool|string|int,
     *   layout_name?: string,
     *   layout_type?: string,
     *   layout_option?: string
     * } $parts
     */
    public static function buildKey(array $parts, array $configForDigest = []): string
    {
        $templatePath = (string)($parts['template_path'] ?? '');
        $mtime = 0;
        $size = 0;
        if ($templatePath !== '' && \is_file($templatePath)) {
            $stat = @\stat($templatePath);
            if (\is_array($stat)) {
                $mtime = (int)($stat['mtime'] ?? 0);
                $size = (int)($stat['size'] ?? 0);
            }
        }

        $digestBag = $configForDigest;
        unset(
            $digestBag['theme_component'],
            $digestBag['theme_component_meta'],
            $digestBag['config'],
            $digestBag['children'],
            $digestBag['preview_mode'],
            $digestBag['editor_mode'],
        );
        $configDigest = \hash(
            'sha256',
            (string)\json_encode($digestBag, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        );
        $share = self::resolveShare($parts, $configForDigest);
        $layoutName = $share
            ? self::SHARED_LAYOUT_TOKEN
            : self::resolveLayoutName($parts, $configForDigest);

        return 'widget.output.' . \sha1(\implode('|', [
            (string)($parts['identity'] ?? ''),
            $templatePath,
            (string)$mtime,
            (string)$size,
            (string)($parts['node_uid'] ?? ''),
            (string)max(0, (int)($parts['product_id'] ?? 0)),
            (string)max(0, (int)($parts['offer_id'] ?? 0)),
            $layoutName,
            $share ? '1' : '0',
            $configDigest,
            (string)max(0, (int)($parts['ttl'] ?? 0)),
            KeyBuilder::environmentHash([
                'cache_version' => self::CACHE_VERSION,
                'widget_context' => 'ttl',
            ], [
                'area_route' => false,
            ]),
        ]));
    }

    /**
     * @param callable():string $builder
     */
    public static function remember(int $ttl, string $cacheKey, callable $builder): string
    {
        $ttl = self::normalizeTtl($ttl);
        if ($ttl < 1 || $cacheKey === '') {
            $html = $builder();

            return \is_string($html) ? $html : '';
        }

        $cached = TemplateFragmentOutputCache::get($cacheKey, $ttl);
        if (($cached['status'] ?? '') !== 'miss' && \is_string($cached['html'] ?? null)) {
            return (string)$cached['html'];
        }

        $html = $builder();
        $html = \is_string($html) ? $html : '';
        if ($html !== '') {
            TemplateFragmentOutputCache::set($cacheKey, $html, $ttl);
        }

        return $html;
    }
}
