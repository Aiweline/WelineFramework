<?php

declare(strict_types=1);

namespace Weline\Ai\Console\Database;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Setup\Install;
use Weline\Framework\Setup\Db\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Database\ConnectionFactory;

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
            
            // Create setup instance
            $setup = new Setup($conn);
            $context = new Context();
            
            // Get install instance and call setup
            $install = new Install();
            $install->setup($setup, $context);
            
            echo "✅ AI module tables created successfully!\n\n";
            
            // List created tables
            echo "=== Verifying Tables ===\n";
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ai_%' ORDER BY name";
            $stmt = $conn->query($sql);
            $tables = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
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

