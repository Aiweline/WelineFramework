<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\Page;

final class AiSitePreviewLinkRewriteService
{
    public function __construct(
        private readonly AiSiteVisualUrlService $visualUrlService,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     */
    public function rewriteMaterializedPreviewLinks(string $html, array $pages, int $virtualThemeId = 0): string
    {
        $previewByPath = [];
        foreach ($pages as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageId = (int)($page['page_id'] ?? $page['id'] ?? 0);
            if ($pageId <= 0) {
                continue;
            }

            $urls = $this->visualUrlService->resolveUrls($pageId, $virtualThemeId);
            $previewUrl = \trim((string)($urls['preview_full_url'] ?? ''));
            if ($previewUrl === '') {
                continue;
            }

            foreach ($this->resolvePagePaths($page) as $path) {
                $previewByPath[$path] = $previewUrl;
            }
        }

        return $this->rewriteAnchors($html, $previewByPath);
    }

    /**
     * @param array<string, array<string, mixed>> $virtualPages
     */
    public function rewriteVirtualPreviewLinks(
        string $html,
        string $publicId,
        array $virtualPages,
        int $virtualThemeId = 0
    ): string {
        $publicId = \trim($publicId);
        if ($publicId === '') {
            return $html;
        }

        $previewByPath = [];
        foreach ($virtualPages as $pageType => $pageData) {
            if (!\is_string($pageType) || !\is_array($pageData)) {
                continue;
            }

            $urls = $this->visualUrlService->resolveVirtualUrls($publicId, $pageType, $virtualThemeId);
            $previewUrl = \trim((string)($urls['preview_full_url'] ?? ''));
            if ($previewUrl === '') {
                continue;
            }

            $handle = \trim((string)($pageData['handle'] ?? ''));
            $path = ($pageType === Page::TYPE_HOME || $handle === '') ? '/' : '/' . \ltrim($handle, '/');
            $normalized = $this->normalizePath($path);
            if ($normalized !== '') {
                $previewByPath[$normalized] = $previewUrl;
            }
        }

        return $this->rewriteAnchors($html, $previewByPath);
    }

    /**
     * @param array<string, mixed> $page
     * @return list<string>
     */
    private function resolvePagePaths(array $page): array
    {
        $paths = [];
        $type = (string)($page['type'] ?? '');
        $url = \trim((string)($page['url'] ?? ''));
        $handle = \trim((string)($page['handle'] ?? ''));

        if ($type === Page::TYPE_HOME) {
            $paths['/'] = '/';
        }
        if ($url !== '') {
            $normalized = $this->normalizePath($url);
            if ($normalized !== '') {
                $paths[$normalized] = $normalized;
            }
        }
        if ($handle !== '') {
            $normalized = $this->normalizePath('/' . \ltrim($handle, '/'));
            if ($normalized !== '') {
                $paths[$normalized] = $normalized;
            }
        }

        return \array_values($paths);
    }

    /**
     * @param array<string, string> $previewByPath
     */
    private function rewriteAnchors(string $html, array $previewByPath): string
    {
        if ($html === '' || $previewByPath === []) {
            return $html;
        }

        $rewritten = \preg_replace_callback(
            '/<a\b([^>]*?)\bhref\s*=\s*(["\'])(.*?)\2([^>]*)>/isu',
            function (array $matches) use ($previewByPath): string {
                $tag = (string)$matches[0];
                if (\stripos($tag, 'data-glr-ref=') !== false) {
                    return $tag;
                }

                $rawHref = \html_entity_decode((string)$matches[3], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
                $resolution = $this->resolvePreviewHref($rawHref, $previewByPath);
                if ($resolution === null) {
                    return $tag;
                }

                $quote = (string)$matches[2];
                $newHref = \htmlspecialchars($resolution['href'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
                $extra = '';
                if (!\preg_match('/\sdata-preview-original-href\s*=/iu', $tag)) {
                    $extra .= ' data-preview-original-href="'
                        . \htmlspecialchars($rawHref, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                        . '"';
                }
                if ($resolution['unresolved'] && !\preg_match('/\sdata-preview-unresolved-href\s*=/iu', $tag)) {
                    $extra .= ' data-preview-unresolved-href="'
                        . \htmlspecialchars($rawHref, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                        . '"';
                }

                return '<a' . (string)$matches[1] . 'href=' . $quote . $newHref . $quote . $extra . (string)$matches[4] . '>';
            },
            $html
        );

        return \is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * @param array<string, string> $previewByPath
     * @return array{href:string,unresolved:bool}|null
     */
    private function resolvePreviewHref(string $href, array $previewByPath): ?array
    {
        $href = \trim($href);
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        if (\preg_match('/^(?:javascript|mailto|tel|sms|data|blob):/iu', $href)) {
            return null;
        }
        if (\str_starts_with($href, '//')) {
            return null;
        }
        if (\stripos($href, '/pagebuilder/backend/preview/full') !== false
            || \stripos($href, '/pagebuilder/backend/ai-site-agent/workspace-preview') !== false) {
            return null;
        }

        $parts = \parse_url($href);
        if (!\is_array($parts)) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        $path = \trim((string)($parts['path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath === '') {
            return null;
        }

        if (isset($previewByPath[$normalizedPath])) {
            return [
                'href' => $this->appendFragment($previewByPath[$normalizedPath], (string)($parts['fragment'] ?? '')),
                'unresolved' => false,
            ];
        }

        if ($this->shouldDisableUnresolvedInternalPath($normalizedPath)) {
            return ['href' => '#', 'unresolved' => true];
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        $path = \trim(\rawurldecode($path));
        if ($path === '') {
            return '';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = '/' . \trim($path, '/');
        return $path === '/' ? '/' : \rtrim($path, '/');
    }

    private function appendFragment(string $url, string $fragment): string
    {
        $fragment = \trim($fragment);
        if ($fragment === '') {
            return $url;
        }

        return $url . '#' . $fragment;
    }

    private function shouldDisableUnresolvedInternalPath(string $path): bool
    {
        if ($path === '/' || \str_contains($path, '..')) {
            return false;
        }

        foreach (['/pagebuilder/', '/media/', '/static/', '/assets/', '/uploads/', '/pub/', '/admin/', '/api/'] as $prefix) {
            if (\str_starts_with($path . '/', $prefix)) {
                return false;
            }
        }

        $basename = \basename($path);
        return !\preg_match('/\.[a-z0-9]{2,8}$/iu', $basename);
    }
}
