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

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
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

        /** @var Domain $domainModel */
        $domainModel = $this->objectManager->getInstance(Domain::class);

        $query = $domainModel->reset()
            ->where(Domain::schema_fields_ENABLED, 1);
        if ($scope !== null && $scope->websiteId !== null) {
            $query->where(Domain::schema_fields_SITE_ID, (int)$scope->websiteId);
        }
        $domains = $query->select()->fetch()->getItems();

        $matchedDomain = null;
        $maxMatchLength = 0;

        foreach ($domains as $domain) {
            $domainName = $domain->getData(Domain::schema_fields_DOMAIN_NAME);

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
        $domainModel = $this->objectManager->getInstance(Domain::class);

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

