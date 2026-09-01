<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Helper\ThemeData;

/**
 * Dedicated Meta resource load/project collaborator for scoped Theme workspaces.
 */
final class ThemeScopedMetaProjector
{
    public function __construct(
        private readonly MetaConfigRepositoryInterface $metaConfigs,
        private readonly ThemeScopedProjectionSupport $support,
    ) {
    }

    /** @return array{values:array<string,mixed>} */
    public function load(ThemeEditorContext $context): array
    {
        $themeId = $this->support->contextThemeId($context);
        if ($themeId <= 0) {
            return ['values' => []];
        }
        [$namespace, $identify, $configPrefix] = $this->support->metaResourceIdentity($context);
        unset($identify);
        $values = [];
        foreach (array_reverse($this->support->legacyReadScopes($context)) as $scope) {
            $records = $this->metaConfigs->search(new MetaConfigSearch(
                namespace: $namespace,
                scope: $scope,
                configKeyPrefix: $configPrefix . '.param.',
                allLocales: true,
                identifyId: (string)$themeId,
            ));
            // Meta is the locale-neutral resource. Localized values belong to the
            // separate I18n workspace and must never change this identity's base.
            $resolved = $this->support->resolveLegacyRecords($records, 'default');
            foreach ($resolved as $configKey => $record) {
                $prefix = $configPrefix . '.param.';
                if (!str_starts_with($configKey, $prefix)) {
                    continue;
                }
                $param = substr($configKey, strlen($prefix));
                if (str_ends_with($param, '.value')) {
                    $param = substr($param, 0, -strlen('.value'));
                }
                if ($param !== '') {
                    $values[$param] = $this->support->decodeLegacyValue($record->value);
                }
            }
        }

        return ['values' => $values];
    }

    /** @param array<string,mixed> $payload */
    public function project(ThemeEditorContext $context, array $payload): void
    {
        $themeId = $this->support->contextThemeId($context);
        if ($themeId <= 0) {
            return;
        }
        [$namespace, $identify, $configPrefix] = $this->support->metaResourceIdentity($context);
        $scope = $this->support->legacyWriteScope($context);
        $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];
        $expected = [];
        foreach ($values as $name => $value) {
            $name = (string)$name;
            if (!$this->support->isConfigPathSegment($name)) {
                throw new \RuntimeException('theme_meta_projection_param_invalid');
            }
            $expected[$configPrefix . '.param.' . $name . '.value'] = $this->support->encodeLegacyValue($value);
        }

        $existing = $this->metaConfigs->search(new MetaConfigSearch(
            namespace: $namespace,
            scope: $scope,
            configKeyPrefix: $configPrefix . '.param.',
            allLocales: true,
            identifyId: (string)$themeId,
        ));
        foreach ($existing as $record) {
            if ($record->locale !== null) {
                continue;
            }
            if (!array_key_exists($record->configKey, $expected)) {
                $this->metaConfigs->delete($this->support->identityForRecord($record));
            }
        }
        foreach ($expected as $configKey => $value) {
            $this->metaConfigs->upsert(new MetaConfigWrite(
                new MetaConfigIdentity(
                    namespace: $namespace,
                    configKey: $configKey,
                    scope: $scope,
                    locale: null,
                    identifyId: (string)$themeId,
                    metaIdentify: $identify,
                ),
                $value,
            ));
        }
        ThemeData::clearCache();
    }
}
