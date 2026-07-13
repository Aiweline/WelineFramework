<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Migration;

/**
 * Module-neutral migration defaults shared by every migration runtime.
 */
abstract class AbstractMigration implements MigrationMetadataInterface
{
    public function getInfo(): array
    {
        return [
            'description' => $this->getDescription(),
            'version' => $this->getVersion(),
            'date' => $this->getDate(),
            'type' => $this->getType(),
        ];
    }

    public function validate(): bool
    {
        return true;
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return '';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDate(): string
    {
        return '';
    }

    public function getType(): string
    {
        return 'modify_table';
    }

    public function getAffectedTables(): array
    {
        return [];
    }

    public function requiresBackup(): bool
    {
        return false;
    }

    public function getBackupStrategy(): array
    {
        return [
            'strategy' => 'none',
            'tables' => [],
            'columns' => [],
        ];
    }
}
