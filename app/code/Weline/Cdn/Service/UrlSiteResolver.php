<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Domain;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * URL站点解析服务
 *
 * 根据URL解析对应的站点和域名（TASK-P1D-002：可选 Scope 隔离，禁止跨站串用）
 *
 * @package Weline_Cdn
 */
class UrlSiteResolver
{
    private ObjectManager $objectManager;

    /**
     * 启用域名列表按 scope 缓存（键 = 'all' 或 'w:<websiteId>'）。
     *
     * 原实现每解析一个 URL 就 select() 全表查一次启用域名并逐行水合模型；
     * 批量预热收集 1700+ URL 时会打 1700+ 次全表查询（实测 setup:upgrade
     * 的 CDN 预热入队单步 66 秒）。域名在单次请求/单次升级内不变，缓存一次即可，
     * 语义不变（仍由 matchDomainByHost 按同一套规则匹配）。
     *
     * @var array<string, list<Domain>>
     */
    private array $enabledDomainsCache = [];

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /** 域名增删改后如需强制重读，可调用此方法。 */
    public function clearEnabledDomainsCache(): void
    {
        $this->enabledDomainsCache = [];
    }

    /**
     * @return list<Domain>
     */
    private function enabledDomains(?ScopeIdentity $scope): array
    {
        $key = ($scope !== null && $scope->websiteId !== null) ? 'w:' . (int)$scope->websiteId : 'all';
        if (isset($this->enabledDomainsCache[$key])) {
            return $this->enabledDomainsCache[$key];
        }

        /** @var Domain $domainModel */
        $domainModel = clone $this->objectManager->getInstance(Domain::class);
        $query = $domainModel->reset()->where(Domain::schema_fields_ENABLED, 1);
        if ($scope !== null && $scope->websiteId !== null) {
            $query->where(Domain::schema_fields_SITE_ID, (int)$scope->websiteId);
        }
        // matchDomainByHost() 返回 ?Domain，必须对象化。
        $domains = $query->select()->fetch()->toModels();

        return $this->enabledDomainsCache[$key] = \is_array($domains) ? $domains : [];
    }

    /**
     * 根据URL解析域名
     *
     * @param string $url URL地址
     * @param ScopeIdentity|null $scope 若提供则仅匹配该 Website（website_id，含 0）
     */
    public function resolveDomainByUrl(string $url, ?ScopeIdentity $scope = null): ?Domain
    {
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['host'])) {
            return null;
        }

        $host = $parsedUrl['host'];

        return self::matchDomainByHost($this->enabledDomains($scope), $host);
    }

    /** Match only within the caller's already scoped domain collection. */
    public static function matchDomainByHost(array $domains, string $host): ?Domain
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $matchedDomain = null;
        $maxMatchLength = 0;

        foreach ($domains as $domain) {
            if (!$domain instanceof Domain) {
                continue;
            }
            $domainName = strtolower(rtrim(trim((string)$domain->getData(Domain::schema_fields_DOMAIN_NAME)), '.'));
            if ($domainName === '') {
                continue;
            }

            if ($domainName === $host) {
                return $domain;
            }

            if (str_ends_with($host, '.' . $domainName)) {
                $matchLength = strlen($domainName);
                if ($matchLength > $maxMatchLength) {
                    $maxMatchLength = $matchLength;
                    $matchedDomain = $domain;
                }
            }
        }

        return $matchedDomain;
    }

    /**
     * 根据站点ID解析域名
     *
     * @param int $siteId 站点ID（0 = 零号站，合法）
     */
    public function resolveDomainBySiteId(int $siteId): ?Domain
    {
        /** @var Domain $domainModel */
        $domainModel = clone $this->objectManager->getInstance(Domain::class);

        $domain = $domainModel->reset()
            ->where(Domain::schema_fields_SITE_ID, $siteId)
            ->where(Domain::schema_fields_ENABLED, 1)
            ->find()
            ->fetch();

        return $domain->getData(Domain::schema_fields_DOMAIN_ID) ? $domain : null;
    }

    /**
     * Scope 媒体基址（绑定优先）。
     */
    public function resolveMediaBaseUrl(ScopeIdentity $scope, string $shared = '/pub/media'): string
    {
        /** @var MediaUrlCowResolver $cow */
        $cow = $this->objectManager->getInstance(MediaUrlCowResolver::class);

        return \rtrim($cow->resolveCowMediaUrl('', $scope, $shared), '/');
    }
}
