<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Theme\Model\ThemeLayout;

final class PreviewNavigationResolver
{
    public function __construct(
        private readonly Request $request,
        private readonly Url $url,
        private readonly PreviewContextService $previewContextService,
        private readonly PreviewTokenService $previewTokenService,
        private readonly ThemePageTypeResolver $themePageTypeResolver,
        private readonly Page $pageModel,
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

        if ($currentShell === PreviewContextService::SHELL_PAGEBUILDER) {
            if ($page?->getId()) {
                return $this->buildPageBuilderEditorResult($page, $context);
            }

            return $this->buildPreviewResult($candidate, $context, null);
        }

        return $this->buildPreviewResult($candidate, $context, $page);
    }

    private function buildThemeEditorPageResult(Page $page, array $context): array
    {
        $pageId = (int)$page->getId();
        $responseContext = \array_replace($context, [
            'shell' => PreviewContextService::SHELL_PAGEBUILDER,
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
            $this->url->getBackendUrl('pagebuilder/backend/page/edit', $params),
            PreviewContextService::TARGET_TYPE_PAGE,
            (string)$pageId,
            (string)$page->getData(Page::schema_fields_TYPE),
            $pageId,
            $responseContext
        );
    }

    private function buildThemeEditorLayoutResult(array $candidate, array $context): array
    {
        $pageType = $this->resolveThemePageType($candidate['path']);
        $editorArea = $this->previewContextService->normalizeArea(
            (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND)
        );
        $themeId = $this->previewContextService->getThemeIdForArea($editorArea, $context, true);
        $responseContext = \array_replace($context, [
            'shell' => PreviewContextService::SHELL_THEME_EDITOR,
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $pageType,
        ]);

        $params = $this->previewContextService->toQueryParams($responseContext);
        $params['theme_id'] = $themeId;
        $params['layout_type'] = $pageType;
        $params['layout_option'] = 'default';
        $params['editor_mode'] = '1';
        $params['status'] = $responseContext['status'];
        $params['editor_area'] = $editorArea;
        $params['preview_mode'] = $responseContext['preview_mode'];

        return $this->buildResponse(
            'internal-editor',
            $this->url->getBackendUrl('theme/backend/theme-editor/layout-preview', $params),
            PreviewContextService::TARGET_TYPE_LAYOUT,
            $pageType,
            $pageType,
            0,
            $responseContext
        );
    }

    private function buildPageBuilderEditorResult(Page $page, array $context): array
    {
        $pageId = (int)$page->getId();
        $responseContext = \array_replace($context, [
            'shell' => PreviewContextService::SHELL_PAGEBUILDER,
            'target_type' => PreviewContextService::TARGET_TYPE_PAGE,
            'target_value' => (string)$pageId,
        ]);

        $params = $this->previewContextService->toQueryParams($responseContext);
        $params['page_id'] = $pageId;
        $params['visual_editor'] = '1';
        $params['weline_theme_id'] = $this->previewContextService->getThemeIdForArea(
            PreviewContextService::AREA_FRONTEND,
            $responseContext,
            true
        );

        $locale = $this->resolveLocale();
        if ($locale !== '') {
            $params['locale'] = $locale;
        }

        return $this->buildResponse(
            'internal-editor',
            $this->url->getBackendUrl('pagebuilder/backend/preview/full', $params),
            PreviewContextService::TARGET_TYPE_PAGE,
            (string)$pageId,
            (string)$page->getData(Page::schema_fields_TYPE),
            $pageId,
            $responseContext
        );
    }

    private function buildPreviewResult(array $candidate, array $context, ?Page $page): array
    {
        $responseContext = $context;
        $responseContext['shell'] = PreviewContextService::SHELL_PREVIEW;

        $pageId = 0;
        $pageType = $this->resolveThemePageType($candidate['path']);
        if ($page?->getId()) {
            $pageId = (int)$page->getId();
            $pageType = (string)($page->getData(Page::schema_fields_TYPE) ?: $pageType);
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
        $query = \array_replace($existingQuery, $this->previewContextService->toQueryParams($context));

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

    private function resolvePage(array $candidate): ?Page
    {
        $pageId = (int)($candidate['query']['page_id'] ?? 0);
        if ($pageId > 0) {
            $page = clone $this->pageModel;
            $page->clear()->load($pageId);
            return $page->getId() ? $page : null;
        }

        $handle = $this->extractHandle($candidate);
        if ($handle === null) {
            return null;
        }

        $websiteId = $this->detectCurrentWebsiteId();
        if ($handle === '') {
            $page = clone $this->pageModel;
            if ($websiteId > 0) {
                $page->clear()
                    ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(Page::schema_fields_TYPE, Page::TYPE_HOME)
                    ->find()
                    ->fetch();
                if ($page->getId()) {
                    return $page;
                }
            }

            $page->clear()
                ->where(Page::schema_fields_TYPE, Page::TYPE_HOME)
                ->find()
                ->fetch();
            return $page->getId() ? $page : null;
        }

        $page = clone $this->pageModel;
        if ($websiteId > 0) {
            $page->clear()
                ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
                ->where(Page::schema_fields_HANDLE, $handle)
                ->find()
                ->fetch();
            if ($page->getId()) {
                return $page;
            }
        }

        $page->clear()
            ->where(Page::schema_fields_HANDLE, $handle)
            ->find()
            ->fetch();

        return $page->getId() ? $page : null;
    }

    private function extractHandle(array $candidate): ?string
    {
        $path = \trim((string)($candidate['path'] ?? '/'));
        $normalizedPath = \trim($path, '/');
        $query = $candidate['query'] ?? [];

        if ($normalizedPath === '' || $normalizedPath === 'index' || $normalizedPath === 'index/index') {
            return '';
        }

        if (\str_contains($normalizedPath, 'pagebuilder/frontend/page/view')) {
            $handle = \trim((string)($query['handle'] ?? ''));
            return $handle !== '' ? $handle : null;
        }

        if (\preg_match('#\.[a-z0-9]+$#i', $normalizedPath)) {
            return null;
        }

        return $normalizedPath;
    }

    private function detectCurrentWebsiteId(): int
    {
        try {
            if (\class_exists(\Weline\UrlManager\Model\UrlRewrite::class)) {
                return (int)\Weline\UrlManager\Model\UrlRewrite::getCurrentWebsiteId();
            }
        } catch (\Throwable) {
        }

        return (int)($_SERVER['WELINE_WEBSITE_ID'] ?? 0);
    }

    private function resolveThemePageType(string $path): string
    {
        $pageType = $this->themePageTypeResolver->resolvePageTypeFromUri($path, ThemeLayout::PAGE_TYPE_HOME);
        return $pageType !== '' ? $pageType : ThemeLayout::PAGE_TYPE_HOME;
    }

    private function resolveLocale(): string
    {
        $locale = \trim((string)$this->request->getParam('locale', ''));
        if ($locale !== '') {
            return $locale;
        }

        return \trim((string)$this->request->getParam('lang', ''));
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

        $basePath = (string)\parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);
        $basePath = \preg_replace('#/[^/]*$#', '/', $basePath ?: '/');
        $basePath = $basePath ?: '/';

        return $baseHost . '/' . \ltrim($basePath . $href, '/');
    }
}
