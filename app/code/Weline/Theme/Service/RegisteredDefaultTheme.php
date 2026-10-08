<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * Theme 模块全局默认主题：磁盘 view/theme 始终可用；目录行仅可选 catalog id。
 */
final class RegisteredDefaultTheme implements DefaultThemeInterface
{
    public function __construct(
        private readonly WelineTheme $themes,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeApplicationReferenceReaderInterface $references,
    ) {
    }

    public function getRegisteredDefault(?string $area = null): array
    {
        $path = $this->moduleThemePath();
        if ($area !== null && $area !== '') {
            $area = $this->themeContext->normalizeArea($area);
            if (!is_dir($path . \DIRECTORY_SEPARATOR . $area)) {
                throw new \RuntimeException('theme_module_default_area_unsupported');
            }
        }

        $catalogId = $this->lookupCatalogThemeId();

        return [
            WelineTheme::schema_fields_ID => $catalogId,
            WelineTheme::schema_fields_NAME => DefaultThemeInterface::REGISTERED_NAME,
            WelineTheme::schema_fields_MODULE_NAME => DefaultThemeInterface::REGISTERED_MODULE,
            WelineTheme::schema_fields_PATH => $path,
            WelineTheme::schema_fields_PARENT_ID => null,
            'source' => 'module_default',
        ];
    }

    public function defaultApplicationReference(string $area, string $ownerScope, string $storeMode): array
    {
        $area = $this->themeContext->normalizeArea($area);
        $ownerScope = trim($ownerScope);
        if ($ownerScope === '' || !in_array($storeMode, ['normal', 'dev', 'test'], true)) {
            throw new \InvalidArgumentException('theme_registered_default_reference_context_invalid');
        }
        $data = $this->getRegisteredDefault($area);

        return $this->references->validateReference([
            'theme_id' => (int)$data[WelineTheme::schema_fields_ID],
            'theme_version_id' => 0,
            'content_revision' => 0,
            'owner_scope' => $ownerScope,
            'store_mode' => $storeMode,
            'area' => $area,
        ]);
    }

    public function isModuleDefaultThemeId(int $themeId): bool
    {
        if ($themeId === DefaultThemeInterface::MODULE_DEFAULT_THEME_ID) {
            return true;
        }
        if ($themeId < 1) {
            return false;
        }

        return $this->lookupCatalogThemeId() === $themeId;
    }

    public function resolveLayoutEntityCatalogThemeId(WelineTheme $theme): int
    {
        $themeId = (int)$theme->getId();
        if ($themeId > 0) {
            return $themeId;
        }
        if ($this->isModuleDefaultThemeId($themeId) || $this->isVirtualModuleDefaultTheme($theme)) {
            $catalogId = $this->lookupCatalogThemeId();

            return $catalogId >= 1 ? $catalogId : 0;
        }

        return 0;
    }

    private function isVirtualModuleDefaultTheme(WelineTheme $theme): bool
    {
        $themeId = (int)$theme->getId();
        if ($themeId === -1 || $themeId === -2) {
            return true;
        }
        try {
            $modulePath = rtrim(str_replace('\\', '/', $this->moduleThemePath()), '/');
        } catch (\Throwable) {
            $modulePath = '';
        }
        $themePath = rtrim(str_replace('\\', '/', (string)$theme->getPath()), '/');
        if ($modulePath !== '' && $themePath !== '' && ($themePath === $modulePath || str_starts_with($themePath, $modulePath . '/'))) {
            return true;
        }
        $origin = method_exists($theme, 'getOriginPath') ? (string)$theme->getOriginPath() : '';

        return $origin !== '' && str_starts_with(str_replace('\\', '/', $origin), DefaultThemeInterface::MODULE_ORIGIN);
    }

    private function moduleThemePath(): string
    {
        $module = Env::getInstance()->getModuleInfo(DefaultThemeInterface::REGISTERED_MODULE);
        $basePath = (string)($module['base_path'] ?? '');
        $themePath = $basePath !== ''
            ? rtrim($basePath, '/\\') . \DIRECTORY_SEPARATOR . 'view' . \DIRECTORY_SEPARATOR . 'theme'
            : '';
        if ($themePath === '' || !is_dir($themePath)) {
            throw new \RuntimeException('theme_module_default_path_missing');
        }

        return $themePath;
    }

    /** 目录可选；找不到则 0（模块包默认）。绝不假定 id=1。 */
    private function lookupCatalogThemeId(): int
    {
        $theme = clone $this->themes;
        $theme->clearData()->clearQuery()->load(WelineTheme::schema_fields_NAME, DefaultThemeInterface::REGISTERED_NAME);
        if ((int)$theme->getId() >= 1) {
            return (int)$theme->getId();
        }
        $theme->clearData()->clearQuery()
            ->where(WelineTheme::schema_fields_MODULE_NAME, DefaultThemeInterface::REGISTERED_MODULE)
            ->where(WelineTheme::schema_fields_NAME, 'default')
            ->find()
            ->fetch();
        if ((int)$theme->getId() >= 1) {
            return (int)$theme->getId();
        }

        return DefaultThemeInterface::MODULE_DEFAULT_THEME_ID;
    }
}
