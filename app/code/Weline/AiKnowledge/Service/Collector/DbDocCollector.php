<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Service\Collector;

use Weline\Framework\Database\DbManager;
use Weline\Framework\Database\DbManagerFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

/**
 * Database Documentation Collector
 * 
 * Collects documentation from database tables including:
 * - Table structures
 * - Column definitions and comments
 * - Index information
 * - Foreign key relationships
 */
class DbDocCollector implements CollectorInterface
{
    private ?DbManager $dbManager = null;
    
    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'db_doc';
    }
    
    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Collects database table structures and column documentation';
    }
    
    /**
     * Get database manager instance
     */
    private function getDbManager(): DbManager
    {
        if ($this->dbManager === null) {
            $factory = ObjectManager::getInstance(DbManagerFactory::class);
            $this->dbManager = $factory->create();
        }
        return $this->dbManager;
    }
    
    /**
     * @inheritDoc
     */
    public function collect(array $options = []): array
    {
        $items = [];
        $tables = $this->listTables($options['module'] ?? null);
        
        foreach ($tables as $table) {
            $schema = $this->getTableSchema($table['name'], $options);
            if ($schema) {
                $items[] = [
                    'type' => 'db_schema',
                    'source' => 'database',
                    'name' => $table['name'],
                    'module' => $table['module'] ?? null,
                    'content' => $this->formatSchemaContent($table['name'], $schema),
                    'metadata' => [
                        'table' => $table['name'],
                        'columns' => count($schema['columns'] ?? []),
                        'indexes' => count($schema['indexes'] ?? []),
                    ],
                ];
            }
        }
        
        return $items;
    }
    
    /**
     * List all database tables
     * 
     * @param string|null $moduleName Filter by module
     * @return array List of tables
     */
    public function listTables(?string $moduleName = null): array
    {
        try {
            $db = $this->getDbManager();
            $connection = $db->getConnection();
            
            // Get all tables
            $stmt = $connection->query("SHOW TABLES");
            $allTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            $tables = [];
            foreach ($allTables as $tableName) {
                $module = $this->guessModuleFromTable($tableName);
                
                // Filter by module if specified
                if ($moduleName !== null && $module !== $moduleName) {
                    continue;
                }
                
                $tables[] = [
                    'name' => $tableName,
                    'module' => $module,
                ];
            }
            
            return $tables;
        } catch (\Exception $e) {
            // Return empty array if database is not available
            return [];
        }
    }
    
    /**
     * Get table schema
     * 
     * @param string $tableName Table name
     * @param array $options Options
     * @return array|null Schema information
     */
    public function getTableSchema(string $tableName, array $options = []): ?array
    {
        try {
            $db = $this->getDbManager();
            $connection = $db->getConnection();
            
            $includeComments = $options['include_comments'] ?? true;
            
            // Get columns
            $stmt = $connection->query("DESCRIBE `{$tableName}`");
            $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $schema = [
                'table' => $tableName,
                'columns' => [],
                'indexes' => [],
                'foreign_keys' => [],
            ];
            
            foreach ($columns as $column) {
                $columnInfo = [
                    'name' => $column['Field'],
                    'type' => $column['Type'],
                    'nullable' => $column['Null'] === 'YES',
                    'key' => $column['Key'],
                    'default' => $column['Default'],
                    'extra' => $column['Extra'],
                ];
                
                if ($includeComments) {
                    $columnInfo['comment'] = $this->getColumnComment($tableName, $column['Field']);
                }
                
                $schema['columns'][] = $columnInfo;
            }
            
            // Get indexes
            $stmt = $connection->query("SHOW INDEX FROM `{$tableName}`");
            $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $indexMap = [];
            foreach ($indexes as $index) {
                $keyName = $index['Key_name'];
                if (!isset($indexMap[$keyName])) {
                    $indexMap[$keyName] = [
                        'name' => $keyName,
                        'unique' => !$index['Non_unique'],
                        'columns' => [],
                    ];
                }
                $indexMap[$keyName]['columns'][] = $index['Column_name'];
            }
            $schema['indexes'] = array_values($indexMap);
            
            return $schema;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get column comment
     */
    private function getColumnComment(string $tableName, string $columnName): string
    {
        try {
            $db = $this->getDbManager();
            $connection = $db->getConnection();
            
            $dbName = $db->getConfig()['database'] ?? '';
            
            $stmt = $connection->prepare(
                "SELECT COLUMN_COMMENT FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$dbName, $tableName, $columnName]);
            
            return $stmt->fetchColumn() ?: '';
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Guess module name from table name
     */
    private function guessModuleFromTable(string $tableName): ?string
    {
        // Weline tables typically use underscores: weline_module_entity
        $parts = explode('_', $tableName);
        
        if (count($parts) >= 2) {
            $vendor = ucfirst($parts[0]); // e.g., 'weline' -> 'Weline'
            $module = ucfirst($parts[1]); // e.g., 'ai' -> 'Ai'
            
            $moduleName = "{$vendor}_{$module}";
            
            // Check if this module exists
            $modules = Env::getInstance()->getModuleList();
            if (isset($modules[$moduleName])) {
                return $moduleName;
            }
        }
        
        return null;
    }
    
    /**
     * Format schema as readable content
     */
    private function formatSchemaContent(string $tableName, array $schema): string
    {
        $lines = [];
        $lines[] = "# Table: {$tableName}";
        $lines[] = "";
        $lines[] = "## Columns";
        $lines[] = "";
        
        foreach ($schema['columns'] as $column) {
            $nullable = $column['nullable'] ? 'NULL' : 'NOT NULL';
            $key = $column['key'] ? "[{$column['key']}]" : '';
            $comment = !empty($column['comment']) ? " -- {$column['comment']}" : '';
            
            $lines[] = "- `{$column['name']}`: {$column['type']} {$nullable} {$key}{$comment}";
        }
        
        if (!empty($schema['indexes'])) {
            $lines[] = "";
            $lines[] = "## Indexes";
            $lines[] = "";
            
            foreach ($schema['indexes'] as $index) {
                $unique = $index['unique'] ? 'UNIQUE' : '';
                $columns = implode(', ', $index['columns']);
                $lines[] = "- `{$index['name']}`: {$unique} ({$columns})";
            }
        }
        
        return implode("\n", $lines);
    }
}
