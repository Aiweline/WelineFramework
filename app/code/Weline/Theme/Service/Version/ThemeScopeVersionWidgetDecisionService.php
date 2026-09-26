<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Version;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionWidgetDecision;
use Weline\Theme\Service\ThemeScopeVersionService;

/**
 * Target-version widget install/uninstall decisions.
 * Runtime authority is always theme_version_id (V); source_version_id is audit only.
 */
final class ThemeScopeVersionWidgetDecisionService
{
    public function __construct(
        private readonly ThemeScopeVersionWidgetDecision $decisionModel,
        private readonly ThemeVersionSnapshotBuilder $snapshots,
    ) {
    }

    /**
     * Resolve the editing/target ThemeScopeVersion id for a theme+scope (not ThemeLayoutVersion).
     */
    public function resolveTargetThemeVersionId(int $themeId, string $scope): int
    {
        if ($themeId < 1) {
            return 0;
        }
        $scope = \trim($scope);
        if ($scope === '') {
            $scope = 'default';
        }

        try {
            /** @var ThemeScopeVersionService $versions */
            $versions = ObjectManager::getInstance(ThemeScopeVersionService::class);
            $current = $versions->getCurrent($themeId, $scope);
            if ($current instanceof ThemeScopeVersion && $current->getVersionId() > 0) {
                return $current->getVersionId();
            }
            $published = $versions->getPublished($themeId, $scope);
            if ($published instanceof ThemeScopeVersion && $published->getVersionId() > 0) {
                return $published->getVersionId();
            }
        } catch (\Throwable) {
            return 0;
        }

        return 0;
    }

    /**
     * @return list<array{slot_id:string,widget_module:string,widget_code:string,injection_key:string}>
     */
    public function listUninstallOmissions(int $themeVersionId): array
    {
        if ($themeVersionId < 1) {
            return [];
        }

        try {
            $rows = (clone $this->decisionModel)->clearQuery()->clearData()
                ->where(ThemeScopeVersionWidgetDecision::schema_fields_THEME_VERSION_ID, $themeVersionId)
                ->where(
                    ThemeScopeVersionWidgetDecision::schema_fields_DECISION,
                    ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL,
                )
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $rows = \is_array($rows) ? $rows : [];
        if ($rows !== [] && !isset($rows[0]) && isset($rows[ThemeScopeVersionWidgetDecision::schema_fields_ID])) {
            $rows = [$rows];
        }

        $list = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $slot = \trim((string)($row[ThemeScopeVersionWidgetDecision::schema_fields_SLOT_IDENTITY] ?? ''));
            $widgetIdentity = \trim((string)($row[ThemeScopeVersionWidgetDecision::schema_fields_WIDGET_IDENTITY] ?? ''));
            $injectionKey = \trim((string)($row[ThemeScopeVersionWidgetDecision::schema_fields_INJECTION_KEY] ?? ''));
            [$module, $code] = $this->splitWidgetIdentity($widgetIdentity);
            if ($code === '' && $injectionKey !== '') {
                // Fallback: last segment of injection_key often ends with module/code.
                $parts = \explode('|', $injectionKey);
                $code = \trim((string)\end($parts));
                if (\count($parts) >= 2) {
                    $module = \trim((string)$parts[\count($parts) - 2]);
                }
            }
            if ($code === '') {
                continue;
            }
            $list[] = [
                'slot_id' => $slot,
                'widget_module' => $module,
                'widget_code' => $code,
                'injection_key' => $injectionKey,
            ];
        }

        return $list;
    }

    public function recordUninstall(
        int $themeVersionId,
        int $contentRevision,
        string $resourceIdentityHash,
        string $injectionKey,
        string $slotIdentity = '',
        string $widgetIdentity = '',
        string $actorId = '',
        ?int $sourceVersionId = null,
    ): void {
        if ($themeVersionId < 1 || $contentRevision < 1) {
            return;
        }
        $resourceIdentityHash = \trim($resourceIdentityHash);
        $injectionKey = \trim($injectionKey);
        if ($resourceIdentityHash === '' || $injectionKey === '') {
            return;
        }

        $copied = $this->snapshots->copyDecisionsToTargetVersion(
            [[
                'theme_version_id' => $themeVersionId,
                'content_revision' => $contentRevision,
                'resource_identity_hash' => $resourceIdentityHash,
                'injection_key' => $injectionKey,
                'decision' => ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL,
                'slot_identity' => $slotIdentity,
                'widget_identity' => $widgetIdentity,
                'actor_id' => $actorId,
                'source_version_id' => $sourceVersionId,
            ]],
            $themeVersionId,
            $contentRevision,
            $sourceVersionId,
        );
        $row = $copied[0] ?? null;
        if (!\is_array($row)) {
            return;
        }

        try {
            $existing = (clone $this->decisionModel)->clearQuery()->clearData()
                ->where(ThemeScopeVersionWidgetDecision::schema_fields_THEME_VERSION_ID, $themeVersionId)
                ->where(ThemeScopeVersionWidgetDecision::schema_fields_CONTENT_REVISION, $contentRevision)
                ->where(ThemeScopeVersionWidgetDecision::schema_fields_RESOURCE_IDENTITY_HASH, $resourceIdentityHash)
                ->where(ThemeScopeVersionWidgetDecision::schema_fields_INJECTION_KEY, $injectionKey)
                ->find()
                ->fetch();
            if ($existing instanceof ThemeScopeVersionWidgetDecision && $existing->getId() > 0) {
                $existing
                    ->setData(ThemeScopeVersionWidgetDecision::schema_fields_DECISION, ThemeScopeVersionWidgetDecision::DECISION_UNINSTALL)
                    ->setData(ThemeScopeVersionWidgetDecision::schema_fields_SLOT_IDENTITY, $slotIdentity)
                    ->setData(ThemeScopeVersionWidgetDecision::schema_fields_WIDGET_IDENTITY, $widgetIdentity)
                    ->setData(ThemeScopeVersionWidgetDecision::schema_fields_ACTOR_ID, $actorId)
                    ->setData(ThemeScopeVersionWidgetDecision::schema_fields_SOURCE_VERSION_ID, $sourceVersionId)
                    ->save();

                return;
            }

            $model = clone $this->decisionModel;
            $model->reset()->clearData();
            foreach ($row as $field => $value) {
                $model->setData((string)$field, $value);
            }
            $model->save();
        } catch (\Throwable) {
            // Decision persistence must not break editor uninstall; bake still reads legacy source.
        }
    }

    public function chromeResourceIdentityHash(int $themeId, string $scope, string $storeMode = 'normal', string $area = 'frontend'): string
    {
        $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
            themeId: \max(1, $themeId),
            canonicalScope: $scope !== '' ? $scope : 'default',
            storeMode: $storeMode !== '' ? $storeMode : 'normal',
            area: \in_array($area, \Weline\Theme\Api\Version\ThemeVersionIdentity::AREAS, true)
                ? $area
                : \Weline\Theme\Api\Version\ThemeVersionIdentity::AREA_FRONTEND,
        );

        return $this->snapshots->chromeResourceIdentityHash($identity);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitWidgetIdentity(string $widgetIdentity): array
    {
        $widgetIdentity = \trim($widgetIdentity);
        if ($widgetIdentity === '') {
            return ['', ''];
        }
        if (\str_contains($widgetIdentity, '::')) {
            [$module, $code] = \explode('::', $widgetIdentity, 2);

            return [\trim($module), \trim($code)];
        }
        if (\str_contains($widgetIdentity, '|')) {
            $parts = \explode('|', $widgetIdentity);

            return [\trim((string)($parts[0] ?? '')), \trim((string)($parts[1] ?? ''))];
        }

        return ['', $widgetIdentity];
    }
}
