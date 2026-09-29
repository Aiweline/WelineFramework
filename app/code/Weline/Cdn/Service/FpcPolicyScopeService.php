<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Api\Scope\ScopeSelectorCatalogInterface;

final class FpcPolicyScopeService
{
    public function __construct(private readonly ScopeHierarchyInterface $hierarchy, private readonly ScopeIdentityCatalogInterface $catalog, private readonly ScopeSelectorCatalogInterface $selector) {}

    public function resolve(array $params): array
    {
        $mode = (string)($params['store_mode'] ?? 'normal');
        if (!in_array($mode,['normal','dev','test'],true)) { throw new \InvalidArgumentException('cdn_fpc_invalid_store_mode'); }
        $view = $this->selector->build((string)($params['target_scope'] ?? ''));
        if ($view['legacy_readonly']) { throw new \InvalidArgumentException('cdn_fpc_invalid_scope'); }
        $scope = ScopeIdentity::fromArray($view['selected_identity']);
        if ($scope->storeMode !== null && $scope->storeMode !== $mode) { throw new \InvalidArgumentException('cdn_fpc_scope_mode_mismatch'); }
        return [$scope, $mode, $view['selected_scope']];
    }

    public function chain(ScopeIdentity $scope): array
    {
        $keys = [];
        do { $keys[] = $scope->canonicalKey(); $scope = $this->hierarchy->parentIdentity($scope); } while ($scope !== null);
        return $keys;
    }

    public function chains(): array
    {
        $out = [];
        foreach (['normal','dev','test'] as $mode) { $global=ScopeIdentity::global(); $out[$mode][$global->canonicalKey()]=$this->chain($global); }
        foreach ($this->catalog->options() as $website) {
            $id=(int)$website['website_id']; $code=(string)$website['code'];
            $scope=ScopeIdentity::website($id,$code);
            foreach (['normal','dev','test'] as $mode) { $out[$mode][$scope->canonicalKey()]=$this->chain($scope); }
            foreach ((array)($website['stores'] ?? []) as $store) {
                $mode=(string)$store['store_mode'];
                $scope=ScopeIdentity::store($id,$code,$store['code'],$mode);
                $out[$mode][$scope->canonicalKey()]=$this->chain($scope);
                foreach ((array)($store['channels'] ?? []) as $channel) {
                    $scope=ScopeIdentity::channel($id,$code,$store['code'],$channel['code'],$mode);
                    $out[$mode][$scope->canonicalKey()]=$this->chain($scope);
                }
            }
        }
        return $out;
    }
}
