<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;
use Weline\Cdn\Model\Domain;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/** 清理权威仅取可信域名和公开 Scope URL，用户不能直接传入清理地址。 */
final class FpcPolicyTargetService
{
    public function __construct(private readonly Domain $domains, private readonly WebsiteCatalogInterface $websites, private readonly StoreCatalogInterface $stores, private readonly WarmupLocaleUrlExpander $locales) {}

    public function domains(bool $enabledOnly = true): array
    {
        $query=clone $this->domains; $query->clear();
        if ($enabledOnly) { $query->where('enabled',1); }
        return $query->select()->fetch()->getItems();
    }

    /** 同站点官方公共 base 优先；绑定 host 的别名只继承该 base 的路径，不扩大域名范围。
     * @return list<array{host:string,base_url:string}>
     */
    public function publicEndpoints(Domain $domain, ?ScopeIdentity $scope = null): array
    {
        $site=(int)$domain->getData('site_id');
        if ($scope?->websiteId !== null && $scope->websiteId !== $site) { return []; }
        $bound=strtolower(rtrim(trim((string)$domain->getData('domain_name')),'.'));
        if ($bound==='' || preg_match('/^[a-z0-9.-]+$/D',$bound)!==1) { return []; }
        $websiteUrl=null;
        foreach ($this->websites->all() as $website) { if ($website->id===$site) { $websiteUrl=$website->url; break; } }
        $candidates=[];
        if ($scope?->storeCode !== null) {
            $store=$this->stores->byCode($site,$scope->storeCode);
            if ($store===null || !$store->enabled) { return []; }
            // 只有 null 继承；显式无效/外站 URL 不能退到其它 Scope 或 root。
            $candidates[]=$store->url===null ? $websiteUrl : $store->url;
        } else {
            if ($websiteUrl!==null) { $candidates[]=$websiteUrl; }
            foreach ($this->stores->byWebsite($site) as $store) { if ($store->enabled && $store->url!==null) { $candidates[]=$store->url; } }
        }
        $official=[]; $boundAliases=[];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate)==='') { continue; }
            $parts=parse_url(trim($candidate));
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { continue; }
            $scheme=strtolower($parts['scheme']??''); $host=strtolower(rtrim($parts['host']??'','.'));
            if (!in_array($scheme,['http','https'],true) || !($host===$bound || str_ends_with($host,'.'.$bound))) { continue; }
            $suffix=(isset($parts['port'])?':'.$parts['port']:'').rtrim((string)($parts['path']??''),'/');
            $base=$scheme.'://'.$host.$suffix;
            $official[$base]=['host'=>$host,'base_url'=>$base];
            $alias=$scheme.'://'.$bound.$suffix;
            $boundAliases[$alias]=['host'=>$bound,'base_url'=>$alias];
        }
        if ($scope?->storeCode===null && $official===[]) {
            $fallback='https://'.$bound;
            $boundAliases[$fallback]=['host'=>$bound,'base_url'=>$fallback];
        }
        return array_values($official+$boundAliases);
    }

    public function targets(array $declarations, ?ScopeIdentity $scope = null, ?array $domains = null): array
    {
        $out=[];
        foreach ($domains ?? $this->domains() as $domain) {
            $site=(int)$domain->getData('site_id');
            $endpoints=$this->publicEndpoints($domain,$scope);
            foreach ($endpoints as $endpoint) {
                $base=$endpoint['base_url']; $host=$endpoint['host'];
                $base=rtrim($base,'/');
                foreach ($declarations as $row) {
                    $patterns=(array)($row['public_path_patterns'] ?? []);
                    if ($patterns === []) { $patterns=[(string)($row['path_pattern'] ?? '/')]; }
                    foreach ($patterns as $path) {
                        if (!str_contains($path,'*')) {
                            foreach ($this->locales->expandRoute($site,$base,$path) as $localized) {
                                if (strtolower((string)parse_url($localized['url'],PHP_URL_HOST))===$host) { $out[]=['domain_id'=>(int)$domain->getId(),'kind'=>'url','value'=>$localized['url']]; }
                            }
                        }
                    }
                }
                // 语言、币种和 wildcard 的公开变体无法穷尽时，限定该 Scope base。
                $prefix=$host . '/' . ltrim((string)parse_url($base,PHP_URL_PATH),'/');
                $out[]=['domain_id'=>(int)$domain->getId(),'kind'=>'prefix','value'=>$prefix];
            }
        }
        $unique=[]; foreach ($out as $target) { $unique[FpcPolicyStateReducer::targetKey($target)]=$target; }
        return array_values($unique);
    }
}
