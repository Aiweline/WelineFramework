<?php

declare(strict_types=1);

namespace Weline\Websites\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Websites\Model\Website;

class Install implements InstallInterface
{
    /**
     * 安装时若无默认网站则插入一条（业务初始化，计划 3.10）
     */
    public function setup(Setup $setup, Context $context): void
    {
        /** @var Website $website */
        $website = ObjectManager::getInstance(Website::class);
        $existing = clone $website;
        $existing->clearQuery()->where(Website::schema_fields_CODE, Website::CODE_DEFAULT)->find()->fetch();
        if ($existing->getWebsiteId()) {
            return;
        }
        $website->clearData(true)
            ->setWebsiteId(1)
            ->setName('默认网站')
            ->setCode(Website::CODE_DEFAULT)
            ->setUrl('http://localhost')
            ->setDefaultCurrency('CNY')
            ->setDefaultLanguage('zh_Hans_CN')
            ->setDefaultTimezone('Asia/Shanghai')
            ->save(true);
    }
}
