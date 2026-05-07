<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Ai\Model\AiApiKey;
use Weline\Database\AbstractMigration;

class UpdateApiKeyQuotaFields20250114V200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure Weline AI API-key quota fields are owned by the AiApiKey model schema.';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function getDate(): string
    {
        return '2025-01-14';
    }

    public function getAffectedTables(): array
    {
        return [AiApiKey::schema_table];
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
