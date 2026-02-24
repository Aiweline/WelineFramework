<?php

declare(strict_types=1);

namespace Weline\Ai\Console\Test;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Database\ConnectionFactory;

/**
 * Check AI tables structure
 */
class CheckTableStructure implements CommandInterface
{

    private ConnectionFactory $connectionFactory;

    public function __construct(ConnectionFactory $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
    }

    public function execute(array $args = [], array $data = [])
    {
        echo "\n=== AI Tables Structure Check ===\n\n";
        
        $conn = $this->connectionFactory->getConnector();
        $prefix = $conn->getConfigProvider()->getPrefix() ?? '';
        $baseTables = ['ai_tenant', 'ai_model', 'ai_api_key'];
        
        foreach ($baseTables as $base) {
            $table = ($prefix !== '' && !str_starts_with($base, $prefix)) ? $prefix . $base : $base;
            echo "📋 Table: {$table}\n";
            echo "----------------------------------------\n";
            
            try {
                // PRAGMA 为 SQLite 语法；MySQL/Pg 需用 information_schema / pg_catalog
                $columns = $conn->query("PRAGMA table_info({$table})")->fetchArray();
                
                foreach ($columns as $column) {
                    echo sprintf(
                        "  %s: %s %s %s\n",
                        $column['name'],
                        $column['type'],
                        $column['notnull'] ? 'NOT NULL' : 'NULL',
                        $column['dflt_value'] ? "DEFAULT {$column['dflt_value']}" : ''
                    );
                }
                echo "\n";
            } catch (\Exception $e) {
                echo "  ❌ Error: " . $e->getMessage() . "\n\n";
            }
        }
    }

    public function tip(): string
    {
        return '检查AI模块表结构';
    }

    public function help(): array|string
    {
        return 'Check AI module database table structures';
    }
}

