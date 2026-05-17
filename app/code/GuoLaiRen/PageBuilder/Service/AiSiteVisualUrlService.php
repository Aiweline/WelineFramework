<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

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

        return [
            'preview_full_url' => $this->url->getBackendUrl('pagebuilder/backend/preview/full', $previewParams),
            'visual_preview_url' => $this->url->getBackendUrl('pagebuilder/backend/preview/full', $visualPreviewParams),
            'visual_edit_url' => $this->url->getBackendUrl('pagebuilder/backend/page/edit', $visualEditParams),
        ];
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

        return [
            'preview_full_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace-preview', $previewParams),
            'visual_preview_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace-preview', $visualPreviewParams),
            'visual_edit_url' => $this->url->getBackendUrl('pagebuilder/backend/page/virtual-edit', $visualEditParams),
        ];
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
        $base = $this->resolveLocalPreviewOriginFromMixed($context);
        if ($base === '') {
            return $urls;
        }

        foreach (['preview_full_url', 'visual_preview_url', 'visual_edit_url', 'virtual_preview_url', 'virtual_edit_url'] as $key) {
            $url = \trim((string)($urls[$key] ?? ''));
            if ($url === '' || $this->isLocalPreviewUrl($url)) {
                continue;
            }
            $rewritten = $this->rewriteUrlToOrigin($url, $base);
            if ($rewritten !== '') {
                $urls[$key] = $rewritten;
            }
        }

        return $urls;
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
