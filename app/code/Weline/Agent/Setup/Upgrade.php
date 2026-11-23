<?php

declare(strict_types=1);

namespace Weline\Agent\Setup;

use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

/**
 * Weline_Agent 升级：Bot → Agent 数据迁移。
 */
class Upgrade implements UpgradeInterface
{
    public const VERSION = '1.1.0';
    public const GENERAL_ROLE_VERSION = '1.2.0';
    public const PLUGIN_VERSION = '1.3.0';
    public const ORG_VERSION = '1.4.0';

    public function setup(Setup $setup, Context $context): void
    {
        $from = (string) $context->getFromSetupVersion();

        if ($from === '' || \version_compare($from, self::VERSION, '<')) {
            (new BotToAgentMigrator())->migrate($setup);
        }

        if ($from === '' || \version_compare($from, self::GENERAL_ROLE_VERSION, '<')) {
            (new GeneralRoleSeeder())->ensure();
        }

        if ($from === '' || \version_compare($from, self::PLUGIN_VERSION, '<')) {
            (new PluginSeeder())->seed();
        }

        if ($from === '' || \version_compare($from, self::ORG_VERSION, '<')) {
            (new OrgRoleSeeder())->ensure();
        }
    }
}
