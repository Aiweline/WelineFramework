<?php

declare(strict_types=1);

namespace Weline\Websites\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

class Upgrade implements UpgradeInterface
{
    /** 本地开发默认域名，升级时自动绑定到默认网站 */
    private const LOCAL_DEFAULT_DOMAINS = ['127.0.0.1', 'localhost'];

    /**
     * 升级：1) 确保默认网站存在并绑定 127.0.0.1 / localhost；2) 回填 domain_pool.site_created
     */
    public function setup(Setup $setup, Context $context): void
    {
        $this->ensureDefaultWebsiteWithLocalDomains();
        /** @var DomainPool $pool */
        $pool = ObjectManager::getInstance(DomainPool::class);
        $pool->syncSiteCreatedFromWebsiteDomainTable();
    }

    /**
     * 确保默认网站存在，并为其添加 127.0.0.1、localhost 域名（若尚未存在），用于本地自动建站
     */
    private function ensureDefaultWebsiteWithLocalDomains(): void
    {
        /** @var Website $website */
        $website = ObjectManager::getInstance(Website::class);
        $existing = clone $website;
        $existing->clearQuery()->where(Website::schema_fields_CODE, 'default')->find()->fetch();
        if (!$existing->getWebsiteId()) {
            $website->clearData(true)
                ->setWebsiteId(0)
                ->setName('默认网站')
                ->setCode('default')
                ->setUrl('http://localhost')
                ->setDefaultCurrency('CNY')
                ->setDefaultLanguage('zh_Hans_CN')
                ->setDefaultTimezone('Asia/Shanghai')
                ->save(true);
            $websiteId = 1;
        } else {
            $websiteId = $existing->getWebsiteId();
        }

        /** @var WebsiteDomain $domainModel */
        $domainModel = ObjectManager::getInstance(WebsiteDomain::class);
        $existingDomains = $domainModel->getWebsiteDomains($websiteId);
        $hasPrimary = false;
        foreach ($existingDomains as $row) {
            if (!empty($row[WebsiteDomain::schema_fields_IS_PRIMARY])) {
                $hasPrimary = true;
                break;
            }
        }
        $existingDomainSet = array_column($existingDomains, WebsiteDomain::schema_fields_DOMAIN);

        $subPath = '';
        $firstNew = true;
        foreach (self::LOCAL_DEFAULT_DOMAINS as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '' || in_array($domain, $existingDomainSet, true)) {
                continue;
            }
            /** @var WebsiteDomain $newDomain */
            $newDomain = ObjectManager::getInstance(WebsiteDomain::class, [], false);
            $newDomain->setWebsiteId($websiteId);
            $newDomain->setDomain($domain);
            $newDomain->setSubPath($subPath);
            $newDomain->setIsPrimary(!$hasPrimary && $firstNew);
            $newDomain->setStatus(WebsiteDomain::STATUS_ACTIVE);
            $newDomain->save();
            $firstNew = false;
            $existingDomainSet[] = $domain;
        }
    }
}
