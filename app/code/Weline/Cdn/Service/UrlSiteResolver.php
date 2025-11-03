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
use Weline\Websites\Model\Website;

/**
 * URL站点解析服务
 * 
 * 根据URL的最长匹配（不区分大小写）解析出对应的站点和域名
 */
class UrlSiteResolver
{
    /**
     * @var Website
     */
    private Website $websiteModel;

    /**
     * @var Domain
     */
    private Domain $domainModel;

    /**
     * 构造函数
     */
    public function __construct(
        Website $websiteModel,
        Domain $domainModel
    ) {
        $this->websiteModel = $websiteModel;
        $this->domainModel = $domainModel;
    }

    /**
     * @DESC          # 解析URL对应的站点和域名
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $url URL地址
     * @return array ['site_id' => int|null, 'domain_id' => int|null]
     */
    public function resolve(string $url): array
    {
        $url = trim($url);
        if (empty($url)) {
            return ['site_id' => null, 'domain_id' => null];
        }

        // 解析URL的host部分
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['host'])) {
            return ['site_id' => null, 'domain_id' => null];
        }

        $host = strtolower($parsedUrl['host']);

        // 1. 先尝试匹配域名（最长匹配）
        $domainId = $this->matchDomain($host);
        
        // 2. 如果找到域名，获取关联的站点
        if ($domainId) {
            try {
                /** @var Domain $domain */
                $domain = $this->domainModel->clear()->reset()->load($domainId);
                if ($domain->getId()) {
                    $siteId = $domain->getData(Domain::fields_SITE_ID);
                    return [
                        'site_id' => $siteId ? (int)$siteId : null,
                        'domain_id' => $domainId
                    ];
                }
            } catch (\Exception $e) {
                // 忽略错误
            }
        }

        // 3. 如果没找到域名，尝试匹配网站URL（最长匹配）
        $siteId = $this->matchWebsite($host);

        return [
            'site_id' => $siteId,
            'domain_id' => $domainId
        ];
    }

    /**
     * @DESC          # 匹配域名（最长匹配，不区分大小写）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $host
     * @return int|null
     */
    private function matchDomain(string $host): ?int
    {
        try {
            // 获取所有启用的域名
            $domains = $this->domainModel->clear()
                ->reset()
                ->where(Domain::fields_ENABLED, 1)
                ->select()
                ->fetchArray();

            $matchedDomain = null;
            $maxMatchLength = 0;

            foreach ($domains as $domain) {
                $domainName = strtolower((string)$domain[Domain::fields_DOMAIN_NAME]);
                
                // 检查host是否包含domain_name（或完全匹配）
                if ($host === $domainName || str_ends_with($host, '.' . $domainName)) {
                    $matchLength = strlen($domainName);
                    if ($matchLength > $maxMatchLength) {
                        $maxMatchLength = $matchLength;
                        $matchedDomain = $domain;
                    }
                }
            }

            return $matchedDomain ? (int)$matchedDomain[Domain::fields_ID] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * @DESC          # 匹配网站URL（最长匹配，不区分大小写）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * 
     * @param string $host
     * @return int|null
     */
    private function matchWebsite(string $host): ?int
    {
        try {
            // 获取所有网站
            $websites = $this->websiteModel->clear()
                ->reset()
                ->select()
                ->fetchArray();

            $matchedWebsite = null;
            $maxMatchLength = 0;

            foreach ($websites as $website) {
                $websiteUrl = (string)$website[Website::fields_URL];
                if (empty($websiteUrl)) {
                    continue;
                }

                $parsedWebsiteUrl = parse_url($websiteUrl);
                if (!isset($parsedWebsiteUrl['host'])) {
                    continue;
                }

                $websiteHost = strtolower($parsedWebsiteUrl['host']);

                // 检查host是否包含website_host（或完全匹配）
                if ($host === $websiteHost || str_ends_with($host, '.' . $websiteHost)) {
                    $matchLength = strlen($websiteHost);
                    if ($matchLength > $maxMatchLength) {
                        $maxMatchLength = $matchLength;
                        $matchedWebsite = $website;
                    }
                }
            }

            return $matchedWebsite ? (int)$matchedWebsite[Website::fields_ID] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
