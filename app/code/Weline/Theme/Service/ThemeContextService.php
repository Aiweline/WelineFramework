<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Model\WelineTheme;

class ThemeContextService
{
    public const DEFAULT_SCOPE = 'default';
    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';
    public const AREA_GLOBAL = 'global';

    public function __construct(
        private readonly WelineTheme $welineTheme,
    ) {
    }

    public function normalizeArea(?string $area, string $default = self::AREA_FRONTEND): string
    {
        $area = strtolower(trim((string)$area));

        return $area === self::AREA_BACKEND ? self::AREA_BACKEND
            : ($area === self::AREA_FRONTEND ? self::AREA_FRONTEND : $default);
    }

    public function isSupportedActivationArea(?string $area): bool
    {
        $area = strtolower(trim((string)$area));

        return $area === ''
            || $area === self::AREA_GLOBAL
            || $area === self::AREA_FRONTEND
            || $area === self::AREA_BACKEND;
    }

    public function normalizeActivationArea(?string $area): ?string
    {
        $area = strtolower(trim((string)$area));

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
            self::AREA_FRONTEND => WelineTheme::schema_fields_IS_ACTIVE_FRONTEND,
            self::AREA_BACKEND => WelineTheme::schema_fields_IS_ACTIVE_BACKEND,
            default => WelineTheme::schema_fields_IS_ACTIVE,
        };
    }

    public function resolveAreaAndScope(string $defaultArea, ?string $scopeParam): array
    {
        $area = $this->normalizeArea($defaultArea);
        $scope = self::DEFAULT_SCOPE;

        if ($scopeParam !== null) {
            $scopeParam = trim($scopeParam);
            if ($scopeParam !== '') {
                if (str_contains($scopeParam, '/')) {
                    [$maybeArea, $rest] = explode('/', $scopeParam, 2);
                    if ($maybeArea !== '') {
                        $area = $this->normalizeArea($maybeArea, $area);
                    }
                    $scopeParam = $rest;
                }

                $scopeParam = trim($scopeParam);
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
        $scopeParam = trim($scopeParam);
        if ($scopeParam === '') {
            return self::DEFAULT_SCOPE;
        }

        if (str_contains($scopeParam, '/')) {
            [$maybeArea, $rest] = explode('/', $scopeParam, 2);
            if ($maybeArea !== '' && $this->normalizeArea($maybeArea, $area) !== $area) {
                return null;
            }
            $scopeParam = $rest;
        }

        $scopeParam = trim($scopeParam);

        return $scopeParam !== '' ? $scopeParam : self::DEFAULT_SCOPE;
    }

    public function resolveCurrentScope(string $area, ?string $scopeParam = null): string
    {
        $area = $this->normalizeArea($area);

        if ($scopeParam !== null && trim($scopeParam) !== '') {
            return $this->extractScopeForArea($area, $scopeParam) ?? self::DEFAULT_SCOPE;
        }

        if (PreviewManager::isPreviewMode()) {
            $previewScope = PreviewManager::getPreviewScope($area);
            if ($previewScope !== null && trim($previewScope) !== '') {
                return $this->extractScopeForArea($area, $previewScope) ?? self::DEFAULT_SCOPE;
            }
        }

        $request = $this->getRequest();
        if ($request) {
            $requestScope = $request->getParam('scope_' . $area) ?? $request->getParam('scope');
            $resolvedScope = $this->extractScopeForArea(
                $area,
                is_scalar($requestScope) ? (string)$requestScope : null
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
        $scope = trim($scope) !== '' ? trim($scope) : self::DEFAULT_SCOPE;

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

        return array_values(array_unique($formatted));
    }

    public function getDirectActiveTheme(?string $area = null): ?WelineTheme
    {
        $theme = $this->newThemeModel();
        $theme->load($this->getActivationField($area), 1);

        return $theme->getId() ? $theme : null;
    }

    public function resolveTheme(?string $area = null, ?WelineTheme $theme = null, bool $allowPreview = true): ?WelineTheme
    {
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

        $resolvedTheme = $this->newThemeModel();
        $resolvedTheme->getActiveTheme($normalizedArea);

        return $resolvedTheme->getId() ? $resolvedTheme : null;
    }

    public function activateTheme(WelineTheme $theme, ?string $area = null): WelineTheme
    {
        $theme->setData($this->getActivationField($area), 1);
        $theme->save();

        return $theme;
    }

    public function themeSupportsArea(WelineTheme $theme, string $area): bool
    {
        $area = $this->normalizeArea($area);
        $originPath = trim((string)$theme->getOriginPath(), '/\\');
        if ($originPath === '') {
            return false;
        }

        $basePath = rtrim(Env::path_THEME_DESIGN_DIR, '/\\') . DS . str_replace(['/', '\\'], DS, $originPath);

        return is_dir($basePath . DS . $area)
            || is_dir($basePath . DS . 'view' . DS . 'theme' . DS . $area);
    }

    private function resolvePreviewTheme(string $area): ?WelineTheme
    {
        $previewThemeId = 0;
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
            // URL 明确带 preview_theme 时，优先使用请求上下文 area，避免被历史 session area 干扰
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

        $previewTheme = $this->newThemeModel();
        $previewTheme->load($previewThemeId);

        return $previewTheme->getId() ? $previewTheme : null;
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
}
