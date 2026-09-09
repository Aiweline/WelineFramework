<?php

declare(strict_types=1);

namespace Weline\Seo\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Service\Seed\HanfuSubjectSeedService;

/**
 * 模块安装：写入汉服默认 SEO 主体画像，避免主体管理空态。
 */
class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var SeoSubject $subject */
        $subject = ObjectManager::getInstance(SeoSubject::class);
        if (!$setup->getDb()->tableExist($subject->getOriginTableName())) {
            return;
        }

        try {
            /** @var HanfuSubjectSeedService $seeder */
            $seeder = ObjectManager::getInstance(HanfuSubjectSeedService::class);
            $seeder->seed();
        } catch (\Throwable) {
            // Seed must not block module install.
        }
    }
}
