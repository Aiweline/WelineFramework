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

    public function setup(Setup $setup, Context $context): void
    {
        $from = (string) $context->getFromSetupVersion();
        if ($from !== '' && \version_compare($from, self::VERSION, '>=')) {
            return;
        }

        (new BotToAgentMigrator())->migrate($setup);
    }
}
