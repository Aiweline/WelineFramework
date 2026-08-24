<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Service\Collector;

use Weline\Framework\App\Env;
use ReflectionClass;

/**
 * Source Graph Collector
 * 
 * Builds a code topology index by scanning:
 * - Class hierarchies (extends, implements)
 * - Dependency injection relationships
 * - Service container bindings
 * - Plugin/Interceptor configurations
 */
class SourceGraphCollector implements CollectorInterface
{
    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return 'source_graph';
    }
    
    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Builds code topology index including class hierarchies and dependencies';
    }
    
    /**
     * @inheritDoc
     */
    public function collect(array $options = []): array
    {
        $items = [];
        $scanPaths = $options['scan_paths'] ?? [BP . '/app/code'];
        
        foreach ($scanPaths as $scanPath) {
            if (!is_dir($scanPath)) {
                continue;
            }
            
            $items = array_merge($items, $this->scanPath($scanPath, $options));
        }
        
        return $items;
    }
    
    /**
     * Scan a directory for PHP classes
     */
    private function scanPath(string $path, array $options): array
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
            
            // Skip certain directories
            $filePath = $file->getPathname();
            if ($this->shouldSkip($filePath)) {
                continue;
            }
            
            $classInfo = $this->analyzeFile($filePath);
            if ($classInfo !== null) {
                $items[] = $classInfo;
            }
        }
        
        return $items;
    }
    
    /**
     * Check if a file should be skipped
     */
    private function shouldSkip(string $filePath): bool
    {
        $skipPatterns = [
            '/vendor/',
            '/test/',
            '/Test/',
            '/UnitTest/',
            '/view/',
            '/statics/',
        ];
        
        foreach ($skipPatterns as $pattern) {
            if (str_contains($filePath, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Analyze a PHP file and extract class information
     */
    private function analyzeFile(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        
        // Extract namespace
        $namespace = null;
        if (preg_match('/namespace\s+([^\s;]+)/m', $content, $matches)) {
            $namespace = $matches[1];
        }
        
        // Extract class name
        $className = null;
        $isInterface = false;
        $isTrait = false;
        $isAbstract = false;
        
        if (preg_match('/abstract\s+class\s+(\w+)/m', $content, $matches)) {
            $className = $matches[1];
            $isAbstract = true;
        } elseif (preg_match('/interface\s+(\w+)/m', $content, $matches)) {
            $className = $matches[1];
            $isInterface = true;
        } elseif (preg_match('/trait\s+(\w+)/m', $content, $matches)) {
            $className = $matches[1];
            $isTrait = true;
        } elseif (preg_match('/class\s+(\w+)/m', $content, $matches)) {
            $className = $matches[1];
        }
        
        if (!$namespace || !$className) {
            return null;
        }
        
        $fqcn = $namespace . '\\' . $className;
        
        // Extract extends
        $extends = null;
        if (preg_match('/class\s+\w+\s+extends\s+([^\s{]+)/m', $content, $matches)) {
            $extends = $this->resolveClassName($matches[1], $namespace, $content);
        }
        
        // Extract implements
        $implements = [];
        if (preg_match('/class\s+\w+(?:\s+extends\s+[^\s{]+)?\s+implements\s+([^{]+)/m', $content, $matches)) {
            $interfaceList = $matches[1];
            $interfaces = array_map('trim', explode(',', $interfaceList));
            foreach ($interfaces as $interface) {
                $implements[] = $this->resolveClassName($interface, $namespace, $content);
            }
        }
        
        // Extract use traits
        $traits = [];
        if (preg_match_all('/use\s+([^\s;]+);/m', $content, $matches)) {
            foreach ($matches[1] as $trait) {
                // Skip if it's a namespace import (contains \)
                if (!str_contains($trait, '\\') && !str_starts_with($trait, 'use ')) {
                    // This might be a trait usage, but we need more context
                }
            }
        }
        
        // Extract constructor dependencies
        $dependencies = $this->extractConstructorDependencies($content, $namespace);
        
        // Determine module
        $module = $this->guessModuleFromPath($filePath);
        
        return [
            'type' => 'source_graph',
            'source' => 'code_analysis',
            'name' => $fqcn,
            'module' => $module,
            'content' => $this->formatGraphContent($fqcn, $extends, $implements, $dependencies),
            'metadata' => [
                'class' => $fqcn,
                'namespace' => $namespace,
                'short_name' => $className,
                'file' => $filePath,
                'is_interface' => $isInterface,
                'is_trait' => $isTrait,
                'is_abstract' => $isAbstract,
                'extends' => $extends,
                'implements' => $implements,
                'dependencies' => $dependencies,
            ],
        ];
    }
    
    /**
     * Resolve a class name to its fully qualified form
     */
    private function resolveClassName(string $className, string $currentNamespace, string $content): string
    {
        // Already fully qualified
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }
        
        // Check use statements
        $pattern = '/use\s+([^\s;]+(?:\\\\' . preg_quote($className, '/') . '))/m';
        if (preg_match($pattern, $content, $matches)) {
            return $matches[1];
        }
        
        // Check for alias
        $pattern = '/use\s+([^\s;]+)\s+as\s+' . preg_quote($className, '/') . '/m';
        if (preg_match($pattern, $content, $matches)) {
            return $matches[1];
        }
        
        // Assume same namespace
        return $currentNamespace . '\\' . $className;
    }
    
    /**
     * Extract constructor dependencies
     */
    private function extractConstructorDependencies(string $content, string $namespace): array
    {
        $dependencies = [];
        
        // Match constructor with parameters
        if (preg_match('/public\s+function\s+__construct\s*\(([^)]*)\)/m', $content, $matches)) {
            $params = $matches[1];
            
            // Parse each parameter
            if (preg_match_all('/(?:private|protected|public|readonly)?\s*([A-Za-z\\\\]+)\s+\$(\w+)/m', $params, $paramMatches, PREG_SET_ORDER)) {
                foreach ($paramMatches as $match) {
                    $type = $match[1];
                    $name = $match[2];
                    
                    // Skip scalar types
                    if (in_array(strtolower($type), ['int', 'string', 'bool', 'float', 'array', 'object', 'mixed', 'null'])) {
                        continue;
                    }
                    
                    $fqcn = $this->resolveClassName($type, $namespace, $content);
                    $dependencies[] = [
                        'type' => $fqcn,
                        'name' => $name,
                    ];
                }
            }
        }
        
        return $dependencies;
    }
    
    /**
     * Guess module from file path
     */
    private function guessModuleFromPath(string $filePath): ?string
    {
        // Pattern: app/code/Vendor/Module/...
        if (preg_match('#app[/\\\\]code[/\\\\](\w+)[/\\\\](\w+)#', $filePath, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }
        
        return null;
    }
    
    /**
     * Format source graph as readable content
     */
    private function formatGraphContent(string $class, ?string $extends, array $implements, array $dependencies): string
    {
        $lines = [];
        $lines[] = "# Class: {$class}";
        $lines[] = "";
        
        if ($extends) {
            $lines[] = "## Extends";
            $lines[] = "";
            $lines[] = "- `{$extends}`";
            $lines[] = "";
        }
        
        if (!empty($implements)) {
            $lines[] = "## Implements";
            $lines[] = "";
            foreach ($implements as $interface) {
                $lines[] = "- `{$interface}`";
            }
            $lines[] = "";
        }
        
        if (!empty($dependencies)) {
            $lines[] = "## Dependencies (Constructor Injection)";
            $lines[] = "";
            foreach ($dependencies as $dep) {
                $lines[] = "- `{$dep['type']}` as `\${$dep['name']}`";
            }
            $lines[] = "";
        }
        
        return implode("\n", $lines);
    }
}
