<?php
declare(strict_types=1);
namespace Weline\Theme\Service\Version;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeApplicationReferenceReaderInterface;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

final class ThemeApplicationReferenceReader implements ThemeApplicationReferenceReaderInterface
{
    public function __construct(
        private readonly WelineTheme $themes,
        private readonly ThemeScopeVersion $versions,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeVersionResourceSnapshotService $snapshots,
    ) {
    }

    public function validateReference(array $reference): array
    {
        $id = (int)($reference['theme_id'] ?? 0);
        $versionId = (int)($reference['theme_version_id'] ?? -1);
        $revision = (int)($reference['content_revision'] ?? -1);
        $scope = (string)($reference['owner_scope'] ?? $reference['version_owner_scope'] ?? '');
        $mode = (string)($reference['store_mode'] ?? $reference['version_owner_store_mode'] ?? '');
        $area = (string)($reference['area'] ?? '');
        if ($id < 0 || $versionId < 0 || $revision < 0 || ($versionId === 0 && $revision !== 0)
            || $scope === '' || strlen($scope) > 400 || $mode === '' || strlen($mode) > 16
            || !in_array($area, ['frontend','backend'], true)) {
            throw new \InvalidArgumentException('theme_application_reference_invalid');
        }
        // theme_id=0：Theme 模块包默认（磁盘 view/theme），不要求库表注册行。
        if ($id === 0) {
            if ($versionId !== 0) {
                throw new \InvalidArgumentException('theme_application_reference_invalid');
            }
            $moduleDefault = $this->themeContext->resolveRegisteredDefaultTheme($area);
            if ($moduleDefault === null || !$this->themeContext->themeSupportsArea($moduleDefault, $area)) {
                throw new \InvalidArgumentException('theme_application_asset_unavailable');
            }
            return ['theme_id'=>0, 'theme_version_id'=>0, 'content_revision'=>0,
                'owner_scope'=>$scope, 'store_mode'=>$mode, 'area'=>$area, 'source_kind'=>'package_defaults'];
        }
        $theme = (clone $this->themes)->clearData()->clearQuery()->load($id);
        if ($theme->getId() !== $id || !$this->themeContext->themeSupportsArea($theme, $area)) {
            throw new \InvalidArgumentException('theme_application_asset_unavailable');
        }
        $out = ['theme_id'=>$id, 'theme_version_id'=>$versionId, 'content_revision'=>$revision,
            'owner_scope'=>$scope, 'store_mode'=>$mode, 'area'=>$area];
        if ($versionId === 0) { return $out + ['source_kind'=>'package_defaults']; }
        $version = (clone $this->versions)->clearData()->clearQuery()->load($versionId);
        if ($version->getVersionId() !== $versionId || $version->getThemeId() !== $id
            || $version->getScope() !== $scope || $version->getStoreMode() !== $mode || $version->getArea() !== $area
            || $version->getLifecycle() !== ThemeScopeVersion::LIFECYCLE_SEALED) {
            throw new \InvalidArgumentException('theme_application_version_owner_mismatch');
        }
        $identity = new ThemeVersionIdentity($id, $scope, $mode, $area, $versionId, 'formal', $revision);
        if ($this->snapshots->head($identity) === null) {
            throw new \RuntimeException('theme_application_revision_missing');
        }
        return $out + ['source_kind'=>'version_snapshot'];
    }

    public function resourceReferences(array $reference): array
    {
        $ref = $this->validateReference($reference);
        if ($ref['theme_version_id'] === 0) { return []; }
        $identity = new ThemeVersionIdentity($ref['theme_id'], $ref['owner_scope'], $ref['store_mode'], $ref['area'],
            $ref['theme_version_id'], 'formal', $ref['content_revision']);
        $out = [];
        foreach ($this->snapshots->resources($identity) as $row) {
            $key = json_decode((string)$row['resource_key_json'], true, flags:JSON_THROW_ON_ERROR);
            $context = $this->snapshots->contextFor($identity, $key);
            if ($context->identityHash() !== (string)$row['resource_identity_hash']) {
                throw new \RuntimeException('theme_application_resource_identity_mismatch');
            }
            $out[$context->identityHash()] = $row + ['context'=>$context->toArray()];
        }
        return $out;
    }

    public function exportLegacyApplicationSnapshot(): array
    {
        return ObjectManager::getInstance(\Weline\Theme\Service\Migration\LegacyApplicationExport::class)->export();
    }
}
