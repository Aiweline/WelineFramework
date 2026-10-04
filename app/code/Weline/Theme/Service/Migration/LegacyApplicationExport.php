<?php
declare(strict_types=1);
namespace Weline\Theme\Service\Migration;

use Weline\Theme\Model\ThemeScopePatch;
use Weline\Theme\Model\ThemeScopeRelease;
use Weline\Theme\Model\ThemeScopeVersion;
use Weline\Theme\Model\ThemeScopeVersionSelection;
use Weline\Theme\Model\ThemeScopeWorkspace;
use Weline\Theme\Model\WelineTheme;

/** 一次升级只读导出旧应用依据；正式主题选择不使用本服务。 */
final class LegacyApplicationExport
{
    public function __construct(
        private readonly ThemeScopeWorkspace $workspaces,
        private readonly ThemeScopeRelease $releases,
        private readonly ThemeScopePatch $patches,
        private readonly ThemeScopeVersionSelection $selections,
        private readonly ThemeScopeVersion $versions,
        private readonly WelineTheme $themes,
    ) {
    }

    public function export(): array
    {
        $workspaceRows = (clone $this->workspaces)->clearData()->clearQuery()->where('resource_type','theme_binding')->select()->fetchArray();
        $selectionRows = (clone $this->selections)->clearData()->clearQuery()->select()->fetchArray();
        $versionRows = (clone $this->versions)->clearData()->clearQuery()->select()->fetchArray();
        $themeRows = (clone $this->themes)->clearData()->clearQuery()->select()->fetchArray();
        $versionById = array_column((array)$versionRows, null, 'version_id');
        $selectionByOwner = [];
        foreach ((array)$selectionRows as $row) {
            $selectionByOwner[$this->ownerKey((int)$row['theme_id'], (string)$row['scope'], (string)$row['store_mode'], (string)$row['area'])] = $row;
        }
        $bindings = []; $unresolved = []; $sourceRecords = [];
        foreach ((array)$workspaceRows as $row) {
            $releaseId = (int)($row['published_release_id'] ?? 0);
            if ($releaseId < 1) { $sourceRecords[] = ['workspace'=>$row, 'published'=>false]; continue; }
            $release = (clone $this->releases)->clearData()->clearQuery()->load($releaseId);
            if ($release->getId() !== $releaseId || (string)$release->getData('identity_hash') !== (string)$row['identity_hash']) {
                $unresolved[] = ['workspace_id'=>$row['workspace_id'], 'reason'=>'legacy_release_identity_missing']; continue;
            }
            $themeId = (int)($release->payload()['theme_id'] ?? 0);
            if ($themeId < 1) { $unresolved[]=['workspace_id'=>$row['workspace_id'], 'reason'=>'legacy_theme_reference_missing']; continue; }
            $revisionId = (int)$release->getData('revision_id');
            $patchRows = $revisionId > 0 ? (clone $this->patches)->clearData()->clearQuery()->where('revision_id',$revisionId)->select()->fetchArray() : [];
            $own = false;
            foreach ((array)$patchRows as $patch) {
                if (($patch['path'] ?? '') === '/theme_id' && in_array(($patch['operation'] ?? ''), ['set','replace'], true)) { $own = true; }
            }
            $base = ['scope'=>(string)$row['scope'], 'scope_kind'=>(string)$row['scope_kind'],
                'website_id'=>$row['website_id'], 'store_mode'=>(string)$row['store_mode'], 'area'=>(string)$row['area'],
                'theme_id'=>$themeId, 'own_intent'=>$own, 'workspace_id'=>(int)$row['workspace_id'],
                'release_id'=>$releaseId, 'revision_id'=>$revisionId ?: null,
                'parent_release_id'=>$release->getData('parent_release_id'), 'identity_hash'=>(string)$row['identity_hash']];
            $scopes = [(string)$row['scope']];
            $cursor = $release; $seen = [];
            while (($parentId = (int)$cursor->getData('parent_release_id')) > 0) {
                if (isset($seen[$parentId])) { throw new \RuntimeException('legacy_parent_release_cycle'); }
                $seen[$parentId]=true;
                $cursor=(clone $this->releases)->clearData()->clearQuery()->load($parentId);
                if ($cursor->getId() !== $parentId) { throw new \RuntimeException('legacy_parent_release_missing'); }
                $scopes[]=(string)$cursor->getData('scope');
            }
            $selection = null;
            foreach ($scopes as $scope) {
                $candidate=$selectionByOwner[$this->ownerKey($themeId,$scope,$base['store_mode'],$base['area'])] ?? null;
                if ((int)($candidate['published_version_id'] ?? 0)>0) { $selection=$candidate; break; }
            }
            if ($selection !== null) {
                $version=$versionById[(int)$selection['published_version_id']] ?? null;
                if ($version === null || (int)$version['theme_id'] !== $themeId || $version['scope'] !== $selection['scope']
                    || $version['store_mode'] !== $base['store_mode'] || $version['area'] !== $base['area']) {
                    $unresolved[]=$base+['reason'=>'legacy_selected_version_owner_missing']; continue;
                }
                $bindings[]=$base+['theme_version_id'=>(int)$version['version_id'], 'content_revision'=>(int)$version['content_revision'],
                    'owner_scope'=>$version['scope'], 'owner_store_mode'=>$version['store_mode'], 'selection_id'=>(int)$selection['selection_id'],
                    'source_kind'=>'version_selection'];
            } else {
                $hasCandidate = false;
                foreach ((array)$versionRows as $version) {
                    if ((int)$version['theme_id']===$themeId && in_array($version['scope'],$scopes,true)
                        && $version['store_mode']===$base['store_mode'] && $version['area']===$base['area']) { $hasCandidate=true; }
                }
                if ($hasCandidate) { $unresolved[]=$base+['reason'=>'legacy_version_selection_missing']; }
                else { $bindings[]=$base+['theme_version_id'=>0,'content_revision'=>0,'owner_scope'=>$base['scope'],
                    'owner_store_mode'=>$base['store_mode'],'selection_id'=>null,'source_kind'=>'package_defaults']; }
            }
            $sourceRecords[]=['workspace'=>$row,'release'=>$release->getData(),'patches'=>$patchRows];
        }
        $activeDefaults=[];
        foreach (['frontend'=>'is_active_frontend','backend'=>'is_active_backend'] as $area=>$field) {
            $active=array_values(array_filter((array)$themeRows,static fn(array $row):bool=>(int)($row[$field] ?? 0)===1));
            if (count($active)>1) { $unresolved[]=['area'=>$area,'reason'=>'legacy_active_ambiguous']; }
            elseif (count($active)===1) { $activeDefaults[$area]=['theme_id'=>(int)$active[0]['id'],'source_kind'=>'legacy_active_marker']; }
        }
        return ['schema'=>'theme-application-legacy-export.v1','bindings'=>$bindings,'active_defaults'=>$activeDefaults,
            'unresolved'=>$unresolved,'source_records'=>['bindings'=>$sourceRecords,'selections'=>$selectionRows,'versions'=>$versionRows,'themes'=>$themeRows]];
    }

    private function ownerKey(int $theme, string $scope, string $mode, string $area): string
    {
        return json_encode([$theme,$scope,$mode,$area],JSON_THROW_ON_ERROR);
    }
}
