<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Ai\Model\AiModel;
use Weline\Database\AbstractMigration;

class CreateTableAiModels20250101V100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Obsolete ai_models table migration kept idempotent; AiModel owns ai_model schema.';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDate(): string
    {
        return '2025-01-01';
    }

    public function getType(): string
    {
        return 'create_table';
    }

    public function getAffectedTables(): array
    {
        return [AiModel::schema_table];
    }

    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }
}
