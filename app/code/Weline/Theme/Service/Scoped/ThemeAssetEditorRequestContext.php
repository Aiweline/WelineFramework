<?php
declare(strict_types=1);
namespace Weline\Theme\Service\Scoped;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Theme\Service\Version\ThemeVersionResourceSnapshotService;

/** 既有后台资产消费边界：目录先验证客户端 scope，再向 Theme 核心供应中性输入。 */
final class ThemeAssetEditorRequestContext
{
    public function __construct(
        private readonly ScopeIdentityCatalogInterface $catalog,
        private readonly ScopeHierarchyInterface $hierarchy,
        private readonly ThemeAssetEditorContextProducer $producer,
        private readonly ThemeScopeVersionService $versions,
        private readonly ThemeVersionResourceSnapshotService $snapshots,
    ) {}

    /** @param callable(array<string,mixed>):string $defaultLocaleForIdentity 使用方既有服务器语言解析。 */
    public function prepare(array $input, callable $defaultLocaleForIdentity): array
    {
        $resolved=$this->resolve($input,$defaultLocaleForIdentity);
        if (\Weline\Framework\Runtime\ThemeApplicationContext::current($resolved['application']->area,'asset')===null) {
            $resolved['application']->install();
        }
        return $resolved['input'];
    }

    /** 每项显式返回自己的应用输入，复制 source/target 不覆盖本请求的主编辑上下文。 */
    public function resolve(array $input, callable $defaultLocaleForIdentity): array
    {
        $raw=$input['editor_context'] ?? $input;
        if (is_string($raw)) { $raw=json_decode($raw,true,flags:JSON_THROW_ON_ERROR); }
        if (!is_array($raw) || !is_array($scope=$raw['scope'] ?? null)) {
            throw new \InvalidArgumentException('theme_editor_typed_scope_required');
        }
        $candidate=isset($scope['identity']) && is_array($scope['identity'])
            ? ScopeIdentity::fromArray($scope['identity'])
            : $this->hierarchy->fromStorageScope((string)($scope['storage_scope'] ?? ''),false);
        if (!$candidate instanceof ScopeIdentity) { throw new \InvalidArgumentException('theme_asset_editor_scope_required'); }
        $authoritative=$this->catalog->authoritativeIdentity($candidate);
        $validated=$this->hierarchy->contextFromIdentity($authoritative);
        if ((isset($scope['storage_scope']) && $scope['storage_scope']!==$validated->storageScope)
            || (isset($scope['store_mode']) && $scope['store_mode']!==$validated->storeMode)) {
            throw new \InvalidArgumentException('theme_asset_editor_scope_mismatch');
        }
        $locale=$defaultLocaleForIdentity($authoritative->toArray());
        $neutral=null;
        // 这里只消费公开边界已经给出的链，不发现业务父范围。
        foreach (array_reverse($validated->fallbackStorageScopes) as $storageScope) {
            $owner=ThemeContentScope::fromStoredOwner($storageScope,$validated->storeMode,$locale);
            $neutral=new ThemeContentScope($owner->provider,$owner->scopeKey,$owner->storeMode,'',$locale,$neutral);
        }
        // theme_binding editor_context intentionally keeps theme_id=0 (resource identity).
        // The selected Theme comes from the outer request theme_id (or a non-zero context claim).
        $themeId=(int)($raw['theme_id'] ?? 0);
        if ($themeId < 1) {
            $themeId=(int)($input['theme_id']
                ?? $input['frontend_theme_id']
                ?? $input['application_theme_id']
                ?? 0);
        }
        // Defensive: theme_binding set/replace carries the target Theme on the change value.
        // Without an outer theme_id the producer would throw theme_asset_editor_theme_unavailable.
        if ($themeId < 1 && is_array($input['changes'] ?? null)) {
            foreach ($input['changes'] as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $path = (string)($change['path'] ?? '');
                if ($path === '/theme_id' || $path === 'theme_id') {
                    $candidate = (int)($change['value'] ?? 0);
                    if ($candidate > 0) {
                        $themeId = $candidate;
                        break;
                    }
                }
            }
        }
        $area=(string)($raw['area'] ?? $raw['editor_area'] ?? $input['editor_area'] ?? 'frontend');
        $version=$this->versions->getCurrent($themeId,$neutral->storageScope,$neutral->storeMode,$area);
        $identity=$version?->toVersionIdentity();
        $references=[];
        if ($identity!==null) {
            foreach ($this->snapshots->resources($identity) as $row) {
                $references[(string)$row['resource_identity_hash']]=$row;
            }
        }
        $application=$this->producer->build($themeId,$area,$neutral,$identity,$references);
        $raw['scope']=$neutral->toArray();
        $input['editor_context']=$raw;
        return ['input'=>$input,'application'=>$application];
    }
}
