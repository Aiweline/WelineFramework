<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\ThemePreview;

use Weline\Acl\Api\Authorization\ResourceAuthorizationServiceInterface;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Session\SessionFactory;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Api\TargetPreviewPayloadProviderInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\EditorModeAssetInjector;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\Scoped\ThemeEditorContextFactory;
use Weline\Theme\Service\Scoped\ThemeScopedPreviewResolver;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePreviewContentRenderer;
use Weline\Theme\Service\ThemeTargetIdentityResolver;
use Weline\Theme\Service\ThemeTargetTypeRegistry;

class Content extends FrontendController
{
    public function index(): string
    {
        /** @var PreviewContextService $previewContextService */
        $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
        /** @var ThemePageTypeResolver $pageTypeResolver */
        $pageTypeResolver = ObjectManager::getInstance(ThemePageTypeResolver::class);
        /** @var PreviewTokenService $previewTokenService */
        $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);
        $tokenData = $previewTokenService->getCurrentPreviewData();
        if (!\is_array($tokenData)) {
            if (!$this->isBackendUserLoggedIn()) {
                throw new \RuntimeException((string)__('Theme 预览需要有效 Token 或后台登录状态。'));
            }
            $this->assertBackendScopePreviewAllowed();
        }
        $this->applyPrivatePreviewResponsePolicy();

        $context = $previewContextService->persistCurrentRequestContext();
        $this->applyPreviewLocale((string)($context['locale'] ?? ''));
        $targetValue = \trim((string)($context['target_value'] ?? ''));
        $layoutType = \is_array($tokenData)
            ? ''
            : \trim((string)$this->request->getParam('layout_type', ''));
        $layoutOption = \is_array($tokenData)
            ? \trim((string)($context['layout_option'] ?? 'default'))
            : \trim((string)$this->request->getParam('layout_option', ''));

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
        // Preview draft correctness comes from status/layout payload + private no-store responses.
        // Do not force view file-path cold lookups on every iframe render (PROD path cache remains usable).
        $editorModeFlag = \trim((string)$this->request->getParam('editor_mode', ''));
        $isEditorMode = ($editorModeFlag === '1' || \strtolower($editorModeFlag) === 'true');
        $this->assign('editor_mode', $isEditorMode);
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

        $themePublicRoute = \trim(\str_replace('\\', '/', (string)$this->request->getParam('theme_public_route', '')), '/');
        if ($themePublicRoute !== '') {
            $this->request->setGet('theme_public_route', $themePublicRoute);
            $this->assign('theme_public_route', $themePublicRoute);
        }

        /** @var ThemePreviewContentRenderer $previewContentRenderer */
        $previewContentRenderer = ObjectManager::getInstance(ThemePreviewContentRenderer::class);
        $versionId = \is_array($tokenData)
            ? ((int)($context['version_id'] ?? 0) ?: null)
            : ((int)$this->request->getParam('version_id', 0) ?: null);
        $typedEditorContext = $this->resolveControlledEditorContext(
            $themeId,
            $layoutType,
            $layoutOption,
        );
        $status = \is_array($tokenData)
            ? (string)($context['status'] ?? PreviewContextService::DEFAULT_STATUS)
            : (string)$this->request->getParam('status', PreviewContextService::DEFAULT_STATUS);
        $controlledPreview = $typedEditorContext instanceof ThemeEditorContext
            || $this->isControlledPreviewRequest();
        if (!$controlledPreview) {
            throw new \RuntimeException('theme_preview_authorization_required');
        }
        $this->installLayoutRenderIdentity(
            $typedEditorContext,
            $context,
            $layoutOption,
            $controlledPreview,
        );
        $previewPayload = $previewContentRenderer->build(
            $themeId,
            $layoutType,
            $status,
            $versionId,
            [],
            $typedEditorContext,
        );
        $editorArea = (string)($context['editor_area'] ?? PreviewContextService::AREA_FRONTEND);
        $scope = (string)($context['scope'] ?? PreviewContextService::DEFAULT_SCOPE);
        $layoutMeta = $this->resolveLayoutMetaForPreview($themeId, $layoutType, $layoutOption, $editorArea, $scope);
        if ($typedEditorContext instanceof ThemeEditorContext) {
            /** @var ThemeScopedPreviewResolver $scopedPreview */
            $scopedPreview = ObjectManager::getInstance(ThemeScopedPreviewResolver::class);
            $layoutMeta = $scopedPreview->resolveLayoutMeta($typedEditorContext, $status);
        }
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
        if ($typedEditorContext instanceof ThemeEditorContext) {
            $html = $this->injectScopedAppearance($html, $typedEditorContext, $status);
        }
        $editorMode = (string)$this->request->getParam('editor_mode', '');
        if ($html !== '' && ($editorMode === '1' || $editorMode === 'true')) {
            /** @var EditorModeAssetInjector $injector */
            $injector = ObjectManager::getInstance(EditorModeAssetInjector::class);
            $html = $injector->inject($html);
        }

        return $html;
    }

    private function injectScopedAppearance(
        string $html,
        ThemeEditorContext $context,
        string $status,
    ): string {
        /** @var WelineTheme $theme */
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->load($context->themeId);
        if ((int)$theme->getId() !== $context->themeId) {
            return $html;
        }
        /** @var ThemeScopedPreviewResolver $resolver */
        $resolver = ObjectManager::getInstance(ThemeScopedPreviewResolver::class);
        $style = $resolver->renderAppearanceStyle($context, $theme, $status);
        if ($style === '') {
            return $html;
        }
        $headEnd = \stripos($html, '</head>');
        if ($headEnd === false) {
            return $style . $html;
        }

        return \substr($html, 0, $headEnd) . $style . \substr($html, $headEnd);
    }

    /**
     * A raw typed context is accepted only for a logged-in backend user. A
     * standalone preview must carry a valid server-side preview token whose
     * stored typed context is used as the authority.
     */
    private function resolveControlledEditorContext(
        int $themeId,
        string $layoutType,
        string $layoutOption,
    ): ?ThemeEditorContext {
        $raw = $this->request->getParam('editor_context', null);
        /** @var PreviewTokenService $tokens */
        $tokens = ObjectManager::getInstance(PreviewTokenService::class);
        $tokenData = $tokens->getCurrentPreviewData();
        $tokenContext = \is_array($tokenData['context'] ?? null) ? $tokenData['context'] : [];
        $tokenRaw = $tokenContext['editor_context'] ?? null;

        if (\is_array($tokenRaw)) {
            $raw = $tokenRaw;
        } elseif ($raw !== null && $raw !== '') {
            $this->assertBackendScopePreviewAllowed();
        }
        if ($raw === null || $raw === '') {
            return null;
        }

        /** @var ThemeEditorContextFactory $factory */
        $factory = ObjectManager::getInstance(ThemeEditorContextFactory::class);
        $typed = $factory->fromInput(
            ['editor_context' => $raw],
            ThemeEditorContext::RESOURCE_LAYOUT,
        );
        if ($typed->themeId !== $themeId
            || $typed->layoutType !== $layoutType
            || $typed->layoutOption !== $layoutOption
            || $typed->area !== PreviewContextService::AREA_FRONTEND
        ) {
            throw new \InvalidArgumentException('theme_scoped_preview_context_mismatch');
        }

        return $typed;
    }

    private function assertBackendScopePreviewAllowed(): void
    {
        /** @var BackendUserContextProviderInterface $users */
        $users = ObjectManager::getInstance(BackendUserContextProviderInterface::class);
        $user = $users->current();
        if ($user === null
            || !$user->getIsEnabled()
            || $user->getRoleId() <= 0
            || !ObjectManager::getInstance(ResourceAuthorizationServiceInterface::class)->isSourceAllowed(
                $user->getRoleId(),
                'Weline_Theme::theme_visual_editor_scope_read',
            )
        ) {
            throw new \RuntimeException('theme_scoped_preview_authorization_required');
        }
    }

    private function isBackendUserLoggedIn(): bool
    {
        try {
            return SessionFactory::getInstance()->createBackendSession()->isLoggedIn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function applyPrivatePreviewResponsePolicy(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $response->setHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    private function isControlledPreviewRequest(): bool
    {
        if ($this->isBackendUserLoggedIn()) {
            return true;
        }
        try {
            /** @var PreviewTokenService $tokens */
            $tokens = ObjectManager::getInstance(PreviewTokenService::class);
            return \is_array($tokens->getCurrentPreviewData());
        } catch (\Throwable) {
            return false;
        }
    }

    private function applyPreviewLocale(string $locale): void
    {
        $locale = \trim($locale);
        if ($locale === '' || \strcasecmp($locale, 'default') === 0) {
            // Explicit default / all-language identity: drop request override so
            // Cookie-backed external language preference remains authoritative.
            State::setRequestLanguageOverride('');
            State::resetRequestPathLocalizationCache();
            State::resetLangLocalCache();
            return;
        }
        if (!\preg_match('/^[a-z]{2,3}_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)?$/', $locale)) {
            return;
        }

        $this->request->setGet('locale', $locale);
        $this->assign('locale', $locale);
        RequestContext::locale($locale);
        // Request-scoped only — never write WELINE_USER_LANG (external switcher owns cookies).
        State::setRequestLanguageOverride($locale);
        State::resetRequestPathLocalizationCache();
        State::resetLangLocalCache();
    }

    /** @param array<string,mixed> $context */
    private function installLayoutRenderIdentity(
        ?ThemeEditorContext $typedContext,
        array $context,
        string $layoutOption,
        bool $controlledPreview,
    ): void {
        /** @var \Weline\Theme\Service\ThemeLayoutScopeNormalizer $normalizer */
        $normalizer = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutScopeNormalizer::class);
        if ($typedContext instanceof ThemeEditorContext) {
            $scope = $normalizer->encodeStorageScope(
                $typedContext->scope->storageScope,
                $typedContext->scope->storeMode,
            );
            $locale = $typedContext->locale === 'default' ? '' : $typedContext->locale;
            $targetType = $typedContext->targetType;
            $targetId = $typedContext->targetId;
        } elseif ($controlledPreview) {
            $normalized = $normalizer->normalize([
                'scope' => (string)($context['scope'] ?? 'default'),
                'store_mode' => (string)($context['store_mode'] ?? 'normal'),
                'locale_code' => (string)($context['locale'] ?? ''),
            ]);
            $scope = $normalized['scope'];
            $locale = $normalized['locale_code'];
            [$targetType, $targetId] = $this->resolvePreviewTarget($context);
            $targetType = $targetType !== '' ? $targetType : 'global';
            $targetId = $targetType !== 'global' ? $targetId : 0;
        } else {
            $identity = RequestContext::scopeIdentity();
            if ($identity === null) {
                throw new \RuntimeException((string)__('Theme 预览缺少冻结的 ScopeIdentity。'));
            }
            $normalized = $normalizer->normalize([
                'scope_identity' => $identity,
                'locale_code' => (string)(RequestContext::locale() ?? ''),
            ]);
            $scope = $normalized['scope'];
            $locale = $normalized['locale_code'];
            $targetType = 'global';
            $targetId = 0;
        }

        RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, new LayoutIdentity(
            $layoutOption !== '' ? $layoutOption : 'default',
            $scope,
            $targetType,
            $targetId,
            $locale,
        ));
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
        [$targetType, $targetId] = $this->resolvePreviewTarget($context);
        if ($targetType === '') {
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
                'store_id' => (int)$this->request->getParam('store_id', 0),
                'store_code' => (string)$this->request->getParam('store_code', ''),
                'store_mode' => (string)$this->request->getParam('store_mode', 'normal'),
                'locale_code' => (string)$this->request->getParam('locale_code', $this->request->getParam('locale', '')),
                'locale' => (string)$this->request->getParam('locale', $this->request->getParam('locale_code', '')),
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
    private function resolvePreviewTarget(array $context = []): array
    {
        /** @var ThemeTargetIdentityResolver $identityResolver */
        $identityResolver = ObjectManager::getInstance(ThemeTargetIdentityResolver::class);
        $candidates = [
            [
                'target_type' => $context['theme_layout_source_target_type'] ?? null,
                'target_id' => $context['theme_layout_source_target_id'] ?? null,
            ],
            [
                'target_type' => $context['theme_layout_target_type'] ?? null,
                'target_id' => $context['theme_layout_target_id'] ?? null,
            ],
        ];
        /** @var PreviewTokenService $tokens */
        $tokens = ObjectManager::getInstance(PreviewTokenService::class);
        if (\is_array($tokens->getCurrentPreviewData())) {
            return $identityResolver->resolveFirst($candidates);
        }
        return $identityResolver->resolveFirst([...$candidates,
            [
                'target_type' => $this->readPreviewRequestValue('theme_layout_target_type'),
                'target_id' => $this->readPreviewRequestValue('theme_layout_target_id'),
            ],
            [
                'target_type' => $this->readPreviewRequestValue('theme_layout_source_target_type'),
                'target_id' => $this->readPreviewRequestValue('theme_layout_source_target_id'),
            ],
        ]);
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
