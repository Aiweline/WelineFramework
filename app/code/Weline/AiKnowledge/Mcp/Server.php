<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Mcp;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;
use Weline\AiKnowledge\Mcp\Tool\ToolInterface;
use Weline\AiKnowledge\Mcp\Tool\SearchDocsTool;
use Weline\AiKnowledge\Mcp\Tool\GetApiStructureTool;
use Weline\AiKnowledge\Mcp\Tool\GetSchemaInfoTool;
use Weline\AiKnowledge\Mcp\Resource\ResourceInterface;
use Weline\AiKnowledge\Mcp\Resource\DocsResource;
use Weline\AiKnowledge\Mcp\Resource\ConfigTemplatesResource;

/**
 * MCP Server Implementation
 * 
 * Handles JSON-RPC 2.0 protocol via Stdio for AI tool integration.
 * Implements the Model Context Protocol (MCP) for external AI access.
 * 
 * @see https://modelcontextprotocol.io/
 */
class Server
{
    public const VERSION = '1.0.0';
    public const PROTOCOL_VERSION = '2024-11-05';
    
    /**
     * @var array<string, ToolInterface> Registered tools
     */
    private array $tools = [];
    
    /**
     * @var array<string, ResourceInterface> Registered resources
     */
    private array $resources = [];
    
    /**
     * @var array<string, array> Predefined prompts
     */
    private array $prompts = [];
    
    /**
     * @var bool Server running state
     */
    private bool $running = false;
    
    /**
     * @var resource|null Input stream
     */
    private $inputStream = null;
    
    /**
     * @var resource|null Output stream
     */
    private $outputStream = null;
    
    /**
     * @var array Module configuration
     */
    private array $config = [];
    
    /**
     * @var array Call history for analytics
     */
    private array $callHistory = [];
    
    public function __construct()
    {
        $this->loadConfig();
        $this->registerDefaultTools();
        $this->registerDefaultResources();
        $this->registerDefaultPrompts();
    }
    
    /**
     * Load module configuration
     */
    private function loadConfig(): void
    {
        $configFile = __DIR__ . '/../etc/env.php';
        if (file_exists($configFile)) {
            $this->config = require $configFile;
        }
    }
    
    /**
     * Get configuration value
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * Register default tools
     */
    private function registerDefaultTools(): void
    {
        $this->registerTool('search_docs', ObjectManager::getInstance(SearchDocsTool::class));
        $this->registerTool('get_api_structure', ObjectManager::getInstance(GetApiStructureTool::class));
        $this->registerTool('get_schema_info', ObjectManager::getInstance(GetSchemaInfoTool::class));
    }
    
    /**
     * Register default resources
     */
    private function registerDefaultResources(): void
    {
        $this->registerResource('docs', ObjectManager::getInstance(DocsResource::class));
        $this->registerResource('config_templates', ObjectManager::getInstance(ConfigTemplatesResource::class));
    }
    
    /**
     * Register default prompts
     */
    private function registerDefaultPrompts(): void
    {
        $this->prompts = [
            'debug-weline-error' => [
                'name' => 'debug-weline-error',
                'description' => 'Guide for debugging Weline Framework errors using log files',
                'arguments' => [
                    [
                        'name' => 'error_message',
                        'description' => 'The error message to debug',
                        'required' => true,
                    ],
                ],
                'template' => <<<'PROMPT'
You are debugging a Weline Framework error. Follow these steps:

1. **Analyze the Error**: Parse the error message: {{error_message}}

2. **Check Log Files**: Look in var/log/ for:
   - dev.log: Development errors
   - exception.log: Exception traces
   - system.log: System-level issues

3. **Common Patterns**:
   - "Class not found": Check namespace and autoload
   - "Table not found": Run `php weline setup:upgrade`
   - "Permission denied": Check file permissions in var/ and pub/

4. **Use Search**: Call search_docs with relevant keywords

5. **Check Configuration**: Verify app/etc/env.php settings
PROMPT,
            ],
            'create-module' => [
                'name' => 'create-module',
                'description' => 'Guide for creating a new Weline module',
                'arguments' => [
                    [
                        'name' => 'module_name',
                        'description' => 'The name of the module (e.g., Vendor_ModuleName)',
                        'required' => true,
                    ],
                ],
                'template' => <<<'PROMPT'
You are creating a new Weline Framework module: {{module_name}}

Required files:
1. register.php - Module registration
2. composer.json - Package definition
3. etc/env.php - Module configuration

Use get_api_structure to see examples from existing modules.
PROMPT,
            ],
            'database-migration' => [
                'name' => 'database-migration',
                'description' => 'Guide for creating database migrations in Weline',
                'arguments' => [
                    [
                        'name' => 'table_name',
                        'description' => 'The name of the table to migrate',
                        'required' => true,
                    ],
                ],
                'template' => <<<'PROMPT'
You are creating a database migration for table: {{table_name}}

Steps:
1. Create Setup/Install.php for initial installation
2. Create Setup/Upgrade.php for version upgrades
3. Use Model class with setup traits
4. Run `php weline setup:upgrade` to apply

Use search_docs with "database migration" for detailed examples.
PROMPT,
            ],
        ];
    }
    
    /**
     * Register a tool
     */
    public function registerTool(string $name, ToolInterface $tool): void
    {
        $this->tools[$name] = $tool;
    }
    
    /**
     * Register a resource
     */
    public function registerResource(string $name, ResourceInterface $resource): void
    {
        $this->resources[$name] = $resource;
    }
    
    /**
     * Start the MCP server (Stdio mode)
     */
    public function start(): void
    {
        $this->inputStream = STDIN;
        $this->outputStream = STDOUT;
        $this->running = true;
        
        $this->log('MCP server started');
        
        // Main loop - read from stdin, process, write to stdout
        while ($this->running && ($line = fgets($this->inputStream)) !== false) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $request = json_decode($line, true);
            if ($request === null) {
                $this->sendError(null, -32700, 'Parse error');
                continue;
            }
            
            $response = $this->handle($request);
            if ($response !== null) {
                $this->send($response);
            }
        }
        
        $this->log('MCP server stopped');
    }
    
    /**
     * Stop the server
     */
    public function stop(): void
    {
        $this->running = false;
    }
    
    /**
     * Handle a single request
     */
    public function handle(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        
        // Record call for analytics
        $this->recordCall($method, $params);
        
        try {
            $result = match ($method) {
                // Lifecycle methods
                'initialize' => $this->handleInitialize($params),
                'initialized' => $this->handleInitialized(),
                'shutdown' => $this->handleShutdown(),
                
                // Tool methods
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($params),
                
                // Resource methods
                'resources/list' => $this->handleResourcesList(),
                'resources/read' => $this->handleResourcesRead($params),
                
                // Prompt methods
                'prompts/list' => $this->handlePromptsList(),
                'prompts/get' => $this->handlePromptsGet($params),
                
                // Ping
                'ping' => ['pong' => true],
                
                default => throw new \InvalidArgumentException("Unknown method: {$method}"),
            };
            
            // Notifications (no id) don't get responses
            if ($id === null) {
                return null;
            }
            
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
        } catch (\Throwable $e) {
            $this->log("Error handling {$method}: " . $e->getMessage());
            
            if ($id === null) {
                return null;
            }
            
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32603,
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }
    
    /**
     * Handle initialize request
     */
    private function handleInitialize(array $params): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
                'resources' => [
                    'subscribe' => false,
                    'listChanged' => false,
                ],
                'prompts' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'weline-ai-knowledge',
                'version' => self::VERSION,
            ],
        ];
    }
    
    /**
     * Handle initialized notification
     */
    private function handleInitialized(): array
    {
        return [];
    }
    
    /**
     * Handle shutdown request
     */
    private function handleShutdown(): array
    {
        $this->stop();
        return [];
    }
    
    /**
     * Handle tools/list request
     */
    private function handleToolsList(): array
    {
        $tools = [];
        
        foreach ($this->tools as $name => $tool) {
            $tools[] = [
                'name' => $name,
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }
        
        return ['tools' => $tools];
    }
    
    /**
     * Handle tools/call request
     */
    private function handleToolsCall(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Tool not found: {$name}");
        }
        
        $tool = $this->tools[$name];
        $result = $tool->execute($arguments);
        
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ],
            ],
        ];
    }
    
    /**
     * Handle resources/list request
     */
    private function handleResourcesList(): array
    {
        $resources = [];
        
        foreach ($this->resources as $name => $resource) {
            foreach ($resource->list() as $item) {
                $resources[] = $item;
            }
        }
        
        return ['resources' => $resources];
    }
    
    /**
     * Handle resources/read request
     */
    private function handleResourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? '';
        
        // Parse URI to find resource handler
        // Format: weline://resource_name/path
        if (!str_starts_with($uri, 'weline://')) {
            throw new \InvalidArgumentException("Invalid resource URI: {$uri}");
        }
        
        $path = substr($uri, strlen('weline://'));
        $parts = explode('/', $path, 2);
        $resourceName = $parts[0] ?? '';
        $resourcePath = $parts[1] ?? '';
        
        if (!isset($this->resources[$resourceName])) {
            throw new \InvalidArgumentException("Resource not found: {$resourceName}");
        }
        
        $content = $this->resources[$resourceName]->read($resourcePath);
        
        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'text/plain',
                    'text' => $content,
                ],
            ],
        ];
    }
    
    /**
     * Handle prompts/list request
     */
    private function handlePromptsList(): array
    {
        $prompts = [];
        
        foreach ($this->prompts as $prompt) {
            $prompts[] = [
                'name' => $prompt['name'],
                'description' => $prompt['description'],
                'arguments' => $prompt['arguments'] ?? [],
            ];
        }
        
        return ['prompts' => $prompts];
    }
    
    /**
     * Handle prompts/get request
     */
    private function handlePromptsGet(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];
        
        if (!isset($this->prompts[$name])) {
            throw new \InvalidArgumentException("Prompt not found: {$name}");
        }
        
        $prompt = $this->prompts[$name];
        $template = $prompt['template'];
        
        // Replace placeholders with arguments
        foreach ($arguments as $key => $value) {
            $template = str_replace("{{" . $key . "}}", $value, $template);
        }
        
        return [
            'description' => $prompt['description'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $template,
                    ],
                ],
            ],
        ];
    }
    
    /**
     * Send a response
     */
    private function send(array $response): void
    {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE);
        fwrite($this->outputStream, $json . "\n");
        fflush($this->outputStream);
    }
    
    /**
     * Send an error response
     */
    private function sendError(?int $id, int $code, string $message): void
    {
        $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
        
        $this->send($response);
    }
    
    /**
     * Record a call for analytics
     */
    private function recordCall(string $method, array $params): void
    {
        if (!$this->getConfig('mcp.log_requests', true)) {
            return;
        }
        
        $this->callHistory[] = [
            'method' => $method,
            'params' => $params,
            'timestamp' => time(),
        ];
        
        // Keep only last 1000 calls
        if (count($this->callHistory) > 1000) {
            $this->callHistory = array_slice($this->callHistory, -1000);
        }
    }
    
    /**
     * Get call history
     */
    public function getCallHistory(): array
    {
        return $this->callHistory;
    }
    
    /**
     * Log a message
     */
    private function log(string $message): void
    {
        $logFile = BP . '/var/log/mcp.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
