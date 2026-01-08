<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Console;

use Weline\AiKnowledge\Mcp\Server;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * MCP Server Command
 * 
 * Starts the MCP (Model Context Protocol) server for AI tool integration.
 * 
 * Usage:
 *   php weline ai:mcp              - Start MCP server (Stdio mode)
 *   php weline ai:mcp --help       - Show help
 *   php weline ai:mcp --version    - Show version
 */
class McpServerCommand implements CommandInterface
{
    private Printing $printing;
    private Server $server;
    
    public function __construct()
    {
        $this->printing = new Printing();
        $this->server = ObjectManager::getInstance(Server::class);
    }
    
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): void
    {
        // Check for flags
        if (in_array('--help', $args) || in_array('-h', $args)) {
            $this->showHelp();
            return;
        }
        
        if (in_array('--version', $args) || in_array('-v', $args)) {
            $this->showVersion();
            return;
        }
        
        // Start the MCP server
        $this->startServer();
    }
    
    /**
     * Start the MCP server
     */
    private function startServer(): void
    {
        // The server runs in Stdio mode, reading from stdin and writing to stdout
        // This is the standard MCP transport for CLI tools like Cursor/Claude
        $this->server->start();
    }
    
    /**
     * Show help information
     */
    private function showHelp(): void
    {
        $help = <<<HELP
Weline AI Knowledge - MCP Server

USAGE:
  php weline ai:mcp [OPTIONS]

DESCRIPTION:
  Starts the Model Context Protocol (MCP) server for AI tool integration.
  The server uses Stdio transport, reading JSON-RPC requests from stdin
  and writing responses to stdout.

OPTIONS:
  -h, --help      Show this help message
  -v, --version   Show version information

MCP INTEGRATION:
  To use with Cursor IDE, add the following to your MCP configuration:

  {
    "mcpServers": {
      "weline": {
        "command": "php",
        "args": ["weline", "ai:mcp"],
        "cwd": "/path/to/weline/project"
      }
    }
  }

AVAILABLE TOOLS:
  - search_docs       Search WelineFramework documentation
  - get_api_structure Get API structure for a module
  - get_schema_info   Get database schema information

AVAILABLE RESOURCES:
  - weline://docs/...            Framework documentation
  - weline://config_templates/...  Code templates and boilerplate

AVAILABLE PROMPTS:
  - debug-weline-error   Guide for debugging Weline errors
  - create-module        Guide for creating a new module
  - database-migration   Guide for database migrations

EXAMPLES:
  # Start MCP server
  php weline ai:mcp

  # Test with a simple request (Unix)
  echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | php weline ai:mcp

HELP;
        
        $this->printing->println($help);
    }
    
    /**
     * Show version information
     */
    private function showVersion(): void
    {
        $version = Server::VERSION;
        $protocol = Server::PROTOCOL_VERSION;
        
        $this->printing->println("Weline AI Knowledge MCP Server");
        $this->printing->println("Version: {$version}");
        $this->printing->println("Protocol: {$protocol}");
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'Start the MCP (Model Context Protocol) server for AI tool integration';
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return [
            'usage' => 'php weline ai:mcp [OPTIONS]',
            'options' => [
                '-h, --help' => 'Show help message',
                '-v, --version' => 'Show version information',
            ],
            'description' => 'Starts the MCP server for AI integration with Cursor, Claude, and other MCP-compatible tools.',
        ];
    }
}
