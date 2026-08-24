<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Mcp\Tool;

/**
 * Interface for MCP Tools
 * 
 * Tools are executable functions that AI can invoke to perform actions.
 */
interface ToolInterface
{
    /**
     * Get the tool description
     */
    public function getDescription(): string;
    
    /**
     * Get the JSON Schema for the tool's input parameters
     */
    public function getInputSchema(): array;
    
    /**
     * Execute the tool with the given arguments
     * 
     * @param array $arguments The input arguments
     * @return mixed The result of the tool execution
     */
    public function execute(array $arguments): mixed;
}
