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
        
        $connection = $this->connectionFactory->getConnection();
        $tables = ['ai_tenant', 'ai_model', 'ai_api_key'];
        
        foreach ($tables as $table) {
            echo "📋 Table: {$table}\n";
            echo "----------------------------------------\n";
            
            try {
                $columns = $connection->query("PRAGMA table_info({$table})")->fetchArray();
                
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

