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
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeLayoutVersionService;

/**
 * 主题预览入口：写入 Session 并生成重定向 URL（后台预览页与前台网关共用）
 */
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

        if (in_array($area, ['frontend', 'backend'], true) && !$this->themeContextService->themeSupportsArea($theme, $area)) {
            return ['ok' => false, 'message' => __('主题不支持 %{1} 区域', [$area])];
        }

        $session->set('preview_theme_id', $themeId);
        $session->set('preview_theme_area', $area);

        $shouldAutoLogin = false;
        if ($area === 'frontend') {
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

        if ($area === 'backend') {
            return [
                'ok' => true,
                'redirect' => $url->getBackendUrl('admin', ['preview_theme' => $themeId]),
            ];
        }

        $mode = in_array($previewMode, ['live', 'version', 'default'], true) ? $previewMode : 'default';

        if ($mode === 'default') {
            $params = $appendPreviewThemeQueryOnFrontendUrl ? ['preview_theme' => $themeId] : [];
            if ($scopeQuery !== null && $scopeQuery !== '') {
                $params['scope'] = $scopeQuery;
            }
            $params['preview_mode'] = 'default';
            $redirect = $url->getFrontendUrl('index/index', $params);
            return [
                'ok' => true,
                'redirect' => $redirect,
            ];
        }

        $layoutType = trim((string)($pageType ?: 'homepage'));
        if ($layoutType === '') {
            $layoutType = 'homepage';
        }
        $previewStatus = in_array($status, ['draft', 'published'], true) ? $status : 'draft';
        $previewEditorArea = $editorArea === 'backend' ? 'backend' : 'frontend';
        $params = [
            'theme_id' => $themeId,
            'layout_type' => $layoutType,
            'layout_option' => 'default',
            'editor_mode' => '1',
            'status' => $previewStatus,
            'editor_area' => $previewEditorArea,
            'preview_mode' => $mode,
            '_t' => time(),
        ];
        $resolvedVersionId = null;
        if ($mode === 'version') {
            $resolvedVersionId = ($versionId !== null && $versionId > 0) ? $versionId : $this->resolvePreviewVersionId(
                $themeId,
                $layoutType,
                $previewStatus
            );
        }
        if ($resolvedVersionId !== null && $resolvedVersionId > 0) {
            $params['version_id'] = $resolvedVersionId;
        }
        if ($scopeQuery !== null && $scopeQuery !== '') {
            $params['scope'] = $scopeQuery;
        }
        if ($appendPreviewThemeQueryOnFrontendUrl) {
            $params['preview_theme'] = $themeId;
        }
        try {
            /** @var PreviewTokenService $previewTokenService */
            $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);
            $previewToken = $previewTokenService->generateToken(
                $themeId,
                $layoutType,
                $resolvedVersionId
            );
            $params[PreviewTokenService::TOKEN_KEY] = $previewToken;
        } catch (\Throwable) {
            // token 生成失败不阻断预览 URL 生成
        }

        $redirect = $url->getBackendUrl('theme/backend/theme-editor/layout-preview', $params);
        return [
            'ok' => true,
            'redirect' => $redirect,
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

            $layoutPath = rtrim($themePath, \DS) . \DS . 'view' . \DS . 'theme' . \DS . $area . \DS . 'layouts' . \DS . $layoutType . \DS . $layoutOption . '.phtml';
            $layoutPath = str_replace('\\', \DS, $layoutPath);

            if (!is_file($layoutPath)) {
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
