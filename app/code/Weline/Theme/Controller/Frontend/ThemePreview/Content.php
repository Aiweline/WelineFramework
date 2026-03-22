<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\ThemePreview;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePreviewContentRenderer;

class Content extends FrontendController
{
    protected ?string $layoutType = null;

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
            (string)$this->request->getParam('status', PreviewContextService::DEFAULT_STATUS)
        );
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
        ], $previewPayload['meta']));

        return (string)$this->fetch('Weline_Theme::templates/frontend/theme-preview/content.phtml');
    }
}
