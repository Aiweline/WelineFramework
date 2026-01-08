<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Mcp\Resource;

/**
 * Interface for MCP Resources
 * 
 * Resources are static data that AI can read to get context.
 */
interface ResourceInterface
{
    /**
     * List all available resources
     * 
     * @return array List of resource descriptors
     */
    public function list(): array;
    
    /**
     * Read a specific resource
     * 
     * @param string $path The resource path
     * @return string The resource content
     */
    public function read(string $path): string;
}
