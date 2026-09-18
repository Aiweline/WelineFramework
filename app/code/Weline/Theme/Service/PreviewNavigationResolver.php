<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\LayoutResolveService;

final class PreviewNavigationResolver
{
    public function __construct(
        private readonly Request $request,
        private readonly Url $url,
        private readonly PreviewContextService $previewContextService,
        private readonly PreviewTokenService $previewTokenService,
        private readonly ThemePageTypeResolver $themePageTypeResolver,
    ) {
    }

    public function resolve(array $context, string $href): array
    {
        $context = $this->previewContextService->ensureThemeIds(
            $this->previewContextService->buildContext($context)
        );
        $candidate = $this->normalizeCandidate($href);

        if (!$candidate['internal']) {
            return $this->buildResponse(
                'external',
                $candidate['absolute_url'],
                $context['target_type'],
                (string)$context['target_value'],
                '',
                0,
                $context
            );
        }

        $page = $this->resolvePage($candidate);
        $currentShell = $context['shell'] ?? PreviewContextService::SHELL_THEME_EDITOR;

        if ($currentShell === PreviewContextService::SHELL_THEME_EDITOR) {
            if ($page?->getId()) {
                return $this->buildThemeEditorPageResult($page, $context);
            }

            return $this->buildThemeEditorLayoutResult($candidate, $context);
        }

        return $this->buildPreviewResult($candidate, $context, $page);
    }

    private function buildThemeEditorPageResult(object $page, array $context): array
    {
        $pageId = (int)$page->getId();
        $responseContext = \array_replace($context, [
            'shell' => PreviewContextService::SHELL_THEME_EDITOR,
            'target_type' => PreviewContextService::TARGET_TYPE_PAGE,
            'target_value' => (string)$pageId,
        ]);

        $params = $this->previewContextService->toQueryParams($responseContext);
        $params['id'] = $pageId;
        $params['page_id'] = $pageId;
        $params['weline_theme_id'] = $this->previewContextService->getThemeIdForArea(
            PreviewContextService::AREA_FRONTEND,
            $responseContext,
            true
        );

        return $this->buildResponse(
            'internal-editor',
            $this->url->getBackendUrl('admin/system/cms', $params),
            PreviewContextService::TARGET_TYPE_PAGE,
            (string)$pageId,
            (string)$page->getData('type'),
            $pageId,
            $responseContext
        );
    }

    private function buildThemeEditorLayoutResult(array $candidate, array $context): array
    {
        // Keep the clicked storefront path as-is (do not rewrite via layout_path).
        // Locale/website mount may be stripped only for layout chrome inference.
        $clickedPath = \trim((string)($candidate['path'] ?? ''), '/');
        $publicRoute = $this->normalizeStorefrontPublicRoute((string)($candidate['path'] ?? ''));
        // Never invent a different hub (e.g. layout_path) — path stays what was clicked.
        // Do not fall back to markup/unsafe clicked paths (broken href="<div…").
        if ($publicRoute === '') {
            $fallback = \strtolower($clickedPath);
            if ($fallback !== ''
                && $this->themePageTypeResolver->isSafeStorefrontPublicRoute($fallback)
                && !$this->themePageTypeResolver->isNonStorefrontPublicRoute($fallback)
            ) {
                $publicRoute = $fallback;
            }
        }

        $resolved = $this->resolveLayoutFromPublicPath($publicRoute);
        $pageType = (string)($resolved['layout_path'] ?? '');
        if ($pageType === '') {
            $pageType = $this->resolveThemePageType('/' . ($publicRoute !== '' ? $publicRoute : $clickedPath));
        }
        if ($pageType === '') {
            $pageType = $publicRoute !== '' ? \explode('/', $publicRoute)[0] : ThemeLayout::PAGE_TYPE_HOME;
        }

        $layoutOption = (string)($resolved['layout_option'] ?? 'default');
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }

        $editorArea = $this->previewContextService->normalizeArea(
            (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND)
        );
        $themeId = $this->previewContextService->getThemeIdForArea($editorArea, $context, true);
        $responseContext = \array_replace($context, [
            'shell' => PreviewContextService::SHELL_THEME_EDITOR,
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $pageType,
            'layout_option' => $layoutOption,
            // Authoritative canvas path = clicked path (normalized), not rebuilt from layout_path.
        ]);

        // Shell URL is only a chrome hint; canvas loads public_route + editor markers.
        $params = $this->previewContextService->toQueryParams($responseContext);
        $params['theme_id'] = $themeId;
        $params['editor_mode'] = '1';
        $params['status'] = $responseContext['status'];
        $params['editor_area'] = $editorArea;
        $params['preview_mode'] = $responseContext['preview_mode'];
        unset($params['layout_type'], $params['page_type'], $params['layout_option']);
        if ($pageType !== '' && $pageType !== ThemeLayout::PAGE_TYPE_HOME) {
            $params['page_type'] = $pageType;
        }
        if ($layoutOption !== 'default') {
            $params['layout_option'] = $layoutOption;
        }

        $response = $this->buildResponse(
            'internal-editor',
            $this->url->getBackendUrl('theme/backend/theme-editor', $params),
            PreviewContextService::TARGET_TYPE_LAYOUT,
            $pageType,
            $pageType,
            0,
            $responseContext
        );
        // Exact path for canvas — editor must not substitute another path.
        $response['public_route'] = $publicRoute;
        $response['layout_option'] = $layoutOption;
        $response['canvas_navigation'] = true;
        $response['preserve_path'] = true;

        return $response;
    }

    /**
     * Strip website mount + locale prefix so /en_US/products → products.
     */
    private function normalizeStorefrontPublicRoute(string $path): string
    {
        $path = \trim(\str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (\str_contains($path, '://')) {
            $path = (string)(\parse_url($path, \PHP_URL_PATH) ?: '');
        }
        if (\str_contains($path, '?')) {
            $path = \explode('?', $path, 2)[0];
        }
        $path = '/' . \trim($path, '/');

        try {
            $path = Url::peelWebsiteMountPathFromRelativePath($path);
        } catch (\Throwable) {
        }

        $segments = \array_values(\array_filter(
            \explode('/', \trim($path, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        try {
            $localized = \Weline\Framework\App\State::resolveLocalizationFromPathSegments($segments);
            if (isset($localized['remaining']) && \is_array($localized['remaining'])) {
                $segments = \array_values(\array_map('strval', $localized['remaining']));
            }
        } catch (\Throwable) {
        }

        $normalized = \strtolower(\implode('/', $segments));
        if ($normalized === '' || !$this->themePageTypeResolver->isSafeStorefrontPublicRoute($normalized)) {
            return '';
        }

        return $normalized;
    }

    /**
     * @return array{claimed?:bool,layout_path?:string,layout_option?:string,entity_slug?:string}
     */
    private function resolveLayoutFromPublicPath(string $publicRoute): array
    {
        if ($publicRoute === '') {
            return [
                'claimed' => true,
                'layout_path' => ThemeLayout::PAGE_TYPE_HOME,
                'layout_option' => 'default',
                'entity_slug' => '',
            ];
        }

        try {
            /** @var LayoutResolveService $layoutResolve */
            $layoutResolve = ObjectManager::getInstance(LayoutResolveService::class);

            return $layoutResolve->resolveFromPath($publicRoute);
        } catch (\Throwable) {
            return [];
        }
    }

    private function buildPreviewResult(array $candidate, array $context, ?object $page): array
    {
        $responseContext = $context;
        $responseContext['shell'] = PreviewContextService::SHELL_PREVIEW;

        $pageId = 0;
        $pageType = $this->resolveThemePageType($candidate['path']);
        if ($page?->getId()) {
            $pageId = (int)$page->getId();
            $pageType = (string)($page->getData('type') ?: $pageType);
            $responseContext['target_type'] = PreviewContextService::TARGET_TYPE_PAGE;
            $responseContext['target_value'] = (string)$pageId;
        } else {
            $responseContext['target_type'] = PreviewContextService::TARGET_TYPE_LAYOUT;
            $responseContext['target_value'] = $pageType;
        }

        $responseContext = $this->ensurePreviewToken($responseContext, $pageType);
        $previewUrl = $this->appendPreviewContextToUrl($candidate['absolute_url'], $responseContext);

        return $this->buildResponse(
            'internal-preview',
            $previewUrl,
            (string)$responseContext['target_type'],
            (string)$responseContext['target_value'],
            $pageType,
            $pageId,
            $responseContext
        );
    }

    private function ensurePreviewToken(array $context, string $pageType): array
    {
        $context = $this->previewContextService->ensureThemeIds($context);
        $token = \trim((string)($context['preview_token'] ?? ''));
        if ($token !== '') {
            return $context;
        }

        $themeId = $this->previewContextService->getThemeIdForArea(
            PreviewContextService::AREA_FRONTEND,
            $context,
            true
        );
        if ($themeId <= 0) {
            return $context;
        }

        $token = $this->previewTokenService->generateToken(
            $themeId,
            $pageType,
            !empty($context['version_id']) ? (int)$context['version_id'] : null,
            $context
        );

        return $this->previewContextService->withPreviewToken($context, $token);
    }

    private function appendPreviewContextToUrl(string $absoluteUrl, array $context): string
    {
        $parts = \parse_url($absoluteUrl);
        if (!\is_array($parts)) {
            return $absoluteUrl;
        }

        $existingQuery = [];
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $existingQuery);
        }

        $previewToken = \trim((string)($context['preview_token'] ?? ''));
        $shell = (string)($context['shell'] ?? PreviewContextService::SHELL_PREVIEW);
        $path = \strtolower((string)($parts['path'] ?? '/'));
        $isLiveStorefrontPreview = $shell === PreviewContextService::SHELL_PREVIEW
            && !\str_contains($path, '/theme/frontend/theme-preview/');

        if ($isLiveStorefrontPreview && $previewToken !== '') {
            $query = $existingQuery;
            $query[PreviewTokenService::TOKEN_KEY] = $previewToken;
        } else {
            $query = \array_replace($existingQuery, $this->previewContextService->toQueryParams($context));
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user = (string)($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $path = (string)($parts['path'] ?? '/');
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $queryString = \http_build_query($query);

        return $scheme . $auth . $host . $port . $path . ($queryString !== '' ? '?' . $queryString : '') . $fragment;
    }

    private function normalizeCandidate(string $href): array
    {
        $href = \trim($href);
        if ($href === '') {
            return [
                'internal' => false,
                'absolute_url' => '',
                'path' => '/',
                'query' => [],
            ];
        }

        $baseHost = \rtrim((string)$this->request->getBaseHost(), '/');
        if (\preg_match('#^(mailto:|tel:|javascript:)#i', $href)) {
            return [
                'internal' => false,
                'absolute_url' => $href,
                'path' => '/',
                'query' => [],
            ];
        }

        $absoluteUrl = $href;
        if (!\preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
            if (\str_starts_with($href, '//')) {
                $scheme = (string)\parse_url($baseHost, \PHP_URL_SCHEME);
                $absoluteUrl = ($scheme !== '' ? $scheme . ':' : 'http:') . $href;
            } else {
                $absoluteUrl = $this->joinUrl($baseHost, $href);
            }
        }

        $parts = \parse_url($absoluteUrl);
        if (!\is_array($parts)) {
            return [
                'internal' => false,
                'absolute_url' => $absoluteUrl,
                'path' => '/',
                'query' => [],
            ];
        }

        $query = [];
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $query);
        }

        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        return [
            'internal' => $this->normalizeOrigin($absoluteUrl) === $this->normalizeOrigin($baseHost),
            'absolute_url' => $absoluteUrl,
            'path' => $path,
            'query' => $query,
        ];
    }

    private function resolvePage(array $candidate): ?object
    {
        return null;
    }

    private function extractHandle(array $candidate): ?string
    {
        $path = \trim((string)($candidate['path'] ?? '/'));
        $normalizedPath = \trim($path, '/');
        $query = $candidate['query'] ?? [];

        if ($normalizedPath === '' || $normalizedPath === 'index' || $normalizedPath === 'index/index') {
            return '';
        }

        if (\preg_match('#\.[a-z0-9]+$#i', $normalizedPath)) {
            return null;
        }

        return $normalizedPath;
    }

    private function resolveThemePageType(string $path): string
    {
        $pageType = $this->themePageTypeResolver->resolvePageTypeFromUri($path, ThemeLayout::PAGE_TYPE_CMS);
        return $pageType !== '' ? $pageType : ThemeLayout::PAGE_TYPE_CMS;
    }

    private function buildResponse(
        string $kind,
        string $targetUrl,
        string $targetType,
        string $targetValue,
        string $pageType,
        int $pageId,
        array $context
    ): array {
        return [
            'kind' => $kind,
            'target_url' => $targetUrl,
            'target_type' => $targetType,
            'target_value' => $targetValue,
            'page_type' => $pageType,
            'page_id' => $pageId,
            'context' => $this->previewContextService->buildContext($context),
        ];
    }

    private function normalizeOrigin(string $url): string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return '';
        }

        $scheme = \strtolower((string)($parts['scheme'] ?? 'http'));
        $host = \strtolower((string)($parts['host'] ?? ''));
        $port = (int)($parts['port'] ?? 0);
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = 0;
        }

        return $scheme . '://' . $host . ($port > 0 ? ':' . $port : '');
    }

    private function joinUrl(string $baseHost, string $href): string
    {
        if (\str_starts_with($href, '/')) {
            return $baseHost . $href;
        }

        $rawPath = (string)\parse_url((string) (\w_env('request.uri', '/') ?? '/'), \PHP_URL_PATH);
        $path = \str_replace('\\', '/', $rawPath);
        if ($path === '') {
            $path = '/';
        } elseif ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = \rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $parent = \str_replace('\\', '/', \dirname($path));
        if ($parent === '.' || $parent === '') {
            $parent = '/';
        }

        $rel = \ltrim(\str_replace('\\', '/', $href), '/');
        if ($parent === '/') {
            return \rtrim($baseHost, '/') . '/' . $rel;
        }

        return \rtrim($baseHost, '/') . $parent . '/' . $rel;
    }
}
