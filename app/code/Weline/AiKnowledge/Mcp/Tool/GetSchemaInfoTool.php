<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Mcp\Tool;

use Weline\AiKnowledge\Service\Collector\DbDocCollector;
use Weline\Framework\Manager\ObjectManager;

/**
 * Get Schema Info Tool
 * 
 * Returns database schema information including tables, columns, and relationships.
 */
class GetSchemaInfoTool implements ToolInterface
{
    private DbDocCollector $dbDocCollector;
    
    public function __construct()
    {
        $this->dbDocCollector = ObjectManager::getInstance(DbDocCollector::class);
    }
    
    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Get database schema information for WelineFramework. ' .
               'Returns table structures, column definitions, and relationships.';
    }
    
    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'The table name to get schema for. Leave empty to list all tables.',
                ],
                'module' => [
                    'type' => 'string',
                    'description' => 'Filter tables by module name.',
                ],
                'include_comments' => [
                    'type' => 'boolean',
                    'description' => 'Include column comments. Default is true.',
                ],
            ],
            'required' => [],
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function execute(array $arguments): mixed
    {
        $tableName = $arguments['table'] ?? null;
        $moduleName = $arguments['module'] ?? null;
        $includeComments = $arguments['include_comments'] ?? true;
        
        if (empty($tableName)) {
            // List all tables
            $tables = $this->dbDocCollector->listTables($moduleName);
            
            return [
                'success' => true,
                'total' => count($tables),
                'tables' => $tables,
            ];
        }
        
        // Get specific table schema
        $schema = $this->dbDocCollector->getTableSchema($tableName, [
            'include_comments' => $includeComments,
        ]);
        
        if (empty($schema)) {
            return [
                'success' => false,
                'error' => "Table not found: {$tableName}",
            ];
        }
        
        return [
            'success' => true,
            'table' => $tableName,
            'schema' => $schema,
        ];
    }
}
