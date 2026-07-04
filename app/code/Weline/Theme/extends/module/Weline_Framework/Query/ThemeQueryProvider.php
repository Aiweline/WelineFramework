<?php
declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Query;

use Weline\Admin\Controller\BaseController as AdminBaseController;
use Weline\Backend\Block\ThemeConfig as BackendThemeConfig;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeLayoutService;
use Weline\Theme\Service\ThemeResourceCatalog;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;
use Weline\Theme\Service\ThemeVirtualLayoutService;

/**
 * 主题查询器
 *
 * 提供 getActiveTheme、getConfigValue 等能力，供其他模块通过 w_query('theme', ...) 调用。
 */
class ThemeQueryProvider implements QueryProviderInterface
{
    private ?ThemeVirtualLayoutService $virtualLayoutService;

    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeContextService $themeContext,
        ?ThemeVirtualLayoutService $virtualLayoutService = null,
    ) {
        $this->virtualLayoutService = $virtualLayoutService;
    }

    public function getProviderName(): string
    {
        return 'theme';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getActiveTheme' => $this->getActiveTheme($params),
            'getConfigValue' => $this->getConfigValue($params),
            'getTemplatePath' => $this->getTemplatePath($params),
            'scanThemeLayoutsByType' => $this->scanThemeLayoutsByType($params),
            'listVirtualLayoutOptions' => $this->listVirtualLayoutOptions($params),
            'virtualLayoutExists' => $this->virtualLayoutExists($params),
            'loadVirtualLayoutSource' => $this->loadVirtualLayoutSource($params),
            'listVirtualLayoutVersions' => $this->listVirtualLayoutVersions($params),
            'saveVirtualLayoutSource' => $this->saveVirtualLayoutSource($params),
            'copyTargetLayoutData' => $this->copyTargetLayoutData($params),
            'rollbackVirtualLayoutVersion' => $this->rollbackVirtualLayoutVersion($params),
            'resolveVirtualLayoutRuntime' => $this->resolveVirtualLayoutRuntime($params),
            'clearRuntimeLayoutCaches' => $this->clearRuntimeLayoutCaches($params),
            'saveLayoutSelection' => $this->saveLayoutSelection($params),
            'deleteLayoutSelection' => $this->deleteLayoutSelection($params),
            'resolveLayoutSelection' => $this->resolveLayoutSelection($params),
            'listLayoutSelectionVersions' => $this->listLayoutSelectionVersions($params),
            'precheckLayoutSelectionRollback' => $this->precheckLayoutSelectionRollback($params),
            'rollbackLayoutSelectionVersion' => $this->rollbackLayoutSelectionVersion($params),
            'generatePreviewToken' => $this->generatePreviewToken($params),
            'validatePreviewToken' => $this->validatePreviewToken($params),
            'editorRequest' => $this->editorRequest($params),
            'setBackendThemeMode' => $this->setBackendThemeMode($params),
            default => throw new \InvalidArgumentException(
                (string)__('Theme 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function generatePreviewToken(array $params): array
    {
        /** @var PreviewContextService $previewContextService */
        $previewContextService = ObjectManager::getInstance(PreviewContextService::class);
        /** @var PreviewTokenService $previewTokenService */
        $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);

        $frontendThemeId = (int)($params['frontend_theme_id'] ?? $params['theme_id'] ?? 0);
        if ($frontendThemeId <= 0) {
            $frontendTheme = $this->themeContext->resolveTheme(ThemeContextService::AREA_FRONTEND);
            $frontendThemeId = $frontendTheme !== null ? (int)$frontendTheme->getId() : 0;
        }
        if ($frontendThemeId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('当前没有可用于预览的前台主题。'),
                'token' => '',
                'token_key' => PreviewTokenService::TOKEN_KEY,
            ];
        }

        $pageType = trim((string)($params['page_type'] ?? $params['layout_type'] ?? 'homepage'));
        if ($pageType === '') {
            $pageType = 'homepage';
        }
        $layoutType = trim((string)($params['layout_type'] ?? $pageType));
        if ($layoutType === '') {
            $layoutType = $pageType;
        }
        $layoutOption = trim((string)($params['layout_option'] ?? $params['layout_code'] ?? 'default'));
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }
        $themeTargetType = trim((string)($params['theme_layout_target_type'] ?? $params['target_type'] ?? ''));
        $themeTargetId = (int)($params['theme_layout_target_id'] ?? $params['target_id'] ?? 0);
        $targetValue = trim((string)($params['target_value'] ?? ''));
        if ($targetValue === '' && $themeTargetType !== '' && $themeTargetId > 0) {
            $targetValue = $themeTargetType . ':' . $themeTargetId;
        }
        if ($targetValue === '') {
            $targetValue = $pageType;
        }

        $context = is_array($params['context'] ?? null) ? $params['context'] : [];
        $context = array_replace([
            'frontend_theme_id' => $frontendThemeId,
            'backend_theme_id' => (int)($params['backend_theme_id'] ?? 0),
            'editor_area' => PreviewContextService::AREA_FRONTEND,
            'shell' => PreviewContextService::SHELL_PREVIEW,
            'preview_mode' => (string)($params['preview_mode'] ?? PreviewContextService::DEFAULT_PREVIEW_MODE),
            'status' => (string)($params['status'] ?? PreviewContextService::DEFAULT_STATUS),
            'version_id' => isset($params['version_id']) ? (int)$params['version_id'] : null,
            'scope' => (string)($params['scope'] ?? PreviewContextService::DEFAULT_SCOPE),
            'target_type' => (string)($params['preview_target_type'] ?? PreviewContextService::TARGET_TYPE_PAGE),
            'target_value' => $targetValue,
            'page_type' => $pageType,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'theme_layout_target_type' => $themeTargetType,
            'theme_layout_target_id' => $themeTargetId,
            'theme_layout_source_target_type' => (string)($params['theme_layout_source_target_type'] ?? $themeTargetType),
            'theme_layout_source_target_id' => (int)($params['theme_layout_source_target_id'] ?? $themeTargetId),
        ], $context);
        $context = $previewContextService->ensureThemeIds(
            $previewContextService->buildContext($context, false),
            true,
            true
        );

        $token = $previewTokenService->generateToken(
            $frontendThemeId,
            $pageType,
            $context['version_id'] ?? null,
            $context
        );
        $context = $previewContextService->withPreviewToken($context, $token);

        if (!empty($params['set_cookie'])) {
            $previewTokenService->setPreviewCookie($token);
        }

        return [
            'success' => true,
            'token' => $token,
            'token_key' => PreviewTokenService::TOKEN_KEY,
            'context' => $context,
            'expires_in' => 3600,
        ];
    }

    private function validatePreviewToken(array $params): array
    {
        /** @var PreviewTokenService $previewTokenService */
        $previewTokenService = ObjectManager::getInstance(PreviewTokenService::class);
        $token = trim((string)($params['token'] ?? $params[PreviewTokenService::TOKEN_KEY] ?? ''));
        if ($token === '') {
            return [
                'success' => false,
                'token' => '',
                'token_key' => PreviewTokenService::TOKEN_KEY,
                'message' => (string)__('缺少预览 token。'),
            ];
        }

        $tokenData = $previewTokenService->validateToken($token);
        if (!is_array($tokenData) || !$this->previewTokenMatches($tokenData, $params)) {
            return [
                'success' => false,
                'token' => $token,
                'token_key' => PreviewTokenService::TOKEN_KEY,
                'message' => (string)__('预览 token 无效或已过期。'),
            ];
        }

        return [
            'success' => true,
            'token' => $token,
            'token_key' => PreviewTokenService::TOKEN_KEY,
            'data' => $tokenData,
            'context' => is_array($tokenData['context'] ?? null) ? $tokenData['context'] : [],
            'expires_at' => (int)($tokenData['expires_at'] ?? 0),
        ];
    }

    private function previewTokenMatches(array $tokenData, array $params): bool
    {
        $context = is_array($tokenData['context'] ?? null) ? $tokenData['context'] : [];

        $expectedPageType = trim((string)($params['page_type'] ?? ''));
        if ($expectedPageType !== '') {
            $actualPageType = trim((string)($tokenData['page_type'] ?? $context['page_type'] ?? $context['layout_type'] ?? ''));
            $isGenericRestoredToken = $actualPageType === 'homepage'
                && empty($context['theme_layout_target_type'])
                && preg_match('/^pv_\d+_\d+_[a-f0-9]{16}$/', (string)($tokenData['token'] ?? ''));
            if ($isGenericRestoredToken) {
                return true;
            }
            if ($actualPageType !== $expectedPageType) {
                return false;
            }
        }

        $expectedTargetType = trim((string)($params['theme_layout_target_type'] ?? ''));
        if ($expectedTargetType !== '') {
            $actualTargetType = trim((string)($context['theme_layout_target_type'] ?? ''));
            if ($actualTargetType !== $expectedTargetType) {
                return false;
            }
        }

        $expectedTargetId = (int)($params['theme_layout_target_id'] ?? 0);
        if ($expectedTargetId > 0 && (int)($context['theme_layout_target_id'] ?? 0) !== $expectedTargetId) {
            return false;
        }

        return true;
    }

    private function setBackendThemeMode(array $params): array
    {
        $mode = strtolower(trim((string)($params['mode'] ?? '')));
        if (!in_array($mode, ['light', 'dark'], true)) {
            throw new \InvalidArgumentException((string)__('Invalid backend theme mode: %{1}', $mode));
        }

        /** @var BackendThemeConfig $themeConfig */
        $themeConfig = ObjectManager::getInstance(BackendThemeConfig::class);
        if (method_exists($themeConfig, '__init')) {
            $themeConfig->__init();
        }

        $originConfig = $themeConfig->getOriginThemeConfig();
        if (!is_array($originConfig)) {
            $originConfig = [];
        }

        $layouts = isset($originConfig['layouts']) && is_array($originConfig['layouts'])
            ? $originConfig['layouts']
            : [];
        $layouts['data-topbar'] = $mode;
        $layouts['data-sidebar'] = $mode;
        $layouts['data-theme-mode'] = $mode;
        $layouts['data-layout-mode'] = $mode;

        $nextConfig = $originConfig;
        $nextConfig['theme-mode-switch'] = $mode;
        $nextConfig['dark-mode-switch'] = $mode === 'dark';
        $nextConfig['light-mode-switch'] = $mode === 'light';
        if (array_key_exists('rtl_mode', $params) || array_key_exists('rtl', $params)) {
            $nextConfig['rtl-mode-switch'] = $this->normalizeBool($params['rtl_mode'] ?? $params['rtl'] ?? false);
        }
        $nextConfig['layouts'] = $layouts;

        $themeConfig->setThemeConfig($nextConfig);
        AdminBaseController::clearRuntimeFullPageCache();

        return [
            'success' => true,
            'mode' => $mode,
            'layouts' => $layouts,
            'msg' => (string)__('同步成功'),
            'message' => (string)__('同步成功'),
        ];
    }

    private function getActiveTheme(array $params): ?array
    {
        $area = $this->normalizeQueryArea($params['area'] ?? null);
        $resolved = $this->themeContext->resolveTheme($area);
        if ($resolved === null || !$resolved->getId()) {
            return null;
        }

        $field = $this->themeContext->getActivationField($area);

        return [
            'id' => $resolved->getId(),
            'name' => $resolved->getData(WelineTheme::schema_fields_NAME),
            'module_name' => $resolved->getData(WelineTheme::schema_fields_MODULE_NAME),
            'path' => $resolved->getData(WelineTheme::schema_fields_PATH),
            'parent_id' => $resolved->getData(WelineTheme::schema_fields_PARENT_ID),
            'is_active' => (int)$resolved->getData($field) === 1,
            'config' => $resolved->getData(WelineTheme::schema_fields_CONFIG),
            'preview_image' => $resolved->getPreviewImage(),
            'frontend_preview_image' => $resolved->getFrontendPreviewImage(),
            'backend_preview_image' => $resolved->getBackendPreviewImage(),
        ];
    }

    private function getConfigValue(array $params): ?string
    {
        $layout = (string)($params['layout'] ?? '');
        $area = (string)($params['area'] ?? '');
        $locale = (string)($params['locale'] ?? '');
        $field = (string)($params['field'] ?? 'value');

        if ($layout === '') {
            return null;
        }

        return \Weline\Theme\Helper\ThemeConfigHelper::getConfigValue(
            $layout,
            $area !== '' ? $area : null,
            $locale !== '' ? $locale : null,
            $field
        );
    }

    private function getTemplatePath(array $params): string
    {
        $layout = (string)($params['layout'] ?? '');
        $area = (string)($params['area'] ?? '');
        $locale = (string)($params['locale'] ?? '');
        $defaultValue = (string)($params['default_value'] ?? 'default');

        if ($layout === '') {
            return '';
        }

        return \Weline\Theme\Helper\ThemeConfigHelper::getTemplatePath(
            $layout,
            $area !== '' ? $area : null,
            $locale !== '' ? $locale : null,
            $defaultValue
        );
    }

    /**
     * 扫描当前激活主题中指定类型的布局文件（含主题继承链）
     */
    private function scanThemeLayoutsByType(array $params): array
    {
        $layoutType = (string)($params['layout_type'] ?? '');
        $area = $this->normalizeQueryArea($params['area'] ?? 'frontend', true) ?? ThemeContextService::AREA_FRONTEND;
        if ($layoutType === '') {
            return [];
        }
        $resolved = $this->themeContext->resolveTheme($area);
        if ($resolved !== null && $resolved->getId()) {
            $theme = $resolved;
        } else {
            $theme = clone $this->welineTheme;
            $theme->clearData()->clearQuery();
            $theme->getActiveTheme($area);
        }
        if (!$theme->getId()) {
            return [];
        }
        $fileOptions = $this->scanThemeLayoutCatalog($layoutType, $area, $theme);
        $virtualOptions = $this->virtualLayoutService()->listLayoutOptions(
            $layoutType,
            (int)$theme->getId(),
            $area,
            isset($params['scope']) ? (string)$params['scope'] : null
        );

        return array_replace($fileOptions, $virtualOptions);
    }

    private function scanThemeLayoutCatalog(string $layoutType, string $area, WelineTheme $theme): array
    {
        /** @var ThemeResourceCatalog $catalog */
        $catalog = ObjectManager::getInstance(ThemeResourceCatalog::class);
        $layoutOptions = $catalog->getLayouts($area, $theme)[$layoutType] ?? [];
        $layouts = [];

        foreach ($layoutOptions as $option) {
            if (!is_array($option)) {
                continue;
            }

            $value = trim((string)($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $meta = is_array($option['meta'] ?? null) ? $option['meta'] : [];
            $label = trim((string)($meta['name'] ?? $meta['title'] ?? ''));
            if ($label === '') {
                $label = ucfirst(str_replace(['_', '-'], ' ', $value));
            }

            $layouts[$value] = [
                'value' => $value,
                'label' => $label,
                'name' => $label,
                'layout_code' => $value,
                'layout_name' => $label,
                'description' => (string)($meta['description'] ?? ''),
                'template' => (string)($option['logical_key'] ?? ''),
                'file' => (string)($option['file'] ?? ''),
                'path' => (string)($option['path'] ?? ''),
                'preview_image' => (string)($meta['preview_image'] ?? $meta['preview_url'] ?? ''),
                'config' => is_array($meta['config'] ?? null) ? $meta['config'] : [],
                'meta' => $meta,
                'layer_type' => (string)($option['layer_type'] ?? ''),
                'layer_key' => (string)($option['layer_key'] ?? ''),
                'module_name' => (string)($option['module_name'] ?? ''),
            ];
        }

        return $layouts;
    }

    private function listVirtualLayoutOptions(array $params): array
    {
        return $this->virtualLayoutService()->listLayoutOptions(
            (string)($params['layout_type'] ?? ''),
            (int)($params['theme_id'] ?? 0),
            $this->normalizeQueryArea($params['area'] ?? 'frontend', true) ?? ThemeContextService::AREA_FRONTEND,
            isset($params['scope']) ? (string)$params['scope'] : null
        );
    }

    private function virtualLayoutExists(array $params): bool
    {
        $identity = is_array($params['identity'] ?? null) ? $params['identity'] : $params;

        return $this->virtualLayoutService()->layoutExists(
            (string)($params['layout_type'] ?? $identity['layout_type'] ?? ''),
            (string)($params['layout_option'] ?? $params['layout_code'] ?? $identity['layout_option'] ?? ''),
            $identity
        );
    }

    private function loadVirtualLayoutSource(array $params): ?string
    {
        $identity = is_array($params['identity'] ?? null) ? $params['identity'] : $params;

        return $this->virtualLayoutService()->loadEditableSource(
            (string)($params['layout_type'] ?? $identity['layout_type'] ?? ''),
            (string)($params['layout_option'] ?? $params['layout_code'] ?? $identity['layout_option'] ?? ''),
            $identity
        );
    }

    private function listVirtualLayoutVersions(array $params): array
    {
        $identity = is_array($params['identity'] ?? null) ? $params['identity'] : $params;

        return $this->virtualLayoutService()->listVersionDetails($identity);
    }

    private function saveVirtualLayoutSource(array $params): array
    {
        $identity = is_array($params['identity'] ?? null) ? $params['identity'] : $params;
        $source = (string)($params['source'] ?? $params['source_code'] ?? '');
        $versionData = is_array($params['version_data'] ?? null) ? $params['version_data'] : [];
        $publish = array_key_exists('publish', $params) ? $this->normalizeBool($params['publish']) : true;

        return $this->virtualLayoutService()->saveSourceVersion($identity, $source, $versionData, $publish);
    }

    private function copyTargetLayoutData(array $params): array
    {
        $area = $this->normalizeQueryArea($params['area'] ?? 'frontend', true) ?? ThemeContextService::AREA_FRONTEND;
        $themeId = (int)($params['theme_id'] ?? 0);
        if ($themeId <= 0) {
            $theme = $this->themeContext->resolveTheme($area);
            $themeId = $theme !== null ? (int)$theme->getId() : 0;
        }

        $layoutType = (string)($params['layout_type'] ?? $params['page_type'] ?? '');
        $layoutOption = (string)($params['layout_option'] ?? $params['layout_code'] ?? 'default');
        $scope = (string)($params['scope'] ?? 'default');
        $sourceTargetType = (string)($params['source_target_type'] ?? $params['target_type_from'] ?? '');
        $sourceTargetId = (int)($params['source_target_id'] ?? $params['target_id_from'] ?? 0);
        $targetTargetType = (string)($params['target_target_type'] ?? $params['target_type_to'] ?? '');
        $targetTargetId = (int)($params['target_target_id'] ?? $params['target_id_to'] ?? 0);

        if ($themeId <= 0 || trim($layoutType) === '' || $sourceTargetType === '' || $targetTargetType === ''
            || $sourceTargetId <= 0 || $targetTargetId <= 0) {
            return [
                'success' => false,
                'status' => 'invalid_identity',
                'message' => (string)__('Theme target 布局复制参数不完整。'),
            ];
        }

        $sourceIdentity = [
            'theme_id' => $themeId,
            'area' => $area,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'scope' => $scope,
            'target_type' => $sourceTargetType,
            'target_id' => $sourceTargetId,
        ];
        $targetIdentity = [
            'theme_id' => $themeId,
            'area' => $area,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'scope' => $scope,
            'target_type' => $targetTargetType,
            'target_id' => $targetTargetId,
        ];

        $selectionResult = $this->saveLayoutSelection([
            'target_type' => $targetTargetType,
            'target_id' => $targetTargetId,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'scope' => $scope,
            'options' => [
                'reason' => (string)__('复制 Theme target 布局选择'),
                'metadata' => [
                    'copied_from_target_type' => $sourceTargetType,
                    'copied_from_target_id' => $sourceTargetId,
                ],
            ],
        ]);

        $layoutResult = ObjectManager::getInstance(ThemeLayoutService::class)->copyLayoutIdentity(
            $themeId,
            $layoutType,
            $sourceIdentity,
            $targetIdentity
        );
        $virtualLayoutResult = $this->virtualLayoutService()->copyVirtualLayoutIdentity(
            $sourceIdentity,
            $targetIdentity
        );
        $this->clearRuntimeLayoutCaches(['reason' => 'theme_query_copy_target_layout_data']);

        $success = !empty($selectionResult['success'])
            && !empty($layoutResult['success'])
            && !empty($virtualLayoutResult['success']);

        return [
            'success' => $success,
            'status' => $success ? 'copied' : 'partial_failed',
            'theme_id' => $themeId,
            'area' => $area,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'scope' => $scope,
            'selection' => $selectionResult,
            'layout' => $layoutResult,
            'virtual_layout' => $virtualLayoutResult,
        ];
    }

    private function rollbackVirtualLayoutVersion(array $params): array
    {
        return $this->virtualLayoutService()->rollbackPublishedVersion(
            (int)($params['asset_id'] ?? 0),
            (int)($params['version_id'] ?? $params['target_version_id'] ?? 0),
            is_array($params['options'] ?? null) ? $params['options'] : []
        );
    }

    private function resolveVirtualLayoutRuntime(array $params): ?array
    {
        return $this->virtualLayoutService()->resolvePublishedRuntimeLayout(
            (string)($params['layout_type'] ?? ''),
            (string)($params['layout_option'] ?? ''),
            (int)($params['theme_id'] ?? 0),
            $this->normalizeQueryArea($params['area'] ?? 'frontend', true) ?? ThemeContextService::AREA_FRONTEND,
            isset($params['scope']) ? (string)$params['scope'] : null,
            is_array($params['target_chain'] ?? null) ? $params['target_chain'] : []
        );
    }

    private function clearRuntimeLayoutCaches(array $params): array
    {
        $reason = trim((string)($params['reason'] ?? 'theme_query_clear_runtime_layout_caches'));
        if ($reason === '') {
            $reason = 'theme_query_clear_runtime_layout_caches';
        }

        ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class)->clearNonGlobalCaches(null, $reason);

        return [
            'success' => true,
            'reason' => $reason,
        ];
    }

    private function saveLayoutSelection(array $params): array
    {
        return $this->virtualLayoutService()->saveLayoutSelection(
            (string)($params['target_type'] ?? ''),
            (int)($params['target_id'] ?? 0),
            (string)($params['layout_type'] ?? ''),
            (string)($params['layout_option'] ?? $params['layout_code'] ?? ''),
            isset($params['scope']) ? (string)$params['scope'] : null,
            isset($params['locale']) ? (string)$params['locale'] : null,
            is_array($params['options'] ?? null) ? $params['options'] : []
        );
    }

    private function deleteLayoutSelection(array $params): array
    {
        return $this->virtualLayoutService()->deleteLayoutSelection(
            (string)($params['target_type'] ?? ''),
            (int)($params['target_id'] ?? 0),
            (string)($params['layout_type'] ?? ''),
            isset($params['scope']) ? (string)$params['scope'] : null,
            isset($params['locale']) ? (string)$params['locale'] : null,
            is_array($params['options'] ?? null) ? $params['options'] : []
        );
    }

    private function resolveLayoutSelection(array $params): ?array
    {
        return $this->virtualLayoutService()->resolveLayoutSelection(
            (string)($params['target_type'] ?? ''),
            (int)($params['target_id'] ?? 0),
            (string)($params['layout_type'] ?? ''),
            isset($params['scope']) ? (string)$params['scope'] : null,
            isset($params['locale']) ? (string)$params['locale'] : null
        );
    }

    private function listLayoutSelectionVersions(array $params): array
    {
        return $this->virtualLayoutService()->listLayoutSelectionVersions(
            (string)($params['target_type'] ?? ''),
            (int)($params['target_id'] ?? 0),
            (string)($params['layout_type'] ?? ''),
            isset($params['scope']) ? (string)$params['scope'] : null,
            isset($params['locale']) ? (string)$params['locale'] : null,
            (int)($params['limit'] ?? 20),
            $this->normalizeBool($params['with_precheck'] ?? false)
        );
    }

    private function precheckLayoutSelectionRollback(array $params): array
    {
        $versionId = (int)($params['version_id'] ?? 0);
        if ($versionId <= 0) {
            return ['rollbackable' => false, 'status' => 'invalid_version', 'version_id' => $versionId, 'blockers' => ['invalid_version']];
        }

        return $this->virtualLayoutService()->precheckLayoutSelectionRollback($versionId, $params);
    }

    private function rollbackLayoutSelectionVersion(array $params): array
    {
        $versionId = (int)($params['version_id'] ?? 0);
        if ($versionId <= 0) {
            return ['success' => false, 'status' => 'invalid_version', 'version_id' => $versionId];
        }

        return $this->virtualLayoutService()->rollbackLayoutSelectionVersion($versionId, $params);
    }

    private function editorRequest(array $params): mixed
    {
        $url = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'GET'))) ?: 'GET';
        $headers = is_array($params['headers'] ?? null) ? $params['headers'] : [];
        $body = array_key_exists('body', $params) && $params['body'] !== null ? (string)$params['body'] : '';

        if ($url === '') {
            return ['success' => false, 'message' => 'Missing editor request URL.'];
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            return ['success' => false, 'message' => 'Unsupported editor request method.'];
        }

        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        $url = $this->resolveEditorRequestUrl($url, $request);
        $this->assertAllowedEditorRequestUrl($url);

        $directResponse = $this->dispatchEditorRequestDirect($url, $method, $headers, $body);
        if ($directResponse !== null) {
            return $directResponse;
        }

        return [
            'success' => false,
            'message' => 'Editor request is not mapped to the Theme Query provider.',
            'path' => $this->normalizeEditorRequestPath((string)(parse_url($url, PHP_URL_PATH) ?: '')),
        ];
    }

    private function dispatchEditorRequestDirect(string $url, string $method, array $headers, string $body): mixed
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return null;
        }

        $path = strtolower($this->normalizeEditorRequestPath((string)$parts['path']));
        if (!str_starts_with($path, '/theme/backend/theme-editor/')
            && !str_starts_with($path, '/theme/backend/virtual-theme/')
            && !str_starts_with($path, '/theme/backend/widget/paramrender/')
            && !str_starts_with($path, '/weline/eav/api/options')
        ) {
            return null;
        }

        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
        }
        $bodyParams = $this->parseEditorRequestBody($body, $headers);
        $this->injectEditorRequestParams($queryParams, $bodyParams, $body);
        $themeEditor = null;

        try {
            $response = match ($path) {
                '/theme/backend/theme-editor/widgets' => ($themeEditor ??= $this->createDirectThemeEditor())->getWidgets(),
                '/theme/backend/theme-editor/default-injections' => ($themeEditor ??= $this->createDirectThemeEditor())->getDefaultInjections(),
                '/theme/backend/theme-editor/apply-default-injection' => ($themeEditor ??= $this->createDirectThemeEditor())->postApplyDefaultInjection(),
                '/theme/backend/theme-editor/widget-config' => ($themeEditor ??= $this->createDirectThemeEditor())->getWidgetConfig(),
                '/theme/backend/theme-editor/widget-preview' => ($themeEditor ??= $this->createDirectThemeEditor())->getWidgetPreview(),
                '/theme/backend/theme-editor/layout-options' => ($themeEditor ??= $this->createDirectThemeEditor())->getLayoutOptionsPayload(),
                '/theme/backend/theme-editor/layout-config' => ($themeEditor ??= $this->createDirectThemeEditor())->getLayoutConfigPayload(),
                '/theme/backend/theme-editor/save-layout-selection' => ($themeEditor ??= $this->createDirectThemeEditor())->saveLayoutSelectionPayload(),
                '/theme/backend/theme-editor/save-layout-config' => ($themeEditor ??= $this->createDirectThemeEditor())->saveLayoutConfigPayload(),
                '/theme/backend/theme-editor/ai-translate-config' => ($themeEditor ??= $this->createDirectThemeEditor())->postAiTranslateConfig(),
                '/theme/backend/theme-editor/compile-layout' => ($themeEditor ??= $this->createDirectThemeEditor())->getCompileLayoutPayload(),
                '/theme/backend/theme-editor/installed-locales' => ($themeEditor ??= $this->createDirectThemeEditor())->getInstalledLocales(),
                '/theme/backend/theme-editor/save-widget' => ($themeEditor ??= $this->createDirectThemeEditor())->postSaveWidget(),
                '/theme/backend/theme-editor/update-config' => ($themeEditor ??= $this->createDirectThemeEditor())->postUpdateConfig(),
                '/theme/backend/theme-editor/remove-widget' => ($themeEditor ??= $this->createDirectThemeEditor())->postRemoveWidget(),
                '/theme/backend/theme-editor/save-widget-config' => ($themeEditor ??= $this->createDirectThemeEditor())->postSaveWidgetConfig(),
                '/theme/backend/theme-editor/update-sort' => ($themeEditor ??= $this->createDirectThemeEditor())->postUpdateSort(),
                '/theme/backend/theme-editor/swap-widget-order' => ($themeEditor ??= $this->createDirectThemeEditor())->postSwapWidgetOrder(),
                '/theme/backend/theme-editor/remove-orphan-widgets' => ($themeEditor ??= $this->createDirectThemeEditor())->postRemoveOrphanWidgets(),
                '/theme/backend/theme-editor/move-widget' => ($themeEditor ??= $this->createDirectThemeEditor())->postMoveWidget(),
                '/theme/backend/theme-editor/save-layout' => ($themeEditor ??= $this->createDirectThemeEditor())->postSaveLayout(),
                '/theme/backend/theme-editor/publish' => ($themeEditor ??= $this->createDirectThemeEditor())->postPublish(),
                '/theme/backend/theme-editor/render-widget' => ($themeEditor ??= $this->createDirectThemeEditor())->postRenderWidget(),
                '/theme/backend/theme-editor/save-compiled-layout' => ($themeEditor ??= $this->createDirectThemeEditor())->postSaveCompiledLayout(),
                '/theme/backend/theme-editor/start-preview' => ($themeEditor ??= $this->createDirectThemeEditor())->postStartPreview(),
                '/theme/backend/theme-editor/exit-preview' => ($themeEditor ??= $this->createDirectThemeEditor())->postExitPreview(),
                '/theme/backend/theme-editor/publish-and-exit' => ($themeEditor ??= $this->createDirectThemeEditor())->postPublishAndExit(),
                '/theme/backend/theme-editor/check-lock' => ($themeEditor ??= $this->createDirectThemeEditor())->getCheckLock(),
                '/theme/backend/theme-editor/release-lock' => ($themeEditor ??= $this->createDirectThemeEditor())->postReleaseLock(),
                '/theme/backend/theme-editor/update-activity' => ($themeEditor ??= $this->createDirectThemeEditor())->postUpdateActivity(),
                '/theme/backend/theme-editor/request-takeover' => ($themeEditor ??= $this->createDirectThemeEditor())->postRequestTakeover(),
                '/theme/backend/theme-editor/check-takeover-request' => ($themeEditor ??= $this->createDirectThemeEditor())->getCheckTakeoverRequest(),
                '/theme/backend/theme-editor/force-takeover' => ($themeEditor ??= $this->createDirectThemeEditor())->postForceTakeover(),
                '/theme/backend/theme-editor/versions' => ($themeEditor ??= $this->createDirectThemeEditor())->getVersionsPayload(),
                '/theme/backend/theme-editor/save-version' => ($themeEditor ??= $this->createDirectThemeEditor())->saveVersionPayload(),
                '/theme/backend/theme-editor/switch-version' => ($themeEditor ??= $this->createDirectThemeEditor())->switchVersionPayload(),
                '/theme/backend/theme-editor/restore-original' => ($themeEditor ??= $this->createDirectThemeEditor())->restoreOriginalPayload(),
                '/theme/backend/theme-editor/publish-version' => ($themeEditor ??= $this->createDirectThemeEditor())->publishVersionPayload(),
                '/theme/backend/theme-editor/delete-version' => ($themeEditor ??= $this->createDirectThemeEditor())->deleteVersionPayload(),
                '/theme/backend/theme-editor/rename-version' => ($themeEditor ??= $this->createDirectThemeEditor())->renameVersionPayload(),
                '/theme/backend/virtual-theme/manifest' => $this->createDirectVirtualTheme()->getManifest(),
                '/theme/backend/virtual-theme/ai-catalog' => $this->createDirectVirtualTheme()->getAiCatalog(),
                '/theme/backend/virtual-theme/source' => $this->createDirectVirtualTheme()->getSource(),
                '/theme/backend/virtual-theme/create-draft' => $this->createDirectVirtualTheme()->postCreateDraft(),
                '/theme/backend/virtual-theme/block-action' => $this->createDirectVirtualTheme()->postBlockAction(),
                '/theme/backend/virtual-theme/save-source' => $this->createDirectVirtualTheme()->postSaveSource(),
                '/theme/backend/virtual-theme/publish-version' => $this->createDirectVirtualTheme()->postPublishVersion(),
                '/theme/backend/virtual-theme/rollback-version' => $this->createDirectVirtualTheme()->postRollbackVersion(),
                '/theme/backend/widget/paramrender/form' => $this->createDirectParamRender()->postForm(),
                '/weline/eav/api/options' => $this->createDirectEavOptions()->getIndex(),
                '/weline/eav/api/options/attributes' => $this->createDirectEavOptions()->getAttributes(),
                '/weline/eav/api/options/entities' => $this->createDirectEavOptions()->getEntities(),
                default => null,
            };
        } catch (\Throwable $e) {
            if (method_exists($e, 'getBody')) {
                $response = (string)$e->getBody();
            } else {
                throw $e;
            }
        }

        if ($response === null) {
            return null;
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $response;
    }

    private function createDirectThemeEditor(): \Weline\Theme\Controller\Backend\ThemeEditor
    {
        $controller = new \Weline\Theme\Controller\Backend\ThemeEditor(
            ObjectManager::getInstance(WelineTheme::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutVersionService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeCacheGenerator::class),
            ObjectManager::getInstance(\Weline\Theme\Service\WidgetPositionResolver::class),
            ObjectManager::getInstance(\Weline\Widget\Service\WidgetRegistry::class),
            ObjectManager::getInstance(\Weline\Theme\Model\ThemeLayout::class),
            ObjectManager::getInstance(\Weline\Meta\Model\Meta::class),
            ObjectManager::getInstance(\Weline\Theme\Service\PreviewTokenService::class),
            ObjectManager::getInstance(\Weline\Theme\Service\EditorLockService::class)
        );
        $this->injectRequestIntoController($controller);
        return $controller;
    }

    private function createDirectParamRender(): \Weline\Theme\Controller\Backend\Widget\ParamRender
    {
        $controller = new \Weline\Theme\Controller\Backend\Widget\ParamRender();
        $this->injectRequestIntoController($controller);
        return $controller;
    }

    private function createDirectVirtualTheme(): \Weline\Theme\Controller\Backend\VirtualTheme
    {
        $controller = new \Weline\Theme\Controller\Backend\VirtualTheme(
            ObjectManager::getInstance(WelineTheme::class),
            ObjectManager::getInstance(\Weline\Theme\Service\ThemeVirtualThemeManifestService::class),
            ObjectManager::getInstance(ThemeVirtualLayoutService::class)
        );
        $this->injectRequestIntoController($controller);
        return $controller;
    }

    private function createDirectEavOptions(): \Weline\Eav\Controller\Api\Options
    {
        $controller = new \Weline\Eav\Controller\Api\Options(
            ObjectManager::getInstance(\Weline\Eav\Model\EavEntity::class),
            ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute::class),
            ObjectManager::getInstance(\Weline\Eav\Model\EavAttribute\Option::class)
        );
        $this->injectRequestIntoController($controller);
        return $controller;
    }

    private function injectRequestIntoController(object $controller): void
    {
        $session = \Weline\Framework\Session\SessionFactory::getInstance()->createBackendSession();
        if (method_exists($session, 'start')) {
            $session->start(null);
        }

        $this->setControllerProperty($controller, 'request', ObjectManager::getInstance(\Weline\Framework\Http\Request::class));
        $this->setControllerProperty($controller, '_objectManager', ObjectManager::getInstance());
        $this->setControllerProperty($controller, '_url', ObjectManager::getInstance(\Weline\Framework\Http\Url::class));
        $this->setControllerProperty($controller, 'session', $session);
    }

    private function setControllerProperty(object $controller, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionObject($controller);
        while ($reflection !== false) {
            if ($reflection->hasProperty($propertyName)) {
                $property = $reflection->getProperty($propertyName);
                $property->setAccessible(true);
                $property->setValue($controller, $value);
                return;
            }
            $reflection = $reflection->getParentClass();
        }
    }

    private function parseEditorRequestBody(string $body, array $headers): array
    {
        if ($body === '') {
            return [];
        }

        $contentType = '';
        foreach ($headers as $name => $value) {
            if (strtolower((string)$name) === 'content-type') {
                $contentType = strtolower((string)$value);
                break;
            }
        }

        if (str_contains($contentType, 'application/json') || str_starts_with(ltrim($body), '{')) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }

        $parsed = [];
        parse_str($body, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    private function injectEditorRequestParams(array $queryParams, array $bodyParams, string $rawBody): void
    {
        /** @var \Weline\Framework\Http\Request $request */
        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        foreach ($queryParams as $key => $value) {
            $request->setGet((string)$key, $value);
        }
        foreach ($bodyParams as $key => $value) {
            $request->setPost((string)$key, $value);
        }

        $merged = array_merge($queryParams, $bodyParams);
        $request->setData('params', $merged);
        $request->setData('body_params', $bodyParams !== [] ? $bodyParams : $rawBody);
        $request->setData('array_body_params', $bodyParams);
        $request->getParameterBag()->setBody($bodyParams);
        $request->getParameterBag()->setRawBody($rawBody);
    }

    private function resolveEditorRequestUrl(string $url, \Weline\Framework\Http\Request $request): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Invalid editor request URL.');
        }

        $https = strtolower((string)$request->getServer('HTTPS'));
        $forwardedProto = strtolower((string)$request->getServer('HTTP_X_FORWARDED_PROTO'));
        $scheme = $forwardedProto !== ''
            ? $forwardedProto
            : (($https !== '' && $https !== 'off') ? 'https' : 'http');

        if (($parts['scheme'] ?? '') === '' && ($parts['host'] ?? '') !== '') {
            return $scheme . ':' . $url;
        }

        if (($parts['scheme'] ?? '') !== '') {
            return $url;
        }

        $host = (string)($request->getServer('HTTP_HOST') ?: $request->getServer('SERVER_NAME') ?: '');
        if ($host === '') {
            throw new \InvalidArgumentException('Unable to resolve editor request host.');
        }

        return $scheme . '://' . $host . (str_starts_with($url, '/') ? $url : '/' . $url);
    }

    private function assertAllowedEditorRequestUrl(string $url): void
    {
        $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            throw new \InvalidArgumentException('Invalid editor request URL.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Unsupported editor request scheme.');
        }

        $targetHost = strtolower((string)($parts['host'] ?? ''));
        if ($targetHost !== '') {
            $requestHost = strtolower((string)($request->getServer('HTTP_HOST') ?: $request->getServer('SERVER_NAME') ?: ''));
            $requestHostName = strtolower((string)(parse_url('//' . $requestHost, PHP_URL_HOST) ?: $requestHost));
            if ($requestHostName !== '' && $targetHost !== $requestHostName) {
                throw new \InvalidArgumentException('Editor request host is not allowed.');
            }
        }

        $normalizedPath = $this->normalizeEditorRequestPath((string)$parts['path']);
        foreach ([
            '/theme/backend/theme-editor/',
            '/theme/backend/virtual-theme/',
            '/theme/backend/widget/paramrender/form',
            '/weline/eav/api/options',
        ] as $allowedPrefix) {
            if ($normalizedPath === $allowedPrefix || str_starts_with($normalizedPath, $allowedPrefix)) {
                return;
            }
        }

        throw new \InvalidArgumentException('Editor request path is not allowed.');
    }

    private function normalizeEditorRequestPath(string $path): string
    {
        $lowerPath = strtolower($path);
        foreach (['/theme/backend/', '/weline/eav/'] as $marker) {
            $pos = strpos($lowerPath, $marker);
            if ($pos !== false) {
                return substr($path, $pos);
            }
        }

        return $path;
    }

    private function doScanThemeLayouts(string $layoutType, string $area, WelineTheme $theme): array
    {
        $layouts = [];
        $themePath = $theme->getPath();
        if ($themePath === '' || !is_dir($themePath)) {
            $parent = $theme->getParentTheme();
            return $parent ? $this->doScanThemeLayouts($layoutType, $area, $parent) : [];
        }
        $ds = \DIRECTORY_SEPARATOR;
        $layoutsDir = rtrim($themePath, $ds) . $ds . 'view' . $ds . 'theme' . $ds . $area . $ds . 'layouts' . $ds . $layoutType;
        if (!is_dir($layoutsDir)) {
            $parent = $theme->getParentTheme();
            return $parent ? $this->doScanThemeLayouts($layoutType, $area, $parent) : [];
        }
        $themeCode = $theme->getModuleName() ?: 'Weline_Theme';
        $files = glob($layoutsDir . $ds . '*.phtml') ?: [];
        foreach ($files as $file) {
            $fileName = basename($file, '.phtml');
            $layoutPath = $themeCode . '::theme/' . $area . '/layouts/' . $layoutType . '/' . $fileName;
            $meta = $this->parseLayoutMeta($file);
            $layouts[$fileName] = [
                'name' => $meta['name'] ?? ucfirst($fileName),
                'description' => $meta['description'] ?? '',
                'template' => $layoutPath,
                'preview_image' => $meta['preview_image'] ?? '',
                'config' => $meta['config'] ?? [],
            ];
        }
        return $layouts;
    }

    private function parseLayoutMeta(string $filePath): array
    {
        $meta = [];
        if (!is_file($filePath)) {
            return $meta;
        }
        $content = file_get_contents($filePath);
        if (preg_match('/@meta\.name\s*\{[^}]*name\s*=\s*"([^"]+)"/', $content, $m)) {
            $meta['name'] = $m[1];
        }
        if (preg_match('/@meta\.description\s*\{[^}]*description\s*=\s*"([^"]+)"/', $content, $m)) {
            $meta['description'] = $m[1];
        }
        if (preg_match('/@preview_image\s*\{[^}]*default\s*=\s*"([^"]+)"/', $content, $m)) {
            $meta['preview_image'] = $m[1];
        }
        return $meta;
    }

    private function virtualLayoutService(): ThemeVirtualLayoutService
    {
        if ($this->virtualLayoutService instanceof ThemeVirtualLayoutService) {
            return $this->virtualLayoutService;
        }

        return $this->virtualLayoutService = ObjectManager::getInstance(ThemeVirtualLayoutService::class);
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'theme',
            'name' => __('主题查询'),
            'description' => __('提供当前主题、配置值、模板路径等查询能力'),
            'module' => 'Weline_Theme',
            'operations' => [
                [
                    'name' => 'getActiveTheme',
                    'description' => __('获取当前激活的主题信息'),
                    'params' => [
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('可选：frontend 或 backend')],
                    ],
                ],
                [
                    'name' => 'getConfigValue',
                    'description' => __('获取主题配置值'),
                    'params' => [
                        ['name' => 'layout', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'field', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getTemplatePath',
                    'description' => __('获取主题配置的模板路径'),
                    'params' => [
                        ['name' => 'layout', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'default_value', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'scanThemeLayoutsByType',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('扫描当前主题中指定类型的布局选项'),
                    'params' => [
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('默认 frontend')],
                    ],
                ],
                [
                    'name' => 'listVirtualLayoutOptions',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('列出 Theme 虚拟布局选项'),
                    'params' => [
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                        ['name' => 'theme_id', 'type' => 'int', 'required' => false],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'virtualLayoutExists',
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('检查 Theme 虚拟布局是否存在'),
                    'params' => [
                        ['name' => 'identity', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name' => 'loadVirtualLayoutSource',
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('读取 Theme 虚拟布局当前可编辑源码'),
                    'params' => [
                        ['name' => 'identity', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name' => 'listVirtualLayoutVersions',
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('列出 Theme 虚拟布局源码/发布版本'),
                    'params' => [
                        ['name' => 'identity', 'type' => 'array', 'required' => true],
                    ],
                ],
                [
                    'name' => 'saveVirtualLayoutSource',
                    'description' => __('保存 Theme 虚拟布局源码版本'),
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'identity', 'type' => 'array', 'required' => true],
                        ['name' => 'source', 'type' => 'string', 'required' => true],
                        ['name' => 'publish', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'copyTargetLayoutData',
                    'description' => __('复制 Theme target 的布局选择、可视化布局和虚拟布局数据'),
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'source_target_type', 'type' => 'string', 'required' => true],
                        ['name' => 'source_target_id', 'type' => 'int', 'required' => true],
                        ['name' => 'target_target_type', 'type' => 'string', 'required' => true],
                        ['name' => 'target_target_id', 'type' => 'int', 'required' => true],
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                        ['name' => 'layout_option', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'rollbackVirtualLayoutVersion',
                    'description' => __('回滚 Theme 虚拟布局发布版本'),
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'asset_id', 'type' => 'int', 'required' => true],
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                    ],
                ],
                [
                    'name' => 'resolveVirtualLayoutRuntime',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('解析当前有效 Theme 虚拟布局运行时信息'),
                    'params' => [
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                        ['name' => 'layout_option', 'type' => 'string', 'required' => true],
                        ['name' => 'target_chain', 'type' => 'array', 'required' => false],
                    ],
                ],
                [
                    'name' => 'clearRuntimeLayoutCaches',
                    'description' => __('清理 Theme 运行时布局缓存'),
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'saveLayoutSelection',
                    'description' => __('保存 Theme 布局选择'),
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'target_type', 'type' => 'string', 'required' => true],
                        ['name' => 'target_id', 'type' => 'int', 'required' => true],
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                        ['name' => 'layout_option', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'deleteLayoutSelection',
                    'description' => __('删除 Theme 布局选择并恢复继承'),
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'target_type', 'type' => 'string', 'required' => true],
                        ['name' => 'target_id', 'type' => 'int', 'required' => true],
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'resolveLayoutSelection',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('解析 Theme 布局选择及来源'),
                    'params' => [
                        ['name' => 'target_type', 'type' => 'string', 'required' => true],
                        ['name' => 'target_id', 'type' => 'int', 'required' => true],
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                    ],
                ],
                [
                    'name' => 'listLayoutSelectionVersions',
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('列出产品/分类布局选择版本'),
                    'params' => [
                        ['name' => 'target_type', 'type' => 'string', 'required' => true],
                        ['name' => 'target_id', 'type' => 'int', 'required' => true],
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'limit', 'type' => 'int', 'required' => false],
                        ['name' => 'with_precheck', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'precheckLayoutSelectionRollback',
                    'mode' => 'read',
                    'graph' => true,
                    'description' => __('预检产品/分类布局选择版本是否可回滚'),
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                        ['name' => 'target_type', 'type' => 'string', 'required' => false],
                        ['name' => 'target_id', 'type' => 'int', 'required' => false],
                        ['name' => 'layout_type', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'rollbackLayoutSelectionVersion',
                    'description' => __('回滚产品/分类布局选择版本'),
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'version_id', 'type' => 'int', 'required' => true],
                        ['name' => 'target_type', 'type' => 'string', 'required' => false],
                        ['name' => 'target_id', 'type' => 'int', 'required' => false],
                        ['name' => 'layout_type', 'type' => 'string', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'reason', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'generatePreviewToken',
                    'description' => __('生成 Theme 前台预览 token'),
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'page_type', 'type' => 'string', 'required' => true],
                        ['name' => 'layout_type', 'type' => 'string', 'required' => false],
                        ['name' => 'layout_option', 'type' => 'string', 'required' => false],
                        ['name' => 'theme_layout_target_type', 'type' => 'string', 'required' => false],
                        ['name' => 'theme_layout_target_id', 'type' => 'int', 'required' => false],
                        ['name' => 'scope', 'type' => 'string', 'required' => false],
                        ['name' => 'context', 'type' => 'array', 'required' => false],
                    ],
                ],
                [
                    'name' => 'validatePreviewToken',
                    'description' => __('校验 Theme 前台预览 token'),
                    'mode' => 'read',
                    'params' => [
                        ['name' => 'token', 'type' => 'string', 'required' => true],
                        ['name' => 'page_type', 'type' => 'string', 'required' => false],
                        ['name' => 'theme_layout_target_type', 'type' => 'string', 'required' => false],
                        ['name' => 'theme_layout_target_id', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'editorRequest',
                    'description' => __('Theme editor signed backend request bridge'),
                    'frontend' => true,
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true, 'max_length' => 2048],
                        ['name' => 'method', 'type' => 'string', 'required' => false, 'max_length' => 8],
                        ['name' => 'headers', 'type' => 'array', 'required' => false],
                        ['name' => 'body', 'type' => 'string', 'required' => false, 'nullable' => true, 'max_length' => 1048576],
                    ],
                ],
                [
                    'name' => 'setBackendThemeMode',
                    'description' => __('同步后台亮色/暗色模式'),
                    'frontend' => true,
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'mode', 'type' => 'string', 'required' => true, 'description' => __('light 或 dark')],
                        ['name' => 'rtl_mode', 'type' => 'bool', 'required' => false],
                        ['name' => 'rtl', 'type' => 'bool', 'required' => false],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param bool $defaultFrontend 当 area 为空时是否默认 frontend（getActiveTheme 为 false，scan 为 true）
     */
    private function normalizeQueryArea(mixed $area, bool $defaultFrontend = false): ?string
    {
        $raw = strtolower(trim((string)$area));
        if ($raw === '') {
            return $defaultFrontend ? ThemeContextService::AREA_FRONTEND : null;
        }

        return match ($raw) {
            ThemeContextService::AREA_FRONTEND => ThemeContextService::AREA_FRONTEND,
            ThemeContextService::AREA_BACKEND => ThemeContextService::AREA_BACKEND,
            default => null,
        };
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }
        return false;
    }
}
