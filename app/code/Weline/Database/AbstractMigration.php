<?php

declare(strict_types=1);

namespace Weline\Database;

use Weline\Database\Interface\MigrationInterface;

/**
 * 迁移脚本抽象基类
 *
 * 新迁移脚本推荐继承此类，仅需实现 install() 和 uninstall()。
 * 旧脚本如果直接实现 MigrationInterface 且未定义新方法，
 * MigrationService 会通过 method_exists 回退到默认值，不会报错。
 */
abstract class AbstractMigration implements MigrationInterface
{
    public function getInfo(): array
    {
        return [
            'description' => $this->getDescription(),
            'version'     => $this->getVersion(),
            'date'        => $this->getDate(),
            'type'        => $this->getType(),
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
            'tables'   => [],
            'columns'  => [],
        ];
    }
}
