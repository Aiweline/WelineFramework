<?php

declare(strict_types=1);

namespace Weline\Theme\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Backend\Setup\Ui\IconDataMigrator;

class Upgrade implements UpgradeInterface
{
    public const VERSION = '2.2.41';

    public function setup(Data\Setup $setup, Data\Context $context): void
    {
        $this->migrateSemanticIcons();
    }

    private function migrateSemanticIcons(): void
    {
        try {
            ObjectManager::getInstance(IconDataMigrator::class)->migrate();
        } catch (\Throwable $e) {
            throw new \Weline\Framework\App\Exception(
                __('Weline UI 2.0 语义图标迁移失败：%{1}', [$e->getMessage()]),
                0,
                $e,
            );
        }
    }

    public function getVersion(): string
    {
        return self::VERSION;
    }
}
