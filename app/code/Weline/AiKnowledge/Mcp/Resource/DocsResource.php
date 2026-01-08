<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Mcp\Resource;

use Weline\Framework\App\Env;

/**
 * Documentation Resource
 * 
 * Provides access to framework documentation files.
 */
class DocsResource implements ResourceInterface
{
    /**
     * Base URI for docs resources
     */
    private const BASE_URI = 'weline://docs/';
    
    /**
     * @inheritDoc
     */
    public function list(): array
    {
        $resources = [];
        
        // Framework documentation
        $frameworkDocPath = BP . '/app/code/Weline/Framework/doc';
        if (is_dir($frameworkDocPath)) {
            $resources = array_merge($resources, $this->scanDocDirectory($frameworkDocPath, 'framework'));
        }
        
        // Module documentation
        $modules = Env::getInstance()->getModuleList();
        foreach ($modules as $name => $module) {
            if (!($module['status'] ?? false)) {
                continue;
            }
            
            $docPath = ($module['base_path'] ?? '') . '/doc';
            if (is_dir($docPath)) {
                $moduleKey = strtolower(str_replace('_', '-', $name));
                $resources = array_merge($resources, $this->scanDocDirectory($docPath, $moduleKey));
            }
        }
        
        return $resources;
    }
    
    /**
     * Scan a documentation directory
     */
    private function scanDocDirectory(string $path, string $prefix): array
    {
        $resources = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['md', 'txt', 'markdown'])) {
                continue;
            }
            
            $relativePath = str_replace($path . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            
            $resources[] = [
                'uri' => self::BASE_URI . $prefix . '/' . $relativePath,
                'name' => $file->getFilename(),
                'description' => "Documentation: {$prefix}/{$relativePath}",
                'mimeType' => 'text/markdown',
            ];
        }
        
        return $resources;
    }
    
    /**
     * @inheritDoc
     */
    public function read(string $path): string
    {
        // Parse the path to get the actual file
        $parts = explode('/', $path, 2);
        $prefix = $parts[0] ?? '';
        $relativePath = $parts[1] ?? '';
        
        if (empty($prefix) || empty($relativePath)) {
            throw new \InvalidArgumentException("Invalid resource path: {$path}");
        }
        
        // Find the actual file path
        $filePath = $this->resolveFilePath($prefix, $relativePath);
        
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("Resource not found: {$path}");
        }
        
        return file_get_contents($filePath);
    }
    
    /**
     * Resolve the actual file path from prefix and relative path
     */
    private function resolveFilePath(string $prefix, string $relativePath): string
    {
        if ($prefix === 'framework') {
            return BP . '/app/code/Weline/Framework/doc/' . $relativePath;
        }
        
        // Convert prefix back to module name
        $modules = Env::getInstance()->getModuleList();
        foreach ($modules as $name => $module) {
            $moduleKey = strtolower(str_replace('_', '-', $name));
            if ($moduleKey === $prefix) {
                return ($module['base_path'] ?? '') . '/doc/' . $relativePath;
            }
        }
        
        throw new \InvalidArgumentException("Unknown resource prefix: {$prefix}");
    }
}
