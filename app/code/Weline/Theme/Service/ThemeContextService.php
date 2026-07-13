<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ThemeContextProviderInterface;
use Weline\Framework\Session\Session;
use Weline\Theme\Helper\PreviewManager;
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

        if ($scopeParam !== null && \trim($scopeParam) !== '') {
            return $this->extractScopeForArea($area, $scopeParam) ?? self::DEFAULT_SCOPE;
        }

        try {
            $previewContext = $this->getPreviewContextService()->getCurrentContext();
            $previewScope = $this->extractScopeForArea($area, (string)($previewContext['scope'] ?? ''));
            if ($previewScope !== null && \trim($previewScope) !== '') {
                return $previewScope;
            }
        } catch (\Throwable) {
        }

        if (PreviewManager::isPreviewMode()) {
            $previewScope = PreviewManager::getPreviewScope($area);
            if ($previewScope !== null && \trim($previewScope) !== '') {
                return $this->extractScopeForArea($area, $previewScope) ?? self::DEFAULT_SCOPE;
            }
        }

        $request = $this->getRequest();
        if ($request) {
            $requestScope = $request->getParam('scope_' . $area) ?? $request->getParam('scope');
            $resolvedScope = $this->extractScopeForArea(
                $area,
                \is_scalar($requestScope) ? (string)$requestScope : null
            );
            if ($resolvedScope !== null) {
                return $resolvedScope;
            }
        }

        return self::DEFAULT_SCOPE;
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

    public function activateTheme(WelineTheme $theme, ?string $area = null): WelineTheme
    {
        $theme->setData($this->getActivationField($area), 1);
        $theme->save();
        $this->clearActivationRuntimeCaches($theme, $area);

        return $theme;
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
        $previewThemeId = 0;

        try {
            $previewThemeId = $this->getPreviewContextService()->getThemeIdForArea($area, null, false);
        } catch (\Throwable) {
            $previewThemeId = 0;
        }

        if (!$previewThemeId) {
            try {
                if (!$this->getPreviewContextService()->shouldUseStoredContext()) {
                    return null;
                }
            } catch (\Throwable) {
            }

            $previewThemeArea = '';
            $previewThemeAreaFromRequest = '';

            $request = $this->getRequest();
            if ($request) {
                $previewThemeId = (int)$request->getParam('preview_theme', 0);
                $previewThemeAreaFromRequest = $this->normalizeArea(
                    (string)$request->getParam('preview_area', $area),
                    $area
                );
            }

            $session = $this->getSession();
            if (!$previewThemeId) {
                if ($session) {
                    $previewThemeId = (int)($session->getData('preview_theme_id') ?? 0);
                    $previewThemeArea = (string)($session->getData('preview_theme_area') ?? '');
                }
            } else {
                $previewThemeArea = $previewThemeAreaFromRequest;
            }

            if (!$previewThemeId) {
                return null;
            }

            if ($previewThemeArea === '') {
                $previewThemeArea = $area;
            }

            if ($previewThemeArea !== $area) {
                return null;
            }
        }

        $previewTheme = $this->newThemeModel();
        $previewTheme->load($previewThemeId);

        return $previewTheme->getId() ? $previewTheme : null;
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

    private function getRequest(): ?Request
    {
        try {
            return ObjectManager::getInstance(Request::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getSession(): ?Session
    {
        try {
            return ObjectManager::getInstance(Session::class);
        } catch (\Throwable) {
            return null;
        }
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
