<?php
namespace Weline\Test\Setup\Db\Migration;

use Weline\Database\Interface\MigrationInterface;

class CreateTableTest20250101V100 implements MigrationInterface
{
    public function install(): bool { return true; }
    public function uninstall(): bool { return true; }
    public function getInfo(): array { return []; }
    public function validate(): bool { return true; }
    public function getDependencies(): array { return []; }
    public function getDescription(): string { return "创建测试表"; }
    public function getVersion(): string { return "1.0.0"; }
    public function getDate(): string { return "20250101"; }
}