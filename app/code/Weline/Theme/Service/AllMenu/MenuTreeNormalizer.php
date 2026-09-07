<?php

declare(strict_types=1);

namespace Weline\Theme\Service\AllMenu;

use Weline\Framework\App\State;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\WidgetI18n;

/**
 * Normalizes all-menu nested arrays: tags, depth<=3, stable ids, display fields.
 *
 * Node `name` is the Chinese source string (i18n key). Storefront display
 * resolves the current locale via WidgetI18n / __() — do not embed per-locale maps.
 */
final class MenuTreeNormalizer
{
    public const MAX_DEPTH = 3;

    public const TAG_PAGE = 'page';
    public const TAG_CATEGORY = 'category';
    public const TAG_CUSTOM = 'custom';

    /** @var list<string> */
    private const ALLOWED_TAGS = [self::TAG_PAGE, self::TAG_CATEGORY, self::TAG_CUSTOM];

    /**
     * @param mixed $tree
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $tree, int $maxDepth = self::MAX_DEPTH): array
    {
        $items = $this->decode($tree);
        $maxDepth = max(1, min(self::MAX_DEPTH, $maxDepth));

        return $this->normalizeList($items, 1, $maxDepth);
    }

    /**
     * Resolve display name for the current storefront language.
     *
     * @param array<string, mixed> $node
     */
    public function resolveDisplayName(array $node): string
    {
        return $this->resolveLocalizedField($node, 'name', ['text', 'label']);
    }

    /**
     * Resolve display description for the current storefront language.
     *
     * @param array<string, mixed> $node
     */
    public function resolveDisplayDescription(array $node): string
    {
        return $this->resolveLocalizedField($node, 'description', ['desc', 'subtitle']);
    }

    /**
     * Flatten to navItems shape. `text` / `description` are resolved for the
     * active storefront locale (node i18n map first, then WidgetI18n / __()).
     * Relative `url` values are rebuilt via getFrontendUrl so currency/lang path follows.
     *
     * @param list<array<string, mixed>> $tree
     * @return list<array<string, mixed>>
     */
    public function toNavItems(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            $item = [
                'text' => $this->resolveDisplayName($node),
                'url' => $this->localizeUrl((string)($node['url'] ?? '#')),
            ];
            $image = trim((string)($node['image'] ?? $node['img'] ?? $node['icon_url'] ?? ''));
            if ($image !== '') {
                $item['image'] = $image;
            }
            $banner = trim((string)($node['banner'] ?? ''));
            if ($banner !== '' && !str_starts_with($banner, 'data:image/')) {
                $item['banner'] = $banner;
            }
            $description = $this->resolveDisplayDescription($node);
            if ($description !== '') {
                $item['description'] = $description;
            }
            $summary = trim((string)($node['summary'] ?? ''));
            if ($summary !== '') {
                $item['summary'] = $summary;
            }
            $children = $node['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $item['children'] = $this->toNavItems($children);
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(mixed $tree): array
    {
        if (is_string($tree)) {
            $trimmed = trim($tree);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($tree) ? $tree : [];
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function normalizeList(array $items, int $depth, int $maxDepth): array
    {
        $out = [];
        foreach ($items as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $node = $this->normalizeNode($raw, $depth, $maxDepth);
            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>|null
     */
    private function normalizeNode(array $raw, int $depth, int $maxDepth): ?array
    {
        $name = trim((string)($raw['name'] ?? $raw['text'] ?? $raw['label'] ?? ''));
        $url = trim((string)($raw['url'] ?? $raw['link'] ?? ''));
        if ($name === '' && $url === '') {
            return null;
        }
        if ($name === '') {
            $name = $url !== '' ? $url : (function_exists('__') ? (string)__('未命名') : '未命名');
        }
        if ($url === '') {
            $url = '#';
        }

        $tag = strtolower(trim((string)($raw['tag'] ?? $raw['type'] ?? self::TAG_CUSTOM)));
        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            $tag = self::TAG_CUSTOM;
        }

        $id = trim((string)($raw['id'] ?? ''));
        if ($id === '') {
            $id = 'n_' . substr(sha1($tag . '|' . $name . '|' . $url . '|' . $depth), 0, 12);
        }

        $node = [
            'id' => $id,
            'tag' => $tag,
            'name' => $name,
            'url' => $url,
            'children' => [],
        ];

        // Intentionally drop legacy name_i18n maps — use structured `i18n.name` / `i18n.description`.

        $i18n = $this->normalizeI18nBlock($raw['i18n'] ?? null);
        if ($i18n !== []) {
            $node['i18n'] = $i18n;
        }

        $description = trim((string)($raw['description'] ?? ''));
        if ($description !== '') {
            $node['description'] = $description;
        }

        $summary = trim((string)($raw['summary'] ?? ''));
        if ($summary !== '') {
            $node['summary'] = $summary;
        }

        $image = $this->normalizeImageValue($raw['image'] ?? null);
        if ($image !== null && $image !== '') {
            $node['image'] = $image;
        }

        $banner = trim((string)($raw['banner'] ?? ''));
        if ($banner !== '' && !str_starts_with($banner, 'data:image/')) {
            $node['banner'] = $banner;
        }

        $ref = trim((string)($raw['ref'] ?? ''));
        if ($ref !== '') {
            $node['ref'] = $ref;
        }

        $meta = $raw['meta'] ?? null;
        if (is_array($meta) && $meta !== []) {
            $node['meta'] = $meta;
        }

        if ($depth < $maxDepth) {
            $children = $raw['children'] ?? [];
            if (is_array($children) && $children !== []) {
                $node['children'] = $this->normalizeList($children, $depth + 1, $maxDepth);
            }
        }

        return $node;
    }

    /**
     * Rebuild relative storefront hrefs with the active currency/lang path prefix.
     */
    public function localizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return $url !== '' ? $url : '#';
        }
        if (
            preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $url) === 1
            || str_starts_with($url, 'mailto:')
            || str_starts_with($url, 'tel:')
            || str_starts_with($url, 'javascript:')
        ) {
            return $url;
        }

        $path = ltrim($url, '/');
        if ($path === '') {
            return $this->frontendUrl('/');
        }

        $segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
        while ($segments !== []) {
            $first = (string)$segments[0];
            if (State::isAllowedLanguageCode($first)) {
                array_shift($segments);
                continue;
            }
            try {
                $probe = '/' . $first . '/';
                if (\Weline\Framework\Http\Url::detectCurrency($probe, $first)) {
                    array_shift($segments);
                    continue;
                }
            } catch (\Throwable) {
                // Unit/CLI without full Env bootstrap: do not invent currency stripping.
            }
            break;
        }

        $route = implode('/', $segments);

        return $this->frontendUrl($route !== '' ? $route : '/');
    }

    private function frontendUrl(string $route): string
    {
        try {
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);

            return (string)$request->getUrlBuilder()->getFrontendUrl(ltrim($route, '/'));
        } catch (\Throwable) {
            try {
                /** @var Url $url */
                $url = ObjectManager::getInstance(Url::class);

                return (string)$url->getFrontendUrl(ltrim($route, '/'));
            } catch (\Throwable) {
                $route = trim($route);
                if ($route === '' || $route === '/') {
                    return '/';
                }

                return str_starts_with($route, '/') ? $route : '/' . $route;
            }
        }
    }

    private function resolveStorefrontLocale(): string
    {
        try {
            $lang = trim(\Weline\Framework\App\State::getLangLocal());
        } catch (\Throwable) {
            $lang = '';
        }
        $requestUri = (string) (\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '') ?: ($_SERVER['REQUEST_URI'] ?? ''));
        if ($requestUri !== '' && preg_match('#/(ar_SA|en_US|zh_Hans_CN|zh_CN)(?:/|$)#', $requestUri, $matches)) {
            return (string) $matches[1];
        }

        return $lang !== '' ? $lang : 'zh_Hans_CN';
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $aliases
     */
    private function resolveLocalizedField(array $node, string $field, array $aliases = []): string
    {
        $source = trim((string)($node[$field] ?? ''));
        if ($source === '') {
            foreach ($aliases as $alias) {
                $source = trim((string)($node[$alias] ?? ''));
                if ($source !== '') {
                    break;
                }
            }
        }

        $locale = $this->resolveStorefrontLocale();
        $i18nMap = $node['i18n'][$field] ?? null;
        if (is_array($i18nMap)) {
            $localized = trim((string)($i18nMap[$locale] ?? ''));
            if ($localized !== '') {
                $source = $localized;
            }
        }

        if ($source === '') {
            return '';
        }

        // Product catalog trees already resolve names for the active storefront locale.
        // Re-running WidgetI18n/__ can reverse-translate EN labels (Hanfu→汉服) when
        // RequestContext/KeyBuilder lang still lags behind the /{locale}/ path.
        if (($node['tag'] ?? '') === self::TAG_CATEGORY) {
            return $source;
        }

        try {
            return WidgetI18n::label($source);
        } catch (\Throwable) {
            return function_exists('__') ? (string)__($source) : $source;
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function normalizeI18nBlock(mixed $i18n): array
    {
        if (!is_array($i18n) || $i18n === []) {
            return [];
        }
        $out = [];
        foreach (['name', 'description'] as $field) {
            if (!isset($i18n[$field]) || !is_array($i18n[$field])) {
                continue;
            }
            $map = [];
            foreach ($i18n[$field] as $locale => $text) {
                $text = trim((string) $text);
                if ($text !== '') {
                    $map[(string) $locale] = $text;
                }
            }
            if ($map !== []) {
                $out[$field] = $map;
            }
        }

        return $out;
    }

    private function normalizeImageValue(mixed $image): mixed
    {
        if ($image === null || $image === '') {
            return null;
        }
        if (is_array($image) && ($image['type'] ?? '') === 'file-image') {
            return $image;
        }
        if (is_string($image)) {
            $trimmed = trim($image);
            if ($trimmed === '') {
                return null;
            }
            if (str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded) && ($decoded['type'] ?? '') === 'file-image') {
                    return $decoded;
                }
            }

            return $trimmed;
        }

        return null;
    }
}
