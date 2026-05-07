<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Database\AbstractMigration;

class AddUserBalanceFields20250114V200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Obsolete frontend_user balance migration kept idempotent for setup compatibility.';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function getDate(): string
    {
        return '2025-01-14';
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
