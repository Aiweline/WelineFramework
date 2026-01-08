<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Service\Collector;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reflect API Collector
 * 
 * Collects API documentation from PHP source code using reflection:
 * - PHP 8 Attributes (ApiDoc, Acl, etc.)
 * - PHPDoc comments
 * - Method signatures and parameters
 * - Return types
 */
class ReflectApiCollector implements CollectorInterface
{
    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'reflect_api';
    }
    
    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Collects API documentation from PHP 8 Attributes and PHPDoc comments';
    }
    
    /**
     * @inheritDoc
     */
    public function collect(array $options = []): array
    {
        $items = [];
        $modules = Env::getInstance()->getModuleList();
        
        foreach ($modules as $moduleName => $module) {
            if (!($module['status'] ?? false)) {
                continue;
            }
            
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath)) {
                continue;
            }
            
            // Scan API controllers
            $apiPaths = [
                $basePath . '/Api',
                $basePath . '/Controller/Api',
            ];
            
            foreach ($apiPaths as $apiPath) {
                if (is_dir($apiPath)) {
                    $items = array_merge($items, $this->scanApiDirectory($apiPath, $moduleName, $options));
                }
            }
        }
        
        return $items;
    }
    
    /**
     * Collect API structure for a specific module
     * 
     * @param string $moduleName Module name
     * @param array $options Collection options
     * @return array Module API structure
     */
    public function collectModule(string $moduleName, array $options = []): array
    {
        $modules = Env::getInstance()->getModuleList();
        
        if (!isset($modules[$moduleName]) || !($modules[$moduleName]['status'] ?? false)) {
            return [];
        }
        
        $basePath = $modules[$moduleName]['base_path'] ?? '';
        if (empty($basePath)) {
            return [];
        }
        
        $endpoints = [];
        
        // Scan API controllers
        $apiPaths = [
            $basePath . '/Api',
            $basePath . '/Controller/Api',
        ];
        
        foreach ($apiPaths as $apiPath) {
            if (is_dir($apiPath)) {
                $items = $this->scanApiDirectory($apiPath, $moduleName, $options);
                foreach ($items as $item) {
                    $endpoints[] = $item['metadata'] ?? [];
                }
            }
        }
        
        return [
            'module' => $moduleName,
            'base_path' => $basePath,
            'endpoints' => $endpoints,
        ];
    }
    
    /**
     * Scan an API directory for controllers
     */
    private function scanApiDirectory(string $path, string $moduleName, array $options): array
    {
        $items = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            
            $className = $this->getClassNameFromFile($file->getPathname());
            if ($className === null) {
                continue;
            }
            
            try {
                $classItems = $this->collectFromClass($className, $moduleName, $options);
                $items = array_merge($items, $classItems);
            } catch (\Throwable $e) {
                // Skip classes that can't be loaded
                continue;
            }
        }
        
        return $items;
    }
    
    /**
     * Get class name from a PHP file
     */
    private function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        // Extract namespace
        $namespace = null;
        if (preg_match('/namespace\s+([^\s;]+)/m', $content, $matches)) {
            $namespace = $matches[1];
        }
        
        // Extract class name
        $className = null;
        if (preg_match('/class\s+(\w+)/m', $content, $matches)) {
            $className = $matches[1];
        }
        
        if ($namespace && $className) {
            return $namespace . '\\' . $className;
        }
        
        return null;
    }
    
    /**
     * Collect API documentation from a class
     */
    private function collectFromClass(string $className, string $moduleName, array $options): array
    {
        $items = [];
        
        if (!class_exists($className)) {
            return $items;
        }
        
        $reflection = new ReflectionClass($className);
        
        // Skip abstract classes and interfaces
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return $items;
        }
        
        $includePhpdoc = $options['include_phpdoc'] ?? true;
        $includeAttributes = $options['include_attributes'] ?? true;
        
        // Collect class-level information
        $classInfo = [
            'class' => $className,
            'short_name' => $reflection->getShortName(),
            'namespace' => $reflection->getNamespaceName(),
            'file' => $reflection->getFileName(),
        ];
        
        if ($includePhpdoc) {
            $classInfo['phpdoc'] = $this->parsePhpDoc($reflection->getDocComment() ?: '');
        }
        
        if ($includeAttributes) {
            $classInfo['attributes'] = $this->getAttributes($reflection);
        }
        
        // Collect method-level information
        $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        
        foreach ($publicMethods as $method) {
            // Skip inherited methods
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }
            
            // Skip magic methods
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            
            $methodInfo = $this->collectMethodInfo($method, $options);
            
            $items[] = [
                'type' => 'api_endpoint',
                'source' => 'reflection',
                'name' => "{$className}::{$method->getName()}",
                'module' => $moduleName,
                'content' => $this->formatApiContent($classInfo, $methodInfo),
                'metadata' => [
                    'class' => $className,
                    'method' => $method->getName(),
                    'parameters' => $methodInfo['parameters'],
                    'return_type' => $methodInfo['return_type'],
                    'attributes' => $methodInfo['attributes'] ?? [],
                    'phpdoc' => $methodInfo['phpdoc'] ?? [],
                ],
            ];
        }
        
        return $items;
    }
    
    /**
     * Collect information from a method
     */
    private function collectMethodInfo(ReflectionMethod $method, array $options): array
    {
        $info = [
            'name' => $method->getName(),
            'parameters' => [],
            'return_type' => null,
        ];
        
        // Collect parameters
        foreach ($method->getParameters() as $param) {
            $paramInfo = [
                'name' => $param->getName(),
                'type' => $param->hasType() ? (string) $param->getType() : 'mixed',
                'optional' => $param->isOptional(),
                'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
            $info['parameters'][] = $paramInfo;
        }
        
        // Get return type
        if ($method->hasReturnType()) {
            $info['return_type'] = (string) $method->getReturnType();
        }
        
        // Parse PHPDoc
        if ($options['include_phpdoc'] ?? true) {
            $info['phpdoc'] = $this->parsePhpDoc($method->getDocComment() ?: '');
        }
        
        // Get attributes
        if ($options['include_attributes'] ?? true) {
            $info['attributes'] = $this->getMethodAttributes($method);
        }
        
        return $info;
    }
    
    /**
     * Parse PHPDoc comment
     */
    private function parsePhpDoc(string $docComment): array
    {
        $result = [
            'summary' => '',
            'description' => '',
            'params' => [],
            'return' => null,
            'throws' => [],
            'tags' => [],
        ];
        
        if (empty($docComment)) {
            return $result;
        }
        
        // Remove comment markers
        $docComment = preg_replace('/^\/\*\*|\*\/$/m', '', $docComment);
        $lines = explode("\n", $docComment);
        
        $inDescription = true;
        $descriptionLines = [];
        
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*\*\s?/', '', $line));
            
            if (empty($line)) {
                if (!empty($descriptionLines)) {
                    $inDescription = false;
                }
                continue;
            }
            
            // Parse tags
            if (str_starts_with($line, '@')) {
                $inDescription = false;
                
                if (preg_match('/^@param\s+(\S+)\s+\$(\w+)\s*(.*)$/', $line, $matches)) {
                    $result['params'][] = [
                        'type' => $matches[1],
                        'name' => $matches[2],
                        'description' => $matches[3],
                    ];
                } elseif (preg_match('/^@return\s+(\S+)\s*(.*)$/', $line, $matches)) {
                    $result['return'] = [
                        'type' => $matches[1],
                        'description' => $matches[2],
                    ];
                } elseif (preg_match('/^@throws\s+(\S+)\s*(.*)$/', $line, $matches)) {
                    $result['throws'][] = [
                        'type' => $matches[1],
                        'description' => $matches[2],
                    ];
                } else {
                    $result['tags'][] = $line;
                }
            } elseif ($inDescription) {
                $descriptionLines[] = $line;
            }
        }
        
        if (!empty($descriptionLines)) {
            $result['summary'] = $descriptionLines[0];
            if (count($descriptionLines) > 1) {
                $result['description'] = implode("\n", array_slice($descriptionLines, 1));
            }
        }
        
        return $result;
    }
    
    /**
     * Get class attributes
     */
    private function getAttributes(ReflectionClass $reflection): array
    {
        $attributes = [];
        
        foreach ($reflection->getAttributes() as $attribute) {
            try {
                $instance = $attribute->newInstance();
                $attributes[] = [
                    'name' => $attribute->getName(),
                    'arguments' => $attribute->getArguments(),
                    'data' => method_exists($instance, 'getData') ? $instance->getData() : [],
                ];
            } catch (\Throwable $e) {
                $attributes[] = [
                    'name' => $attribute->getName(),
                    'arguments' => $attribute->getArguments(),
                ];
            }
        }
        
        return $attributes;
    }
    
    /**
     * Get method attributes
     */
    private function getMethodAttributes(ReflectionMethod $method): array
    {
        $attributes = [];
        
        foreach ($method->getAttributes() as $attribute) {
            try {
                $instance = $attribute->newInstance();
                $attributes[] = [
                    'name' => $attribute->getName(),
                    'arguments' => $attribute->getArguments(),
                    'data' => method_exists($instance, 'getData') ? $instance->getData() : [],
                ];
            } catch (\Throwable $e) {
                $attributes[] = [
                    'name' => $attribute->getName(),
                    'arguments' => $attribute->getArguments(),
                ];
            }
        }
        
        return $attributes;
    }
    
    /**
     * Format API documentation as readable content
     */
    private function formatApiContent(array $classInfo, array $methodInfo): string
    {
        $lines = [];
        $lines[] = "# API: {$classInfo['class']}::{$methodInfo['name']}()";
        $lines[] = "";
        
        // Summary from PHPDoc
        if (!empty($methodInfo['phpdoc']['summary'])) {
            $lines[] = $methodInfo['phpdoc']['summary'];
            $lines[] = "";
        }
        
        // Description
        if (!empty($methodInfo['phpdoc']['description'])) {
            $lines[] = $methodInfo['phpdoc']['description'];
            $lines[] = "";
        }
        
        // Attributes
        if (!empty($methodInfo['attributes'])) {
            $lines[] = "## Attributes";
            $lines[] = "";
            foreach ($methodInfo['attributes'] as $attr) {
                $lines[] = "- `{$attr['name']}`";
                if (!empty($attr['data'])) {
                    foreach ($attr['data'] as $key => $value) {
                        if (is_scalar($value)) {
                            $lines[] = "  - {$key}: {$value}";
                        }
                    }
                }
            }
            $lines[] = "";
        }
        
        // Parameters
        if (!empty($methodInfo['parameters'])) {
            $lines[] = "## Parameters";
            $lines[] = "";
            foreach ($methodInfo['parameters'] as $param) {
                $optional = $param['optional'] ? '(optional)' : '(required)';
                $default = $param['optional'] && $param['default'] !== null 
                    ? " = " . var_export($param['default'], true) 
                    : '';
                $lines[] = "- `\${$param['name']}`: {$param['type']} {$optional}{$default}";
            }
            $lines[] = "";
        }
        
        // Return type
        if ($methodInfo['return_type']) {
            $lines[] = "## Returns";
            $lines[] = "";
            $lines[] = "- Type: `{$methodInfo['return_type']}`";
            if (!empty($methodInfo['phpdoc']['return']['description'])) {
                $lines[] = "- Description: {$methodInfo['phpdoc']['return']['description']}";
            }
            $lines[] = "";
        }
        
        return implode("\n", $lines);
    }
}
