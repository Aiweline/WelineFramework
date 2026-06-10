<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\ThemePreview;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\EditorModeAssetInjector;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePreviewContentRenderer;

class Content extends FrontendController
{
    public function index(): string
    {
        /** @var PreviewContextService $previewContextService */
        $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
        /** @var ThemePageTypeResolver $pageTypeResolver */
        $pageTypeResolver = ObjectManager::getInstance(ThemePageTypeResolver::class);

        $context = $previewContextService->persistCurrentRequestContext();
        $targetValue = \trim((string)($context['target_value'] ?? ''));
        $layoutType = \trim((string)$this->request->getParam('layout_type', ''));
        $layoutOption = \trim((string)$this->request->getParam('layout_option', ''));

        if ($layoutType === '') {
            $layoutType = \trim((string)$this->request->getParam('page_type', ''));
        }

        if ($layoutType === '' && $targetValue !== '' && ($context['target_type'] ?? '') === PreviewContextService::TARGET_TYPE_LAYOUT) {
            if (\str_contains($targetValue, '.')) {
                [$layoutType, $layoutOption] = \explode('.', $targetValue, 2);
            } else {
                $layoutType = $targetValue;
            }
        }

        $layoutType = $pageTypeResolver->resolveLayoutType($layoutType, $this, $this->request, 'homepage');
        $layoutOption = $layoutOption !== '' ? $layoutOption : 'default';

        $this->layoutType = $layoutType;
        $this->request->setData('skip_view_file_cache', true);
        $this->request->setGet('page_type', $layoutType);
        $this->request->setGet('layout_type', $layoutType);
        $this->request->setGet('layout_option', $layoutOption);

        if ((string)$this->request->getParam('editor_area', '') === '') {
            $this->request->setGet('editor_area', (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND));
        }
        if ((string)$this->request->getParam('status', '') === '') {
            $this->request->setGet('status', (string)($context['status'] ?? PreviewContextService::DEFAULT_STATUS));
        }

        $this->assign('preview_mode', (string)($context['preview_mode'] ?? PreviewContextService::DEFAULT_PREVIEW_MODE));
        $this->assign('preview_context', $context);
        $themeId = $previewContextService->getThemeIdForArea(PreviewContextService::AREA_FRONTEND, $context, true);
        $this->assign('theme_id', $themeId);
        $this->assign('layout_type', $layoutType);
        $this->assign('layout_option', $layoutOption);

        /** @var ThemePreviewContentRenderer $previewContentRenderer */
        $previewContentRenderer = ObjectManager::getInstance(ThemePreviewContentRenderer::class);
        $previewPayload = $previewContentRenderer->build(
            $themeId,
            $layoutType,
            (string)$this->request->getParam('status', PreviewContextService::DEFAULT_STATUS),
            (int)($this->request->getParam('version_id', $context['version_id'] ?? 0)) ?: null
        );
        $editorArea = (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND);
        $scope = (string)($context['scope'] ?? PreviewContextService::DEFAULT_SCOPE);
        $layoutMeta = $this->resolveLayoutMetaForPreview($themeId, $layoutType, $layoutOption, $editorArea, $scope);
        $this->assign('content', $previewPayload['content']);
        $this->assign('meta', array_merge([
            'showHeader' => true,
            'showFooter' => true,
            'showStatistics' => true,
            'showFeatures' => true,
            'showProducts' => true,
            'showTestimonials' => true,
            'showNews' => true,
            'showPartners' => true,
        ], $previewPayload['meta'], $layoutMeta));

        $html = (string)$this->fetch('Weline_Theme::templates/frontend/theme-preview/content.phtml');
        $editorMode = (string)$this->request->getParam('editor_mode', '');
        if ($html !== '' && ($editorMode === '1' || $editorMode === 'true')) {
            /** @var EditorModeAssetInjector $injector */
            $injector = ObjectManager::getInstance(EditorModeAssetInjector::class);
            $html = $injector->inject($html);
        }

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLayoutMetaForPreview(
        int $themeId,
        string $layoutType,
        string $layoutOption,
        string $editorArea,
        string $scope
    ): array {
        if ($themeId <= 0) {
            return [];
        }

        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->reset()->load($themeId);
            if (!$theme->getId()) {
                return [];
            }

            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($editorArea === PreviewContextService::AREA_BACKEND ? 'backend' : 'frontend');
            $metaIdentify = 'layouts.' . $layoutType . '.' . $layoutOption;

            return ThemeData::getFileParams($metaIdentify, $scope);
        } catch (\Throwable) {
            return [];
        }
    }
}
