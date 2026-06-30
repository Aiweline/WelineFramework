<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\ConfigLoader;
use Weline\Theme\Helper\PreviewAccountManager;
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
        mixed $autoLogin,
        AuthenticatedSessionInterface $session,
        bool $appendPreviewThemeQueryOnFrontendUrl = true,
        ?string $scopeQuery = null,
        ?string $pageType = null,
        ?int $versionId = null,
        string $status = 'draft',
        string $editorArea = 'frontend',
        string $previewMode = 'default',
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

        $shouldAutoLogin = false;
        if ($area === PreviewContextService::AREA_FRONTEND) {
            if ($autoLogin !== null && $autoLogin !== '') {
                $shouldAutoLogin = ($autoLogin === '1' || $autoLogin === 1 || $autoLogin === true);
            } else {
                $shouldAutoLogin = $this->shouldAutoLoginByLayout($theme, $area);
            }
        }

        $session->set('preview_auto_login', $shouldAutoLogin);
        if ($shouldAutoLogin) {
            PreviewAccountManager::ensurePreviewUser($theme);
        }

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
            $params['layout_type'] = $layoutType;
            $params['layout_option'] = $layoutOption;
            $params['editor_mode'] = '1';
            $params['status'] = $previewStatus;
            $params['editor_area'] = PreviewContextService::AREA_BACKEND;
            $params['preview_mode'] = $context['preview_mode'];
            $params['_t'] = \time();
            if ($resolvedVersionId !== null && $resolvedVersionId > 0) {
                $params['version_id'] = $resolvedVersionId;
            }

            return [
                'ok' => true,
                'redirect' => $url->getBackendUrl('theme/backend/theme-editor/layout-preview', $params),
            ];
        }

        $params = $previewContextService->toQueryParams($context, $appendPreviewThemeQueryOnFrontendUrl);
        $params['page_type'] = $layoutType;
        $params['layout_type'] = $layoutType;
        $params['layout_option'] = $layoutOption;
        $params['_t'] = \time();

        return [
            'ok' => true,
            'redirect' => $url->getFrontendUrl(
                'theme/frontend/theme-preview/content',
                $params
            ),
        ];
    }

    private function shouldAutoLoginByLayout(WelineTheme $theme, string $area): bool
    {
        try {
            $layoutConfig = ConfigLoader::getLayoutConfig($theme, $area);
            $layoutType = 'default';
            $layoutOption = $layoutConfig[$layoutType] ?? 'default';

            $themePath = $theme->getPath();
            if ($themePath === '') {
                return false;
            }

            $layoutPath = \rtrim($themePath, \DS) . \DS . 'view' . \DS . 'theme' . \DS . $area . \DS . 'layouts' . \DS . $layoutType . \DS . $layoutOption . '.phtml';
            $layoutPath = \str_replace('\\', \DS, $layoutPath);

            if (!\is_file($layoutPath)) {
                $parentId = $theme->getParentId();
                if ($parentId) {
                    /** @var WelineTheme $parentTheme */
                    $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                    $parentTheme->load($parentId);
                    if ($parentTheme->getId()) {
                        return $this->shouldAutoLoginByLayout($parentTheme, $area);
                    }
                }

                return false;
            }

            $meta = ComponentMetaParser::parse($layoutPath);

            return isset($meta['preview_login']) && $meta['preview_login'] == 1;
        } catch (\Throwable) {
            return false;
        }
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
