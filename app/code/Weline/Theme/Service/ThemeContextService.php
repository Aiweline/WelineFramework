<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeContextProviderInterface;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\WelineTheme;

class ThemeContextService implements ThemeContextProviderInterface
{
    public const DEFAULT_SCOPE = 'default';
    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';
    public const AREA_GLOBAL = 'global';

    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ?PreviewContextService $previewContextService = null,
        private readonly ?ThemeScopedWorkspaceInterface $scopedWorkspace = null,
        private readonly ?ScopeHierarchyInterface $scopeHierarchy = null,
        private readonly ?ThemeLayoutScopeNormalizer $layoutScopeNormalizer = null,
    ) {
    }

    public function normalizeArea(?string $area, string $default = self::AREA_FRONTEND): string
    {
        $area = \strtolower(\trim((string)$area));

        return $area === self::AREA_BACKEND ? self::AREA_BACKEND
            : ($area === self::AREA_FRONTEND ? self::AREA_FRONTEND : $default);
    }

    public function isSupportedActivationArea(?string $area): bool
    {
        $area = \strtolower(\trim((string)$area));

        return $area === ''
            || $area === self::AREA_GLOBAL
            || $area === self::AREA_FRONTEND
            || $area === self::AREA_BACKEND;
    }

    public function normalizeActivationArea(?string $area): ?string
    {
        $area = \strtolower(\trim((string)$area));

        return match ($area) {
            self::AREA_FRONTEND => self::AREA_FRONTEND,
            self::AREA_BACKEND => self::AREA_BACKEND,
            self::AREA_GLOBAL, '' => null,
            default => null,
        };
    }

    public function getActivationField(?string $area = null): string
    {
        return match ($this->normalizeActivationArea($area)) {
            self::AREA_FRONTEND => $this->getFrontendActiveField(),
            self::AREA_BACKEND => $this->getBackendActiveField(),
            default => $this->getLegacyActiveField(),
        };
    }

    public function resolveAreaAndScope(string $defaultArea, ?string $scopeParam): array
    {
        $area = $this->normalizeArea($defaultArea);
        $scope = self::DEFAULT_SCOPE;

        if ($scopeParam !== null) {
            $scopeParam = \trim($scopeParam);
            if ($scopeParam !== '') {
                if (\str_contains($scopeParam, '/')) {
                    [$maybeArea, $rest] = \explode('/', $scopeParam, 2);
                    if ($maybeArea !== '') {
                        $area = $this->normalizeArea($maybeArea, $area);
                    }
                    $scopeParam = $rest;
                }

                $scopeParam = \trim($scopeParam);
                if ($scopeParam !== '') {
                    $scope = $scopeParam;
                }
            }
        }

        return [$area, $scope];
    }

    public function extractScopeForArea(string $area, ?string $scopeParam): ?string
    {
        if ($scopeParam === null) {
            return null;
        }

        $area = $this->normalizeArea($area);
        $scopeParam = \trim($scopeParam);
        if ($scopeParam === '') {
            return self::DEFAULT_SCOPE;
        }

        if (\str_contains($scopeParam, '/')) {
            [$maybeArea, $rest] = \explode('/', $scopeParam, 2);
            if ($maybeArea !== '' && $this->normalizeArea($maybeArea, $area) !== $area) {
                return null;
            }
            $scopeParam = $rest;
        }

        $scopeParam = \trim($scopeParam);

        return $scopeParam !== '' ? $scopeParam : self::DEFAULT_SCOPE;
    }

    public function resolveCurrentScope(string $area, ?string $scopeParam = null): string
    {
        $area = $this->normalizeArea($area);

        // Only a validated preview context may override the frozen request
        // Scope. Ordinary runtime never accepts URL/body/session Scope claims.
        try {
            $previewContextService = $this->getPreviewContextService();
            if ($previewContextService->hasAuthoritativePreviewContext()) {
                $previewContext = $previewContextService->getCurrentContext();
                $previewThemeId = $previewContextService->getThemeIdForArea($area, $previewContext, false);
                $previewScope = $this->extractScopeForArea($area, (string)($previewContext['scope'] ?? ''));
                if ($previewThemeId > 0 && $previewScope !== null && \trim($previewScope) !== '') {
                    return $previewScope;
                }
            }
        } catch (\Throwable) {
        }

        $identity = RequestContext::scopeIdentity();
        if (!$identity instanceof ScopeIdentity) {
            // Ordinary backend chrome (e.g. Media Manager) may render without a
            // frozen storefront ScopeIdentity. Keep Theme Editor / frontend fail-closed:
            // editor/preview must install preview context or freeze identity first.
            if ($area === self::AREA_BACKEND) {
                return self::DEFAULT_SCOPE;
            }
            throw new \RuntimeException((string)__('Theme 运行时缺少冻结的 ScopeIdentity。'));
        }
        $context = $this->getScopeHierarchy()->contextFromIdentity($identity);

        return $this->getLayoutScopeNormalizer()->encodeStorageScope(
            $context->storageScope,
            $context->storeMode,
        );
    }

    /**
     * Legacy Theme data read chain, nearest Scope first.
     *
     * @return list<string>
     */
    public function resolveCurrentScopeChain(string $area, ?string $scopeParam = null): array
    {
        return $this->resolveStorageScopeChain($this->resolveCurrentScope($area, $scopeParam));
    }

    /** @return list<string> */
    public function resolveStorageScopeChain(string $scope): array
    {
        $scope = \trim($scope) !== '' ? \trim($scope) : 'default.default.default';
        if (\str_starts_with($scope, PreviewThemeScopeService::PREFIX)) {
            return [$scope];
        }

        try {
            return $this->getLayoutScopeNormalizer()->readFallbackScopes($scope);
        } catch (\Throwable) {
            return [$scope];
        }
    }

    public function formatScopePath(string $area, string $scope): string
    {
        $area = $this->normalizeArea($area);
        $scope = \trim($scope) !== '' ? \trim($scope) : self::DEFAULT_SCOPE;

        return $area . '/' . $scope;
    }

    public function formatScopeList(string $area, array $scopes): array
    {
        $formatted = [];
        foreach ($scopes as $scope) {
            $formatted[] = $this->formatScopePath($area, (string)$scope);
        }

        if (empty($formatted)) {
            $formatted[] = $this->formatScopePath($area, self::DEFAULT_SCOPE);
        }

        return \array_values(\array_unique($formatted));
    }

    public function getDirectActiveTheme(?string $area = null): ?WelineTheme
    {
        $theme = $this->newThemeModel();
        try {
            $theme->getActiveTheme($this->normalizeActivationArea($area));
        } catch (\Throwable) {
            return null;
        }

        return $theme->getId() ? $theme : null;
    }

    public function resolveTheme(?string $area = null, ?object $theme = null, bool $allowPreview = true): ?WelineTheme
    {
        if ($theme !== null && !$theme instanceof WelineTheme) {
            throw new \TypeError('Theme context resolution requires a WelineTheme instance when a theme is provided.');
        }
        if ($theme && $theme->getId()) {
            return $theme;
        }

        $normalizedArea = $area === null ? null : $this->normalizeArea($area);
        if ($allowPreview && $normalizedArea !== null) {
            $previewTheme = $this->resolvePreviewTheme($normalizedArea);
            if ($previewTheme) {
                return $previewTheme;
            }
        }

        if ($normalizedArea !== null) {
            $scopedTheme = $this->resolvePublishedScopedTheme($normalizedArea);
            if ($scopedTheme && $scopedTheme->getId()) {
                return $scopedTheme;
            }

            $directTheme = $this->getDirectActiveTheme($normalizedArea);
            if ($directTheme && $directTheme->getId()) {
                return $directTheme;
            }

            $defaultTheme = $this->buildModuleDefaultTheme($normalizedArea);
            if ($defaultTheme->getId()) {
                return $defaultTheme;
            }
        }

        $resolvedTheme = $this->newThemeModel();
        $this->loadActiveTheme($resolvedTheme, $normalizedArea);

        return $resolvedTheme->getId() ? $resolvedTheme : null;
    }

    /**
     * Resolve a Theme for an explicit immutable Scope instead of borrowing the
     * current request Scope. CMS/queue/CLI callers must use this boundary.
     */
    public function resolveThemeForScope(string $area, ScopeIdentity $identity): ?WelineTheme
    {
        $normalizedArea = $this->normalizeArea($area);
        $scopedTheme = $this->resolvePublishedScopedTheme($normalizedArea, $identity);
        if ($scopedTheme && $scopedTheme->getId()) {
            return $scopedTheme;
        }

        $directTheme = $this->getDirectActiveTheme($normalizedArea);
        if ($directTheme && $directTheme->getId()) {
            return $directTheme;
        }

        $defaultTheme = $this->buildModuleDefaultTheme($normalizedArea);
        if ($defaultTheme->getId()) {
            return $defaultTheme;
        }

        $resolvedTheme = $this->newThemeModel();
        $this->loadActiveTheme($resolvedTheme, $normalizedArea);

        return $resolvedTheme->getId() ? $resolvedTheme : null;
    }

    public function activateTheme(WelineTheme $theme, ?string $area = null): WelineTheme
    {
        $theme->setData($this->getActivationField($area), 1);
        $theme->save();
        $this->clearActivationRuntimeCaches($theme, $area);

        return $theme;
    }

    /**
     * @return array{success:bool,status:string,message:string,theme_id?:int,area?:?string}
     */
    public function activateThemeForArea(int $themeId, ?string $area = null): array
    {
        if ($themeId <= 0) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => (string)__('请选择主题'),
            ];
        }

        $area = \strtolower(\trim((string)$area));
        $normalizedArea = \in_array($area, [self::AREA_FRONTEND, self::AREA_BACKEND], true)
            ? $area
            : null;

        $theme = $this->newThemeModel();
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => (string)__('主题不存在'),
            ];
        }

        if ($normalizedArea !== null && !$this->themeSupportsArea($theme, $normalizedArea)) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => (string)__('主题不支持 %{1} 区域', [$normalizedArea]),
            ];
        }

        try {
            if ($normalizedArea === self::AREA_FRONTEND) {
                if ($this->safeToggleAreaThemeActivation($theme, $themeId, WelineTheme::schema_fields_IS_ACTIVE_FRONTEND)) {
                    $theme->_cache->delete('theme_frontend');
                } else {
                    $this->activateThemeFallback($theme, $themeId);
                }
            } elseif ($normalizedArea === self::AREA_BACKEND) {
                if ($this->safeToggleAreaThemeActivation($theme, $themeId, WelineTheme::schema_fields_IS_ACTIVE_BACKEND)) {
                    $theme->_cache->delete('theme_backend');
                } else {
                    $this->activateThemeFallback($theme, $themeId);
                }
            } else {
                $this->activateThemeFallback($theme, $themeId);
            }

            $theme->_cache->delete('theme');
            $theme->_cache->delete('theme_parent_' . $themeId);
            $this->clearActivationRuntimeCaches($theme, $normalizedArea);

            if ($normalizedArea === null || $normalizedArea === self::AREA_FRONTEND) {
                $this->ensureStorefrontHomepageLayoutSeeded($themeId);
            }

            return [
                'success' => true,
                'status' => 'success',
                'message' => (string)__('主题激活成功'),
                'theme_id' => $themeId,
                'area' => $normalizedArea,
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => (string)__('激活失败：%{1}', [$throwable->getMessage()]),
            ];
        }
    }

    /**
     * Ensure storefront homepage layout exists for the activated theme.
     * Does not overwrite an existing draft/published homepage layout.
     */
    private function ensureStorefrontHomepageLayoutSeeded(int $themeId): void
    {
        if ($themeId <= 0) {
            return;
        }

        try {
            /** @var DefaultLayoutSeeder $seeder */
            $seeder = ObjectManager::getInstance(DefaultLayoutSeeder::class);
            $seeder->seedDefaultLayout($themeId, 'homepage', false);
        } catch (\Throwable) {
        }
    }

    private function safeToggleAreaThemeActivation(WelineTheme $theme, int $themeId, string $field): bool
    {
        try {
            $theme->clearQuery();
            $theme->where($field, 1)
                ->update([$field => 0])->fetch();
            $theme->clearQuery();
            $theme->where(WelineTheme::schema_fields_ID, $themeId)
                ->update([$field => 1])->fetch();

            return true;
        } catch (\Throwable $throwable) {
            if ($this->isMissingThemeActivationFieldError($throwable, $field)) {
                return false;
            }

            throw $throwable;
        }
    }

    private function activateThemeFallback(WelineTheme $theme, int $themeId): void
    {
        $theme->clearQuery();
        $theme->where(WelineTheme::schema_fields_IS_ACTIVE, 1)
            ->update([WelineTheme::schema_fields_IS_ACTIVE => 0])->fetch();
        $theme->clearQuery();
        $theme->where(WelineTheme::schema_fields_ID, $themeId)
            ->update([WelineTheme::schema_fields_IS_ACTIVE => 1])->fetch();
    }

    private function isMissingThemeActivationFieldError(\Throwable $throwable, string $field): bool
    {
        $message = \strtolower($throwable->getMessage());
        if (!\str_contains($message, \strtolower($field))) {
            return false;
        }

        return \str_contains($message, 'undefined column')
            || \str_contains($message, 'does not exist')
            || \str_contains($message, 'column');
    }

    private function clearActivationRuntimeCaches(WelineTheme $theme, ?string $area): void
    {
        try {
            ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class)->clearNonGlobalCaches(
                (int)$theme->getId(),
                'theme_context_activate_' . ($this->normalizeActivationArea($area) ?? self::AREA_GLOBAL)
            );
        } catch (\Throwable) {
        }
    }

    public function themeSupportsArea(WelineTheme $theme, string $area): bool
    {
        $area = $this->normalizeArea($area);
        $basePath = \rtrim($theme->getPath(), '/\\');
        if ($basePath === '') {
            return false;
        }

        return \is_dir($basePath . \DIRECTORY_SEPARATOR . $area)
            || \is_dir($basePath . \DIRECTORY_SEPARATOR . 'view' . \DIRECTORY_SEPARATOR . 'theme' . \DIRECTORY_SEPARATOR . $area)
            || \is_dir($basePath . \DIRECTORY_SEPARATOR . 'theme' . \DIRECTORY_SEPARATOR . $area);
    }

    private function resolvePreviewTheme(string $area): ?WelineTheme
    {
        try {
            $previewContextService = $this->getPreviewContextService();
            if (!$previewContextService->hasAuthoritativePreviewContext()) {
                return null;
            }
            $previewThemeId = $previewContextService->getThemeIdForArea($area, null, false);
        } catch (\Throwable) {
            return null;
        }

        if (!$previewThemeId) {
            return null;
        }

        $previewTheme = $this->newThemeModel();
        $previewTheme->load($previewThemeId);

        return $previewTheme->getId() ? $previewTheme : null;
    }

    /**
     * Resolve the immutable published Theme binding for the authoritative request Scope.
     *
     * Request parameters and editor Session state are deliberately excluded here. During
     * a rolling upgrade, missing scoped tables/services fall back to the legacy active
     * Theme so the last known runtime remains available.
     */
    private function resolvePublishedScopedTheme(string $area, ?ScopeIdentity $identity = null): ?WelineTheme
    {
        try {
            $identity ??= RequestContext::scopeIdentity();
            if (!$identity instanceof ScopeIdentity) {
                return null;
            }
            $scope = $this->getScopeHierarchy()->contextFromIdentity($identity);
            $resolved = $this->getScopedWorkspace()->resolvePublishedTheme($scope, $area);
            $themeId = (int)($resolved?->effectiveValue ?? 0);
            if ($themeId <= 0) {
                return null;
            }

            $theme = $this->newThemeModel();
            $theme->load($themeId);
            if (!$theme->getId() || !$this->themeSupportsArea($theme, $area)) {
                return null;
            }

            return $theme;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 获取预览区域的标识符
     * 用于当无法从模板路径确定区域时使用
     */
    public function getPreviewArea(): ?string
    {
        try {
            $context = $this->getPreviewContextService()->getCurrentContext();
            $editorArea = $context['editor_area'] ?? null;
            if ($editorArea === PreviewContextService::AREA_BACKEND) {
                return 'backend';
            }
            // 默认返回 frontend
            return $editorArea ?: 'frontend';
        } catch (\Throwable) {
            return null;
        }
    }

    private function newThemeModel(): WelineTheme
    {
        /** @var WelineTheme $theme */
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery();

        return $theme;
    }

    private function getPreviewContextService(): PreviewContextService
    {
        if ($this->previewContextService) {
            return $this->previewContextService;
        }

        /** @var PreviewContextService $service */
        $service = ObjectManager::getInstance(PreviewContextService::class);
        return $service;
    }

    private function getScopedWorkspace(): ThemeScopedWorkspaceInterface
    {
        if ($this->scopedWorkspace) {
            return $this->scopedWorkspace;
        }

        /** @var ThemeScopedWorkspaceInterface $service */
        $service = ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);

        return $service;
    }

    private function getScopeHierarchy(): ScopeHierarchyInterface
    {
        if ($this->scopeHierarchy) {
            return $this->scopeHierarchy;
        }

        /** @var ScopeHierarchyInterface $service */
        $service = ObjectManager::getInstance(ScopeHierarchyInterface::class);

        return $service;
    }

    private function getLayoutScopeNormalizer(): ThemeLayoutScopeNormalizer
    {
        return $this->layoutScopeNormalizer
            ?? new ThemeLayoutScopeNormalizer($this->getScopeHierarchy());
    }

    private function loadActiveTheme(WelineTheme $theme, ?string $area = null): void
    {
        try {
            $method = new \ReflectionMethod($theme, 'getActiveTheme');
            if ($method->getNumberOfParameters() > 0) {
                $theme->getActiveTheme($area);
            } else {
                $theme->getActiveTheme();
            }
        } catch (\ArgumentCountError) {
            $theme->getActiveTheme();
        }
    }

    private function buildModuleDefaultTheme(?string $area = null): WelineTheme
    {
        $module = Env::getInstance()->getModuleInfo('Weline_Theme');
        $basePath = (string)($module['base_path'] ?? '');
        $themePath = $basePath !== '' ? rtrim($basePath, '/\\') . \DIRECTORY_SEPARATOR . 'view' . \DIRECTORY_SEPARATOR . 'theme' : '';
        $normalizedArea = $area === null ? null : $this->normalizeArea($area);

        if ($themePath === '' || !is_dir($themePath)) {
            return $this->newThemeModel();
        }

        if ($normalizedArea !== null && !is_dir($themePath . \DIRECTORY_SEPARATOR . $normalizedArea)) {
            return $this->newThemeModel();
        }

        $theme = new class extends WelineTheme {
            public string $runtimePath = '';
            public string $runtimeOriginPath = '';

            public function getPath(): string
            {
                return rtrim($this->runtimePath, '/\\') . \DIRECTORY_SEPARATOR;
            }

            public function getOriginPath(): string
            {
                return $this->runtimeOriginPath !== '' ? $this->runtimeOriginPath : $this->getPath();
            }

            public function getThemeChain(): array
            {
                return [$this];
            }

            public function getParentTheme(): ?WelineTheme
            {
                return null;
            }
        };

        $theme->runtimePath = $themePath;
        $theme->runtimeOriginPath = 'Weline_Theme::view/theme';
        $theme->setData($this->getIdField(), $normalizedArea === self::AREA_BACKEND ? -2 : -1);
        $theme->setData($this->getNameField(), 'Weline_Theme');
        $theme->setData($this->getModuleNameField(), 'Weline_Theme');
        $theme->setData($this->getPathField(), $themePath);
        $theme->setData($this->getLegacyActiveField(), 1);
        if ($normalizedArea === self::AREA_FRONTEND) {
            $theme->setData($this->getFrontendActiveField(), 1);
        }
        if ($normalizedArea === self::AREA_BACKEND) {
            $theme->setData($this->getBackendActiveField(), 1);
        }

        return $theme;
    }

    private function getIdField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_ID')
            ? WelineTheme::schema_fields_ID
            : (\defined(WelineTheme::class . '::fields_ID') ? WelineTheme::fields_ID : 'id');
    }

    private function getNameField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_NAME')
            ? WelineTheme::schema_fields_NAME
            : (\defined(WelineTheme::class . '::fields_NAME') ? WelineTheme::fields_NAME : 'name');
    }

    private function getModuleNameField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_MODULE_NAME')
            ? WelineTheme::schema_fields_MODULE_NAME
            : (\defined(WelineTheme::class . '::fields_MODULE_NAME') ? WelineTheme::fields_MODULE_NAME : 'module_name');
    }

    private function getPathField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_PATH')
            ? WelineTheme::schema_fields_PATH
            : (\defined(WelineTheme::class . '::fields_PATH') ? WelineTheme::fields_PATH : 'path');
    }

    private function getLegacyActiveField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_IS_ACTIVE')
            ? WelineTheme::schema_fields_IS_ACTIVE
            : (\defined(WelineTheme::class . '::fields_IS_ACTIVE') ? WelineTheme::fields_IS_ACTIVE : 'is_active');
    }

    private function getFrontendActiveField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_IS_ACTIVE_FRONTEND')
            ? WelineTheme::schema_fields_IS_ACTIVE_FRONTEND
            : 'is_active_frontend';
    }

    private function getBackendActiveField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_IS_ACTIVE_BACKEND')
            ? WelineTheme::schema_fields_IS_ACTIVE_BACKEND
            : 'is_active_backend';
    }
}
