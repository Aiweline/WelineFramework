<?php

declare(strict_types=1);

namespace Weline\Smtp\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Smtp\Service\MailTemplateSeedCopyCatalog;
use Weline\Smtp\Service\MailTemplateSeeder;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * upgrade 全量种子邮件模板（R7）；页面进入亦可懒同步。
 */
final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        try {
            MailTemplateSeedCopyCatalog::materializeFiles();
            /** @var MailTemplateSeeder $seeder */
            $seeder = ObjectManager::getInstance(MailTemplateSeeder::class);
            $seeder->syncAll(SystemConfig::SCOPE_GLOBAL);
        } catch (\Throwable $e) {
            // Soft: 表尚未就绪或 provider 依赖未装时不阻断 upgrade
            w_log_warning('Smtp MailTemplateSeeder syncAll: ' . $e->getMessage(), [], 'smtp');
        }
    }
}
