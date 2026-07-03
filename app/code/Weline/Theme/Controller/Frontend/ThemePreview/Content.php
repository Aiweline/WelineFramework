<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\ThemePreview;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\State;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Api\TargetPreviewPayloadProviderInterface;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\EditorModeAssetInjector;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePreviewContentRenderer;
use Weline\Theme\Service\ThemeTargetTypeRegistry;

class Content extends FrontendController
{
    public function index(): string
    {
        $this->applyPreviewLocale((string)$this->request->getParam('locale', ''));

        /** @var PreviewContextService $previewContextService */
        $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
        /** @var ThemePageTypeResolver $pageTypeResolver */
        $pageTypeResolver = ObjectManager::getInstance(ThemePageTypeResolver::class);

        $context = $previewContextService->persistCurrentRequestContext();
        $this->applyPreviewLocale((string)$this->request->getParam('locale', (string)($context['locale'] ?? '')));
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
        if ($themeId > 0) {
            $this->request->setGet('theme_id', $themeId);
            $this->request->setGet('frontend_theme_id', $themeId);
        }
        if ((string)$this->request->getParam('preview_area', '') === '') {
            $this->request->setGet('preview_area', PreviewContextService::AREA_FRONTEND);
        }
        $this->assign('theme_id', $themeId);
        $this->assign('layout_type', $layoutType);
        $this->assign('layout_option', $layoutOption);

        /** @var ThemePreviewContentRenderer $previewContentRenderer */
        $previewContentRenderer = ObjectManager::getInstance(ThemePreviewContentRenderer::class);
        $versionId = (int)$this->request->getParam('version_id', 0) ?: null;
        $previewPayload = $previewContentRenderer->build(
            $themeId,
            $layoutType,
            (string)$this->request->getParam('status', PreviewContextService::DEFAULT_STATUS),
            $versionId
        );
        $editorArea = (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND);
        $scope = (string)($context['scope'] ?? PreviewContextService::DEFAULT_SCOPE);
        $layoutMeta = $this->resolveLayoutMetaForPreview($themeId, $layoutType, $layoutOption, $editorArea, $scope);
        $targetPreviewPayload = $this->resolveTargetPreviewPayload($context, $layoutType, $layoutOption, $editorArea, $scope);
        $targetPreviewMeta = $this->buildTargetPreviewMeta($targetPreviewPayload);
        $this->assign('content', $previewPayload['content']);
        $this->assign('target_preview_payload', $targetPreviewPayload ?: []);
        $this->assign('meta', array_merge([
            'showHeader' => true,
            'showFooter' => true,
            'showStatistics' => true,
            'showFeatures' => true,
            'showProducts' => true,
            'showTestimonials' => true,
            'showNews' => true,
            'showPartners' => true,
        ], $previewPayload['meta'], $layoutMeta, $targetPreviewMeta));

        $html = (string)$this->fetch('Weline_Theme::templates/frontend/theme-preview/content.phtml');
        $editorMode = (string)$this->request->getParam('editor_mode', '');
        if ($html !== '' && ($editorMode === '1' || $editorMode === 'true')) {
            /** @var EditorModeAssetInjector $injector */
            $injector = ObjectManager::getInstance(EditorModeAssetInjector::class);
            $html = $injector->inject($html);
        }

        return $html;
    }

    private function applyPreviewLocale(string $locale): void
    {
        $locale = \trim($locale);
        if ($locale === '' || !\preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $locale)) {
            return;
        }

        $_SERVER['WELINE_USER_LANG'] = $locale;
        $this->request->setServer('WELINE_USER_LANG', $locale);
        $this->request->setGet('locale', $locale);
        $this->assign('locale', $locale);
        RequestContext::locale($locale);
        WelineEnv::setLang($locale);
        WelineEnv::setServer('WELINE_USER_LANG', $locale, 'Theme preview locale override');
        State::resetRequestPathLocalizationCache();
        State::resetLangLocalCache();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function resolveTargetPreviewPayload(
        array $context,
        string $layoutType,
        string $layoutOption,
        string $editorArea,
        string $scope
    ): ?array {
        [$targetType, $targetId] = $this->resolvePreviewTarget();
        if ($targetType === '' || $targetId <= 0) {
            return null;
        }

        try {
            /** @var ThemeTargetTypeRegistry $targetTypeRegistry */
            $targetTypeRegistry = ObjectManager::getInstance(ThemeTargetTypeRegistry::class);
            $provider = $targetTypeRegistry->get($targetType);
            if (!$provider instanceof TargetPreviewPayloadProviderInterface) {
                return null;
            }
            if (!$provider->canUseLayoutType($layoutType)) {
                return null;
            }

            $payload = $provider->resolvePreviewPayload($targetId, [
                'layout_type' => $layoutType,
                'layout_option' => $layoutOption,
                'editor_area' => $editorArea,
                'preview_area' => (string)$this->request->getParam('preview_area', $editorArea),
                'preview_mode' => (string)($context['preview_mode'] ?? PreviewContextService::DEFAULT_PREVIEW_MODE),
                'status' => (string)($context['status'] ?? PreviewContextService::DEFAULT_STATUS),
                'scope' => $scope,
                'preview' => true,
                'request_context' => $context,
            ]);

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0:string,1:int}
     */
    private function resolvePreviewTarget(): array
    {
        $targetType = strtolower(trim((string)$this->readPreviewRequestValue('theme_layout_target_type')));
        $targetId = (int)$this->readPreviewRequestValue('theme_layout_target_id');
        if ($targetType !== '' && $targetId > 0) {
            return [$targetType, $targetId];
        }

        $sourceTargetType = strtolower(trim((string)$this->readPreviewRequestValue('theme_layout_source_target_type')));
        $sourceTargetId = (int)$this->readPreviewRequestValue('theme_layout_source_target_id');
        if ($sourceTargetType !== '' && $sourceTargetId > 0) {
            return [$sourceTargetType, $sourceTargetId];
        }

        return ['', 0];
    }

    private function readPreviewRequestValue(string $key): mixed
    {
        $value = null;
        try {
            $value = $this->request->getData($key);
        } catch (\Throwable) {
        }
        if ($value !== null && $value !== '') {
            return $value;
        }

        try {
            $value = $this->request->getParam($key, null);
        } catch (\Throwable) {
        }
        if ($value !== null && $value !== '') {
            return $value;
        }

        try {
            return $this->request->getGet($key, '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function buildTargetPreviewMeta(?array $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        if (!array_key_exists('content', $meta) && array_key_exists('html', $content)) {
            $meta['content'] = (string)$content['html'];
        }
        if (!array_key_exists('title', $meta) && array_key_exists('title', $content)) {
            $meta['title'] = (string)$content['title'];
        }

        return $meta;
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
