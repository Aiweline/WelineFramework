<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Theme\Helper\ConfigLoader;
use Weline\Theme\Model\WelineTheme;

final class ThemePreviewEntryApplication
{
    public function __construct(
        private readonly ThemeContextService $themeContextService,
    ) {
    }

    /**
     * @return array{ok: true, redirect: string}|array{ok: false, message: string}
     */
    public function preparePreviewRedirect(
        int $themeId,
        string $area,
        AuthenticatedSessionInterface $session,
        bool $appendPreviewThemeQueryOnFrontendUrl = true,
        ?string $scopeQuery = null,
        ?string $pageType = null,
        ?int $versionId = null,
        string $status = 'draft',
        string $editorArea = 'frontend',
        string $previewMode = 'default',
        ?int $websiteId = null,
        ?string $websiteCode = null,
    ): array {
        if ($themeId <= 0) {
            return ['ok' => false, 'message' => __('请选择主题')];
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return ['ok' => false, 'message' => __('主题不存在')];
        }

        if (\in_array($area, ['frontend', 'backend'], true) && !$this->themeContextService->themeSupportsArea($theme, $area)) {
            return ['ok' => false, 'message' => __('主题不支持 %{1} 区域', [$area])];
        }

        $session->set('preview_theme_id', $themeId);
        $session->set('preview_theme_area', $area);

        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        /** @var PreviewContextService $previewContextService */
        $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
        /** @var PreviewTokenService $previewTokenService */
        $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);
        /** @var ThemePageTypeResolver $themePageTypeResolver */
        $themePageTypeResolver = ObjectManager::getInstance(ThemePageTypeResolver::class);

        $mode = \in_array($previewMode, ['live', 'version', 'default'], true) ? $previewMode : 'default';
        $requestedLayoutType = \trim((string)($pageType ?: 'homepage'));
        if ($requestedLayoutType === '') {
            $requestedLayoutType = 'homepage';
        }
        $layoutType = $themePageTypeResolver->resolveLayoutType(
            $requestedLayoutType,
            null,
            null,
            'homepage'
        );
        $resolvedPageType = $themePageTypeResolver->mapLayoutTypeToPageType($layoutType);
        $previewStatus = \in_array($status, ['draft', 'published'], true) ? $status : 'draft';
        $previewEditorArea = $editorArea === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
        $resolvedVersionId = null;
        if ($mode === 'version') {
            $resolvedVersionId = ($versionId !== null && $versionId > 0)
                ? $versionId
                : $this->resolvePreviewVersionId($themeId, $layoutType, $previewStatus);
        }

        $context = $previewContextService->buildContext([
            'frontend_theme_id' => $area === PreviewContextService::AREA_FRONTEND ? $themeId : 0,
            'backend_theme_id' => $area === PreviewContextService::AREA_BACKEND ? $themeId : 0,
            'editor_area' => $area === PreviewContextService::AREA_FRONTEND
                ? PreviewContextService::AREA_FRONTEND
                : $previewEditorArea,
            'shell' => $area === PreviewContextService::AREA_BACKEND
                ? PreviewContextService::SHELL_THEME_EDITOR
                : PreviewContextService::SHELL_PREVIEW,
            'preview_mode' => $mode === 'default' ? PreviewContextService::DEFAULT_PREVIEW_MODE : $mode,
            'status' => $previewStatus,
            'version_id' => $resolvedVersionId,
            'scope' => ($scopeQuery !== null && $scopeQuery !== '')
                ? \trim($scopeQuery)
                : PreviewContextService::DEFAULT_SCOPE,
            'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
            'target_value' => $layoutType,
        ], false);
        $context = $previewContextService->ensureThemeIds($context);
        $layoutOption = ConfigLoader::getLayoutConfigValue(
            $theme,
            $area,
            $layoutType,
            (string)($context['scope'] ?? PreviewContextService::DEFAULT_SCOPE)
        );
        $layoutOption = \trim($layoutOption) !== '' ? \trim($layoutOption) : 'default';
        $context['layout_option'] = $layoutOption;
        $context = $previewContextService->buildContext($context, false);

        if ($area === PreviewContextService::AREA_FRONTEND) {
            $tokenThemeId = $previewContextService->getThemeIdForArea(
                PreviewContextService::AREA_FRONTEND,
                $context,
                true
            );
            if ($tokenThemeId > 0) {
                try {
                    $previewToken = $previewTokenService->generateToken(
                        $tokenThemeId,
                        $layoutType,
                        $resolvedVersionId,
                        $context
                    );
                    $previewTokenService->setPreviewCookie($previewToken);
                    $context = $previewContextService->withPreviewToken($context, $previewToken);
                } catch (\Throwable) {
                }
            }
        }

        $previewContextService->persistContext($context);

        if ($area === PreviewContextService::AREA_BACKEND) {
            $params = $previewContextService->toQueryParams($context, $appendPreviewThemeQueryOnFrontendUrl);
            $params['theme_id'] = $previewContextService->getThemeIdForArea(
                PreviewContextService::AREA_BACKEND,
                $context,
                true
            );
            $params['editor_mode'] = '1';
            $params['shell'] = PreviewContextService::SHELL_THEME_EDITOR;
            $params['status'] = $previewStatus;
            $params['editor_area'] = PreviewContextService::AREA_BACKEND;
            $params['preview_area'] = PreviewContextService::AREA_BACKEND;
            $params['preview_mode'] = $context['preview_mode'];
            $params['_t'] = \time();
            if ($resolvedVersionId !== null && $resolvedVersionId > 0) {
                $params['version_id'] = $resolvedVersionId;
            }
            unset($params['layout_type'], $params['layout_option']);

            // Real admin homepage. Dashboard owns its layout; do not open a custom preview shell.
            return [
                'ok' => true,
                'redirect' => $url->getBackendUrl('weline_dashboard/backend/dashboard', $params),
            ];
        }

        $previewToken = \trim((string)($context['preview_token'] ?? ''));
        if ($previewToken === '') {
            return ['ok' => false, 'message' => __('Preview token is required')];
        }

        // "/" for homepage — getFrontendUrl('') reuses REQUEST_URI (query-bin).
        $frontendPath = $themePageTypeResolver->getFrontendUrlPathForPreview($resolvedPageType);
        $frontendBase = $this->normalizeStorefrontPreviewBaseUrl(
            $url->getFrontendUrl($frontendPath),
            $websiteId,
            $websiteCode,
        );

        $redirect = $previewTokenService->getPreviewUrl($frontendBase, $previewToken);
        $redirect = $this->appendWebsiteQueryParams($redirect, $websiteId, $websiteCode);

        return [
            'ok' => true,
            'redirect' => $redirect,
        ];
    }

    /**
     * Admin-context getFrontendUrl embeds backend mount; Host-only sites need their origin.
     */
    public function normalizeStorefrontPreviewBaseUrl(
        string $frontendBase,
        ?int $websiteId = null,
        ?string $websiteCode = null,
    ): string {
        $frontendBase = $this->stripBackendAreaMountFromUrl($frontendBase);

        return $this->rewriteFrontendBaseForWebsite($frontendBase, $websiteId, $websiteCode);
    }

    /**
     * Peel area_routes.backend prefix from an absolute URL path.
     * Admin getFrontendUrl() otherwise yields /{backendMount}/… which is not a storefront route.
     */
    private function stripBackendAreaMountFromUrl(string $absoluteUrl): string
    {
        $prefix = \trim((string)(\Weline\Framework\App\Env::getAreaRoutePrefix('backend') ?? ''), '/');
        if ($prefix === '') {
            return $absoluteUrl;
        }

        $parts = \parse_url($absoluteUrl);
        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $absoluteUrl;
        }

        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $mount = '/' . $prefix;
        $pathLower = \strtolower($path);
        $mountLower = \strtolower($mount);
        if ($pathLower !== $mountLower && !\str_starts_with($pathLower, $mountLower . '/')) {
            return $absoluteUrl;
        }

        $remainder = \substr($path, \strlen($mount));
        if ($remainder === false || $remainder === '') {
            $remainder = '/';
        }
        if ($remainder[0] !== '/') {
            $remainder = '/' . $remainder;
        }

        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $query = !empty($parts['query']) ? '?' . (string)$parts['query'] : '';
        $fragment = !empty($parts['fragment']) ? '#' . (string)$parts['fragment'] : '';

        return \strtolower((string)$parts['scheme']) . '://' . \strtolower((string)$parts['host'])
            . $port . $remainder . $query . $fragment;
    }

    /**
     * Host-only websites must preview on their primary origin, not the admin Host.
     * Path must already be storefront-relative (backend mount stripped).
     */
    private function rewriteFrontendBaseForWebsite(
        string $frontendBase,
        ?int $websiteId,
        ?string $websiteCode,
    ): string {
        $code = \strtolower(\trim((string)$websiteCode));
        $id = $websiteId !== null ? (int)$websiteId : -1;
        if ($id < 0 && $code === '') {
            return $frontendBase;
        }
        if ($id < 0) {
            $id = 0;
        }

        try {
            /** @var InstallLocalStorefrontBaseResolver $resolver */
            $resolver = ObjectManager::getInstance(InstallLocalStorefrontBaseResolver::class);
            $origin = \trim((string)($resolver->resolveForWebsite($id, $code) ?? ''));
        } catch (\Throwable) {
            return $frontendBase;
        }
        if ($origin === '') {
            return $frontendBase;
        }

        $parts = \parse_url($frontendBase);
        $path = \is_array($parts) && isset($parts['path']) ? (string)$parts['path'] : '/';
        if ($path === '') {
            $path = '/';
        }
        // Install base may already include site mount (/daocharms); keep only the
        // storefront route after the mount when rewriting Host.
        $originParts = \parse_url($origin);
        $mount = \is_array($originParts) && isset($originParts['path'])
            ? \rtrim((string)$originParts['path'], '/')
            : '';
        if ($mount !== '' && $mount !== '/') {
            $mountLower = \strtolower($mount);
            $pathLower = \strtolower($path);
            if ($pathLower === $mountLower || \str_starts_with($pathLower, $mountLower . '/')) {
                $path = \substr($path, \strlen($mount));
                if ($path === false || $path === '') {
                    $path = '/';
                }
                if ($path[0] !== '/') {
                    $path = '/' . $path;
                }
            }
        }
        $query = \is_array($parts) && !empty($parts['query']) ? '?' . (string)$parts['query'] : '';
        $fragment = \is_array($parts) && !empty($parts['fragment']) ? '#' . (string)$parts['fragment'] : '';

        return \rtrim($origin, '/') . ($path === '/' ? '/' : $path) . $query . $fragment;
    }

    private function appendWebsiteQueryParams(
        string $url,
        ?int $websiteId,
        ?string $websiteCode,
    ): string {
        $code = \strtolower(\trim((string)$websiteCode));
        $id = $websiteId !== null ? (int)$websiteId : -1;
        if ($id < 0 && $code === '') {
            return $url;
        }

        $separator = \str_contains($url, '?') ? '&' : '?';
        $params = [];
        if ($id >= 0) {
            $params['website_id'] = (string)$id;
        }
        if ($code !== '') {
            $params['website_code'] = $code;
            $params['scope_kind'] = 'website';
        }

        return $url . $separator . \http_build_query($params);
    }

    private function resolvePreviewVersionId(int $themeId, string $pageType, string $status): ?int
    {
        try {
            /** @var ThemeLayoutVersionService $versionService */
            $versionService = ObjectManager::getInstance(ThemeLayoutVersionService::class);
            if ($status === 'published') {
                $published = $versionService->getPublishedVersion($themeId, $pageType);
                return $published?->getVersionId() ?: null;
            }
            $current = $versionService->getCurrentVersion($themeId, $pageType);
            return $current?->getVersionId() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
