<?php

declare(strict_types=1);

namespace Weline\MediaManager\Setup\Db\Migration;

use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\MediaManager\Service\AiDrawModelBinder;

class BindMediaManagerAiDrawToCurrentText2image20260708V100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '将媒体管理 AI 作图场景绑定到当前环境可用的文生图模型';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDate(): string
    {
        return '2026-07-08';
    }

    public function install(): bool
    {
        ObjectManager::getInstance(AiDrawModelBinder::class)->bindIfNeeded();

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }
}
