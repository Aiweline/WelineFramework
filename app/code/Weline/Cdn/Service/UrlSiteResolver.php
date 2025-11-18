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

/**
 * URL站点解析服务
 * 
 * 根据URL解析对应的站点和域名
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
     * 使用最长匹配原则，找到最匹配的域名
     * 
     * @param string $url URL地址
     * @return Domain|null
     */
    public function resolveDomainByUrl(string $url): ?Domain
    {
        // 解析URL获取主机名
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['host'])) {
            return null;
        }

        $host = $parsedUrl['host'];

        /** @var Domain $domainModel */
        $domainModel = $this->objectManager->getInstance(Domain::class);

        // 获取所有启用的域名
        $domains = $domainModel->reset()
            ->where(Domain::fields_ENABLED, 1)
            ->select()
            ->fetch()
            ->getItems();

        $matchedDomain = null;
        $maxMatchLength = 0;

        // 使用最长匹配原则
        foreach ($domains as $domain) {
            $domainName = $domain->getData(Domain::fields_DOMAIN_NAME);
            
            // 完全匹配
            if ($domainName === $host) {
                return $domain;
            }

            // 检查是否为子域名
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
     * @param int $siteId 站点ID
     * @return Domain|null
     */
    public function resolveDomainBySiteId(int $siteId): ?Domain
    {
        /** @var Domain $domainModel */
        $domainModel = $this->objectManager->getInstance(Domain::class);
        
        $domain = $domainModel->reset()
            ->where(Domain::fields_SITE_ID, $siteId)
            ->where(Domain::fields_ENABLED, 1)
            ->find()
            ->fetch();

        return $domain->getData(Domain::fields_DOMAIN_ID) ? $domain : null;
    }
}

