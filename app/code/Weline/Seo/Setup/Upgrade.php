<?php

declare(strict_types=1);

namespace Weline\Seo\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Service\Seed\HanfuSubjectSeedService;
use Weline\Seo\Service\SeoPlatformCode;

/**
 * 模块升级：幂等补种汉服默认 SEO 主体画像；归一 Google 平台别名。
 */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var SeoSubject $subject */
        $subject = ObjectManager::getInstance(SeoSubject::class);
        if ($setup->getDb()->tableExist($subject->getOriginTableName())) {
            try {
                /** @var HanfuSubjectSeedService $seeder */
                $seeder = ObjectManager::getInstance(HanfuSubjectSeedService::class);
                $seeder->seed();
            } catch (\Throwable) {
                // Seed must not block module upgrade.
            }
        }

        /** @var SeoAccount $accountModel */
        $accountModel = ObjectManager::getInstance(SeoAccount::class);
        if (!$setup->getDb()->tableExist($accountModel->getOriginTableName())) {
            return;
        }

        try {
            foreach ($accountModel->reset()->select()->fetchArray() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row[SeoAccount::schema_fields_ACCOUNT_ID] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $platform = (string)($row[SeoAccount::schema_fields_PLATFORM] ?? '');
                $provider = (string)($row[SeoAccount::schema_fields_PROVIDER] ?? '');
                $canonical = SeoPlatformCode::canonicalize($platform !== '' ? $platform : $provider);
                if ($canonical === '') {
                    continue;
                }

                $account = ObjectManager::getInstance(SeoAccount::class)->reset()->load($id);
                if (!$account->getId()) {
                    continue;
                }

                $dirty = false;
                if ($platform !== $canonical) {
                    $account->setData(SeoAccount::schema_fields_PLATFORM, $canonical);
                    $dirty = true;
                }
                if ($provider !== $canonical) {
                    $account->setData(SeoAccount::schema_fields_PROVIDER, $canonical);
                    $dirty = true;
                }
                if (SeoPlatformCode::isGoogle($canonical)
                    && (int)($row[SeoAccount::schema_fields_ENABLE_CRON_PUSH_URLS] ?? 0) === 1) {
                    $account->setData(SeoAccount::schema_fields_ENABLE_CRON_PUSH_URLS, 0);
                    $dirty = true;
                }
                if ($dirty) {
                    $account->save();
                }
            }
        } catch (\Throwable) {
            // Alias normalize must not block upgrade.
        }
    }
}
