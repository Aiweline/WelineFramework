<?php

declare(strict_types=1);

namespace Weline\Websites\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Websites\Model\DomainPool;
use Weline\Websites\Model\Website\LocalDescription as WebsiteLocalDescription;
use Weline\Websites\Service\DomainPoolLifecycleService;
use Weline\Websites\Service\DefaultWebsiteService;
use Weline\Websites\Service\LegacyThemeApplicationMigration;
use Weline\Websites\Service\SiteContactSeedService;
use Weline\Websites\Service\StoreChannelSeedService;
use Weline\Websites\Service\WebsiteBrandIdentitySeedService;

class Upgrade implements UpgradeInterface
{
    /**
     * 升级：1) 确保默认网站存在并绑定 127.0.0.1 / localhost；2) 回填 domain_pool.site_created；
     * 3) 幂等补种每个 Website 的 default Store 与 default SalesChannel；
     * 4) 幂等写入全局默认联系地址（英文，可继承）；
     * 5) Website LocalDescription 表（名称/简介多语言，供 LocalModelTranslation）
     */
    public function setup(Setup $setup, Context $context): void
    {
        foreach ([
            WebsiteLocalDescription::class,
        ] as $modelClass) {
            $model = ObjectManager::getInstance($modelClass);
            $runner = ObjectManager::make(ModelSetup::class);
            $runner->putModel($model);
            $model->setup($runner, $context);
        }

        /** @var DefaultWebsiteService $defaultWebsiteService */
        $defaultWebsiteService = ObjectManager::getInstance(DefaultWebsiteService::class);
        $defaultWebsiteService->ensureDefaultWebsite();
        /** @var WebsiteBrandIdentitySeedService $brandIdentitySeed */
        $brandIdentitySeed = ObjectManager::getInstance(WebsiteBrandIdentitySeedService::class);
        $brandIdentitySeed->ensureDefaultWebsiteBrandIdentity();
        /** @var SiteContactSeedService $contactSeed */
        $contactSeed = ObjectManager::getInstance(SiteContactSeedService::class);
        $contactSeed->ensureGlobalDefaults();
        /** @var StoreChannelSeedService $storeChannelSeed */
        $storeChannelSeed = ObjectManager::getInstance(StoreChannelSeedService::class);
        $storeChannelSeed->ensureDefaults();
        /** @var DomainPool $pool */
        $pool = ObjectManager::getInstance(DomainPool::class);
        $pool->syncSiteCreatedFromWebsiteDomainTable();
        /** @var DomainPoolLifecycleService $lifecycle */
        $lifecycle = ObjectManager::getInstance(DomainPoolLifecycleService::class);
        $n = $lifecycle->backfillAllPoolStages();
        if ($n > 0 && \function_exists('w_log_info')) {
            \w_log_info(\sprintf('[Websites Upgrade] 已回填域名池生命周期阶段 %d 条', $n), [], 'websites_upgrade');
        }

        /** @var LegacyThemeApplicationMigration $themeApplicationMigration */
        $themeApplicationMigration = ObjectManager::getInstance(LegacyThemeApplicationMigration::class);
        $migration = $themeApplicationMigration->migrate(
            BP . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'backup'
            . DIRECTORY_SEPARATOR . 'websites-theme-application-migration',
        );
        if (\function_exists('w_log_info')) {
            \w_log_info(
                \sprintf(
                    '[Websites Upgrade] 主题应用迁移：保存%d，未解析%d，备份%s',
                    (int)$migration['saved'],
                    count($migration['unresolved']),
                    (string)$migration['backup_path'],
                ),
                ['unresolved' => $migration['unresolved']],
                'websites_upgrade',
            );
        }
        foreach ($migration['unresolved'] as $unresolved) {
            echo '[Websites Upgrade] 未解析旧应用：'
                . json_encode($unresolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
    }
}
