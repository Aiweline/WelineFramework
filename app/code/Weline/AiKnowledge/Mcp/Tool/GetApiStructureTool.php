<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Mcp\Tool;

use Weline\AiKnowledge\Service\Collector\ReflectApiCollector;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

/**
 * Get API Structure Tool
 * 
 * Returns the API structure for a given module, including all endpoints,
 * parameters, and return types.
 */
class GetApiStructureTool implements ToolInterface
{
    private ReflectApiCollector $apiCollector;
    
    public function __construct()
    {
        $this->apiCollector = ObjectManager::getInstance(ReflectApiCollector::class);
    }
    
    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Get the API structure for a WelineFramework module. ' .
               'Returns all API endpoints with their routes, parameters, and return types.';
    }
    
    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'module' => [
                    'type' => 'string',
                    'description' => 'The module name (e.g., "Weline_Ai", "Weline_Framework"). ' .
                                   'Leave empty to list all available modules.',
                ],
                'include_phpdoc' => [
                    'type' => 'boolean',
                    'description' => 'Include PHPDoc comments in the output. Default is true.',
                ],
                'include_attributes' => [
                    'type' => 'boolean',
                    'description' => 'Include PHP 8 Attributes in the output. Default is true.',
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
        $moduleName = $arguments['module'] ?? null;
        $includePhpdoc = $arguments['include_phpdoc'] ?? true;
        $includeAttributes = $arguments['include_attributes'] ?? true;
        
        // If no module specified, list all available modules
        if (empty($moduleName)) {
            return $this->listModules();
        }
        
        // Get API structure for the specified module
        $structure = $this->apiCollector->collectModule($moduleName, [
            'include_phpdoc' => $includePhpdoc,
            'include_attributes' => $includeAttributes,
        ]);
        
        if (empty($structure)) {
            return [
                'success' => false,
                'error' => "Module not found or has no API endpoints: {$moduleName}",
            ];
        }
        
        return [
            'success' => true,
            'module' => $moduleName,
            'api_count' => count($structure['endpoints'] ?? []),
            'structure' => $structure,
        ];
    }
    
    /**
     * List all available modules
     */
    private function listModules(): array
    {
        $modules = Env::getInstance()->getModuleList();
        $moduleList = [];
        
        foreach ($modules as $name => $module) {
            if ($module['status'] ?? false) {
                $moduleList[] = [
                    'name' => $name,
                    'path' => $module['base_path'] ?? '',
                    'has_api' => $this->hasApiControllers($module['base_path'] ?? ''),
                ];
            }
        }
        
        return [
            'success' => true,
            'total' => count($moduleList),
            'modules' => $moduleList,
        ];
    }
    
    /**
     * Check if a module has API controllers
     */
    private function hasApiControllers(string $basePath): bool
    {
        $apiPath = $basePath . '/Api';
        $restPath = $basePath . '/Controller/Api';
        
        return is_dir($apiPath) || is_dir($restPath);
    }
}
