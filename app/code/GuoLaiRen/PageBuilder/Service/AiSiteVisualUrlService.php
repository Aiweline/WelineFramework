<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Url;

class AiSiteVisualUrlService
{
    public function __construct(
        private readonly Url $url,
    ) {
    }

    /**
     * @return array{preview_full_url:string,visual_preview_url:string,visual_edit_url:string}
     */
    public function resolveUrls(int $pageId, int $virtualThemeId = 0): array
    {
        if ($pageId <= 0) {
            return [
                'preview_full_url' => '',
                'visual_preview_url' => '',
                'visual_edit_url' => '',
            ];
        }

        $previewParams = ['page_id' => $pageId];
        $visualPreviewParams = ['page_id' => $pageId, 'visual_editor' => '1'];
        $visualEditParams = ['id' => $pageId];

        if ($virtualThemeId > 0) {
            $previewParams['virtual_theme_id'] = $virtualThemeId;
            $visualPreviewParams['virtual_theme_id'] = $virtualThemeId;
            $visualEditParams['virtual_theme_id'] = $virtualThemeId;
        }

        return $this->normalizePreviewUrlsForCurrentBackendRequest([
            'preview_full_url' => $this->url->getBackendUrl('pagebuilder/backend/preview/full', $previewParams),
            'visual_preview_url' => $this->url->getBackendUrl('pagebuilder/backend/preview/full', $visualPreviewParams),
            'visual_edit_url' => $this->url->getBackendUrl('pagebuilder/backend/page/edit', $visualEditParams),
        ]);
    }

    /**
     * @return array{preview_full_url:string,visual_preview_url:string,visual_edit_url:string}
     */
    public function resolveVirtualUrls(string $publicId, string $pageType, int $virtualThemeId = 0): array
    {
        $publicId = \trim($publicId);
        $pageType = \trim($pageType);
        if ($publicId === '' || $pageType === '') {
            return [
                'preview_full_url' => '',
                'visual_preview_url' => '',
                'visual_edit_url' => '',
            ];
        }

        $baseParams = [
            'public_id' => $publicId,
            'page_type' => $pageType,
        ];
        $previewParams = $baseParams + [
            'preview' => '1',
        ];
        $visualPreviewParams = $previewParams + ['visual_editor' => '1'];
        $visualEditParams = $baseParams;

        if ($virtualThemeId > 0) {
            $previewParams['virtual_theme_id'] = $virtualThemeId;
            $visualPreviewParams['virtual_theme_id'] = $virtualThemeId;
            $visualEditParams['virtual_theme_id'] = $virtualThemeId;
        }

        return $this->normalizePreviewUrlsForCurrentBackendRequest([
            'preview_full_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace-preview', $previewParams),
            'visual_preview_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace-preview', $visualPreviewParams),
            'visual_edit_url' => $this->url->getBackendUrl('pagebuilder/backend/page/virtual-edit', $visualEditParams),
        ]);
    }

    /**
     * @param array{preview_full_url:string,visual_preview_url:string,visual_edit_url:string} $urls
     * @return array{preview_full_url:string,visual_preview_url:string,visual_edit_url:string}
     */
    private function normalizePreviewUrlsForCurrentBackendRequest(array $urls): array
    {
        $localePrefix = $this->resolveCurrentBackendLocalePrefix();
        if ($localePrefix === '') {
            return $urls;
        }

        foreach ($urls as $key => $url) {
            $urls[$key] = $this->insertBackendLocalePrefixIfMissing((string)$url, $localePrefix);
        }

        return $urls;
    }

    private function resolveCurrentBackendLocalePrefix(): string
    {
        $requestUri = \trim((string)($_SERVER['WELINE_FULL_REQUEST_URI'] ?? $_SERVER['REQUEST_URI'] ?? ''));
        if ($requestUri === '') {
            return '';
        }

        $path = (string)(\parse_url($requestUri, \PHP_URL_PATH) ?: $requestUri);
        $segments = \array_values(\array_filter(\explode('/', \trim($path, '/')), static fn(string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return '';
        }

        $backendPrefix = \trim((string)Env::getAreaRoutePrefix('backend'), '/');
        $backendIndex = \array_search($backendPrefix, $segments, true);
        if ($backendIndex === false) {
            return '';
        }

        $first = (string)($segments[$backendIndex + 1] ?? '');
        $second = (string)($segments[$backendIndex + 2] ?? '');
        if ($this->isCurrencySegment($first) && $this->isLocaleSegment($second)) {
            return '/' . $first . '/' . $second;
        }
        if ($this->isLocaleSegment($first)) {
            return '/' . $first;
        }

        return '';
    }

    private function insertBackendLocalePrefixIfMissing(string $url, string $localePrefix): string
    {
        $url = \trim($url);
        if ($url === '' || $localePrefix === '') {
            return $url;
        }

        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return $url;
        }

        $path = (string)($parts['path'] ?? '');
        if ($path === '' || !\str_contains($path, '/pagebuilder/backend/')) {
            return $url;
        }

        $backendPrefix = '/' . \trim((string)Env::getAreaRoutePrefix('backend'), '/');
        if (!\str_starts_with($path . '/', $backendPrefix . '/')) {
            return $url;
        }

        $afterBackend = \substr($path, \strlen($backendPrefix));
        if (\str_starts_with($afterBackend . '/', $localePrefix . '/')) {
            return $url;
        }
        if (\preg_match('#^/[A-Z]{3}/[A-Za-z]{2}(?:[_-][A-Za-z0-9]{2,8}){1,2}(?:/|$)#', $afterBackend) === 1
            || \preg_match('#^/[A-Za-z]{2}(?:[_-][A-Za-z0-9]{2,8}){1,2}(?:/|$)#', $afterBackend) === 1
        ) {
            return $url;
        }

        $parts['path'] = $backendPrefix . $localePrefix . $afterBackend;

        return $this->buildUrlFromParts($parts);
    }

    /**
     * @param array<string,mixed> $parts
     */
    private function buildUrlFromParts(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? ((string)$parts['scheme'] . '://') : '';
        $user = (string)($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? (':' . (string)$parts['pass']) : '';
        $auth = $user !== '' ? ($user . $pass . '@') : '';
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? (':' . (string)$parts['port']) : '';
        $path = (string)($parts['path'] ?? '');
        $query = isset($parts['query']) ? ('?' . (string)$parts['query']) : '';
        $fragment = isset($parts['fragment']) ? ('#' . (string)$parts['fragment']) : '';

        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }

    private function isCurrencySegment(string $value): bool
    {
        return \preg_match('/^[A-Z]{3}$/', $value) === 1;
    }

    private function isLocaleSegment(string $value): bool
    {
        return \preg_match('/^[A-Za-z]{2}(?:[_-][A-Za-z0-9]{2,8}){1,2}$/', $value) === 1;
    }

    /**
     * Queue/CLI execution may not have the browser Host header. When URL
     * generation returns a pseudo host, preserve its path/query and restore the
     * known local preview origin from the current workspace state/profile.
     *
     * @param array<string,mixed> $urls
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function normalizeUrlsToLocalBase(array $urls, array $context): array
    {
        $base = $this->resolveExplicitPreviewOriginFromContext($context);
        if ($base === '') {
            $base = $this->resolveLocalPreviewOriginFromMixed($context);
        }
        if ($base === '') {
            return $urls;
        }
        $baseIsLocalPreviewOrigin = $this->isLocalPreviewUrl($base);

        foreach (['preview_full_url', 'visual_preview_url', 'visual_edit_url', 'virtual_preview_url', 'virtual_edit_url'] as $key) {
            $url = \trim((string)($urls[$key] ?? ''));
            if ($url === '' || ($baseIsLocalPreviewOrigin && $this->isLocalPreviewUrl($url))) {
                continue;
            }
            $rewritten = $this->rewriteUrlToOrigin($url, $base);
            if ($rewritten !== '') {
                $urls[$key] = $rewritten;
            }
        }

        return $urls;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function resolveExplicitPreviewOriginFromContext(array $context): string
    {
        foreach (['preview_origin', 'request_origin', 'backend_origin', 'current_origin', 'current_request_origin'] as $key) {
            $origin = $this->normalizeHttpOrigin((string)($context[$key] ?? ''));
            if ($origin !== '') {
                return $origin;
            }
        }

        return '';
    }

    public function resolveLocalPreviewOriginFromMixed(mixed $value, int $depth = 0): string
    {
        if ($depth > 8) {
            return '';
        }
        if (\is_string($value)) {
            if ($this->isLocalPreviewUrl($value)) {
                return $this->extractUrlOrigin($value);
            }

            return $this->resolveLocalPreviewOriginFromDomain($value);
        }
        if (!\is_array($value)) {
            return '';
        }

        foreach ([
            'preview_full_url',
            'visual_preview_url',
            'visual_edit_url',
            'virtual_preview_url',
            'virtual_edit_url',
            'preview_url',
            'target_domain',
            'selected_domain',
            'domain',
            'site_domain',
            'public_domain',
        ] as $key) {
            if (!\array_key_exists($key, $value)) {
                continue;
            }
            $origin = $this->resolveLocalPreviewOriginFromMixed($value[$key], $depth + 1);
            if ($origin !== '') {
                return $origin;
            }
        }
        foreach ($value as $item) {
            $origin = $this->resolveLocalPreviewOriginFromMixed($item, $depth + 1);
            if ($origin !== '') {
                return $origin;
            }
        }

        return '';
    }

    public function isLocalPreviewUrl(string $url): bool
    {
        $host = \parse_url($url, \PHP_URL_HOST);
        $host = \is_string($host) ? \strtolower(\trim($host)) : '';

        return $this->isLocalPreviewHost($host);
    }

    private function resolveLocalPreviewOriginFromDomain(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if (\preg_match('#^https?://#i', $value) === 1) {
            return $this->isLocalPreviewUrl($value) ? $this->extractUrlOrigin($value) : '';
        }

        $host = \parse_url('https://' . \ltrim($value, '/'), \PHP_URL_HOST);
        $host = \is_string($host) ? \strtolower(\trim($host)) : '';
        if (!$this->isLocalPreviewHost($host)) {
            return '';
        }

        return 'https://' . $host;
    }

    private function isLocalPreviewHost(string $host): bool
    {
        return $host !== ''
            && (\str_ends_with($host, '.weline.test') || \str_ends_with($host, '.local.test'));
    }

    private function extractUrlOrigin(string $url): string
    {
        $scheme = \parse_url($url, \PHP_URL_SCHEME);
        $host = \parse_url($url, \PHP_URL_HOST);
        if (!\is_string($scheme) || !\is_string($host) || \trim($host) === '') {
            return '';
        }
        $port = \parse_url($url, \PHP_URL_PORT);

        return \strtolower($scheme) . '://' . \strtolower($host) . (\is_int($port) && $port > 0 ? ':' . $port : '');
    }

    private function normalizeHttpOrigin(string $origin): string
    {
        $origin = \trim($origin);
        if ($origin === '' || \preg_match('#^https?://#i', $origin) !== 1) {
            return '';
        }
        $scheme = \parse_url($origin, \PHP_URL_SCHEME);
        $host = \parse_url($origin, \PHP_URL_HOST);
        if (!\is_string($scheme) || !\is_string($host) || \trim($host) === '') {
            return '';
        }
        $port = \parse_url($origin, \PHP_URL_PORT);

        return \strtolower($scheme) . '://' . \strtolower($host) . (\is_int($port) && $port > 0 ? ':' . $port : '');
    }

    private function rewriteUrlToOrigin(string $url, string $origin): string
    {
        $origin = \rtrim($origin, '/');
        if ($origin === '') {
            return '';
        }

        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) && \trim($path) !== '' ? $path : '/' . \ltrim($url, '/');
        $query = \parse_url($url, \PHP_URL_QUERY);

        return $origin . '/' . \ltrim($path, '/') . (\is_string($query) && $query !== '' ? '?' . $query : '');
    }
}
