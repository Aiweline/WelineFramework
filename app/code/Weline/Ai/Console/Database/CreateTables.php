<?php

declare(strict_types=1);

namespace Weline\Ai\Console\Database;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Setup\Install;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\Setup as DbSetup;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Output\Cli\Printing;

/**
 * Console command to manually create AI module database tables
 */
class CreateTables implements CommandInterface
{

    public function execute(array $args = [], array $data = [])
    {
        echo "\n=== Creating AI Module Tables ===\n\n";
        
        try {
            // Get connection
            $connFactory = ObjectManager::getInstance(ConnectionFactory::class);
            $conn = $connFactory->getConnection();
            
            // Create install context
            $context = new Context('Weline_Ai', '1.0.0', 'AI Module Database Installation');
            
            // Create setup instance
            $connector = $connFactory->getConnection();
            $dbSetup = new DbSetup($connector);
            $printing = ObjectManager::getInstance(Printing::class);
            $setup = new Setup($dbSetup, $printing);
            
            // Get install instance and call setup（Install 无构造参数，连接由 setup 内 $setup->getDb() 提供）
            $install = new Install();
            $install->setup($setup, $context);
            
            echo "✅ AI module tables created successfully!\n\n";
            
            // Ensure supplier column is nullable for compatibility（表名使用 getTable 以带前缀）
            try {
                $aiModelTable = $dbSetup->getTable('ai_model');
                $dbSetup->query("ALTER TABLE {$aiModelTable} MODIFY COLUMN supplier VARCHAR(100) NULL");
                echo "✓ Ensured ai_model.supplier is NULL-able for compatibility.\n";
            } catch (\Throwable $e) {
                // ignore if not needed (e.g. PostgreSQL 使用不同语法)
            }

            // List created tables（依赖当前数据库类型：SQLite 用 sqlite_master，MySQL/Pg 可用 information_schema）
            echo "=== Verifying Tables ===\n";
            $prefix = $dbSetup->getTablePrefix();
            $tables = [];
            foreach (['ai_model', 'ai_api_key', 'ai_tenant', 'ai_model_monitoring'] as $base) {
                if ($conn->tableExist($dbSetup->getTable($base))) {
                    $tables[] = ['name' => $prefix . $base];
                }
            }
            
            if ($tables) {
                foreach ($tables as $table) {
                    $tableName = $table['name'] ?? '';
                    echo "  ✓ {$tableName}\n";
                }
            } else {
                echo "  No AI tables found.\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ Error creating tables: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        }
    }

    public function tip(): string
    {
        return '手动创建AI模块的数据库表';
    }
    
    public function help(array $args = [], array $data = []): string
    {
        return '手动创建AI模块的数据库表。用法: php bin/w ai:database:create-tables';
    }
}

