<?php

declare(strict_types=1);

namespace Weline\Theme\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\InstallInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

class Upgrade implements InstallInterface
{
    public const VERSION = '1.0.3';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $this->backfillDefaultAreaThemes();
    }

    public function getVersion(): string
    {
        return self::VERSION;
    }

    public function collectDefaultThemeActivationUpdates(
        WelineTheme $theme,
        ThemeContextService $themeContext,
        callable $hasActiveThemeForField
    ): array {
        if (!$theme->getId()) {
            return [];
        }

        $updates = [];
        $activationFields = [
            ThemeContextService::AREA_FRONTEND => $this->getFrontendActiveField(),
            ThemeContextService::AREA_BACKEND => $this->getBackendActiveField(),
        ];

        foreach ($activationFields as $area => $field) {
            if ((int)$theme->getData($field) === 1) {
                continue;
            }
            if (!$themeContext->themeSupportsArea($theme, $area)) {
                continue;
            }
            if ($hasActiveThemeForField($field)) {
                continue;
            }
            $updates[$field] = 1;
        }

        if (!empty($updates) && (int)$theme->getData($this->getLegacyActiveField()) !== 1) {
            $updates[$this->getLegacyActiveField()] = 1;
        }

        return $updates;
    }

    private function backfillDefaultAreaThemes(): void
    {
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme->clearData()->clearQuery();
            $theme->load($this->getModuleNameField(), 'Weline_Theme');
            if (!$theme->getId()) {
                return;
            }

            /** @var ThemeContextService $themeContext */
            $themeContext = ObjectManager::getInstance(ThemeContextService::class);
            $updates = $this->collectDefaultThemeActivationUpdates(
                $theme,
                $themeContext,
                function (string $field): bool {
                    /** @var WelineTheme $activeTheme */
                    $activeTheme = ObjectManager::getInstance(WelineTheme::class);
                    $activeTheme->clearData()->clearQuery();
                    $activeTheme->load($field, 1);
                    return (bool)$activeTheme->getId();
                }
            );

            if (empty($updates)) {
                return;
            }

            foreach ($updates as $field => $value) {
                $theme->setData($field, $value);
            }
            $theme->save();
        } catch (\Throwable) {
        }
    }

    private function getModuleNameField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_MODULE_NAME')
            ? WelineTheme::schema_fields_MODULE_NAME
            : 'module_name';
    }

    private function getLegacyActiveField(): string
    {
        return \defined(WelineTheme::class . '::schema_fields_IS_ACTIVE')
            ? WelineTheme::schema_fields_IS_ACTIVE
            : 'is_active';
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
