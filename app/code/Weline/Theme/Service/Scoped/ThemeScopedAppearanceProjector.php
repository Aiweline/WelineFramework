<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Disk\ThemeDiskCompileService;

/**
 * Dedicated Appearance (tokens/disks/brand) load/project collaborator.
 */
final class ThemeScopedAppearanceProjector
{
    public function __construct(
        private readonly WelineTheme $themes,
        private readonly MetaConfigRepositoryInterface $metaConfigs,
        private readonly ThemeDiskCompileService $diskCompiler,
        private readonly ThemeScopedProjectionSupport $support,
    ) {
    }

    /** @return array{tokens:array<string,mixed>,disks:array<string,mixed>,brand:array<string,mixed>} */
    public function load(ThemeEditorContext $context): array
    {
        $themeId = $this->support->contextThemeId($context);
        $payload = ['tokens' => [], 'disks' => [], 'brand' => []];
        if ($themeId <= 0) {
            return $payload;
        }

        foreach (array_reverse($this->support->legacyReadScopes($context)) as $scope) {
            $records = $this->metaConfigs->search(new MetaConfigSearch(
                namespace: 'theme.' . $context->area,
                scope: $scope,
                configKeyPrefix: 'disk_',
                allLocales: true,
                identifyId: (string)$themeId,
            ));
            foreach ($this->support->resolveLegacyRecords($records, 'default') as $configKey => $record) {
                if (str_starts_with($configKey, 'disk_active.')) {
                    $panel = substr($configKey, strlen('disk_active.'));
                    if ($this->support->isAppearanceSegment($panel)) {
                        $payload['tokens'][$panel] = $record->value;
                    }
                    continue;
                }
                if (!str_starts_with($configKey, 'disk_custom.')) {
                    continue;
                }
                $parts = explode('.', substr($configKey, strlen('disk_custom.')), 2);
                if (count($parts) !== 2
                    || !$this->support->isAppearanceSegment($parts[0])
                    || !$this->support->isAppearanceSegment($parts[1])
                ) {
                    continue;
                }
                $decoded = json_decode($record->value, true);
                $payload['disks'][$parts[0]][$parts[1]] = is_array($decoded) ? $decoded : [];
            }

            $brandRecords = $this->metaConfigs->search(new MetaConfigSearch(
                namespace: 'theme.' . $context->area,
                scope: $scope,
                configKeyPrefix: 'brand_',
                allLocales: true,
                identifyId: (string)$themeId,
            ));
            foreach ($this->support->resolveLegacyRecords($brandRecords, 'default') as $configKey => $record) {
                if (!str_starts_with($configKey, 'brand_')) {
                    continue;
                }
                $key = substr($configKey, strlen('brand_'));
                if (!\in_array($key, ['favicon', 'apple_touch_icon', 'logo_light', 'logo_dark'], true)) {
                    continue;
                }
                $value = trim((string)$record->value);
                if ($value !== '') {
                    $payload['brand'][$key] = $value;
                }
            }
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    public function project(ThemeEditorContext $context, array $payload): void
    {
        $themeId = $this->support->contextThemeId($context);
        if ($themeId <= 0) {
            return;
        }
        $scope = $this->support->legacyWriteScope($context);
        $expected = [];
        foreach (is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [] as $panel => $active) {
            if (!$this->support->isAppearanceSegment((string)$panel)) {
                throw new \RuntimeException('theme_appearance_panel_invalid');
            }
            $expected['disk_active.' . $panel] = (string)$active;
        }
        foreach (is_array($payload['disks'] ?? null) ? $payload['disks'] : [] as $panel => $disks) {
            if (!$this->support->isAppearanceSegment((string)$panel) || !is_array($disks)) {
                throw new \RuntimeException('theme_appearance_disk_invalid');
            }
            foreach ($disks as $diskKey => $disk) {
                if (!$this->support->isAppearanceSegment((string)$diskKey)) {
                    throw new \RuntimeException('theme_appearance_disk_invalid');
                }
                if ($disk === null) {
                    continue;
                }
                $expected['disk_custom.' . $panel . '.' . $diskKey] = $this->support->encodeLegacyValue($disk);
            }
        }
        foreach (is_array($payload['brand'] ?? null) ? $payload['brand'] : [] as $brandKey => $brandValue) {
            $brandKey = (string)$brandKey;
            if (!\in_array($brandKey, ['favicon', 'apple_touch_icon', 'logo_light', 'logo_dark'], true)) {
                throw new \RuntimeException('theme_appearance_brand_invalid');
            }
            $value = trim((string)$brandValue);
            if ($value === '') {
                continue;
            }
            $expected['brand_' . $brandKey] = $value;
        }

        $existing = $this->metaConfigs->search(new MetaConfigSearch(
            namespace: 'theme.' . $context->area,
            scope: $scope,
            configKeyPrefix: 'disk_',
            allLocales: true,
            identifyId: (string)$themeId,
        ));
        foreach ($existing as $record) {
            if ($record->locale !== null) {
                continue;
            }
            if ((!str_starts_with($record->configKey, 'disk_active.')
                    && !str_starts_with($record->configKey, 'disk_custom.'))
                || array_key_exists($record->configKey, $expected)
            ) {
                continue;
            }
            $this->metaConfigs->delete($this->support->identityForRecord($record));
        }
        $existingBrand = $this->metaConfigs->search(new MetaConfigSearch(
            namespace: 'theme.' . $context->area,
            scope: $scope,
            configKeyPrefix: 'brand_',
            allLocales: true,
            identifyId: (string)$themeId,
        ));
        foreach ($existingBrand as $record) {
            if ($record->locale !== null) {
                continue;
            }
            if (!str_starts_with($record->configKey, 'brand_')
                || array_key_exists($record->configKey, $expected)
            ) {
                continue;
            }
            $this->metaConfigs->delete($this->support->identityForRecord($record));
        }
        foreach ($expected as $configKey => $value) {
            $this->metaConfigs->upsert(new MetaConfigWrite(
                new MetaConfigIdentity(
                    namespace: 'theme.' . $context->area,
                    configKey: $configKey,
                    scope: $scope,
                    locale: null,
                    identifyId: (string)$themeId,
                ),
                $value,
            ));
        }

        ThemeData::clearCache();
        $theme = clone $this->themes;
        $theme->clearData()->clearQuery()->load($themeId);
        if ((int)$theme->getId() === $themeId) {
            $this->diskCompiler->compileBundle($theme, $context->area, $scope);
        }
    }
}
