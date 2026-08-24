# Weline AI Knowledge Module

AI-enhanced documentation and API discovery via the Model Context Protocol (MCP).

## Features

### 1. MCP Server
Full implementation of the Model Context Protocol for integration with AI tools like Cursor, Claude, and other MCP-compatible clients.

**Start the server:**
```bash
php weline ai:mcp
```

### 2. Knowledge Collectors

#### DbDocCollector
Scans database tables and collects:
- Table structures
- Column definitions and comments
- Index information
- Foreign key relationships

#### ReflectApiCollector
Uses PHP Reflection to parse:
- PHP 8 Attributes (#[ApiDoc], #[Acl], etc.)
- PHPDoc comments
- Method signatures and parameters
- Return types

#### SourceGraphCollector
Builds code topology index:
- Class hierarchies (extends, implements)
- Dependency injection relationships
- Service container bindings

### 3. Hybrid Search

Combines multiple search strategies:
- **Dense Vector Search**: Semantic similarity (when embeddings are available)
- **Sparse Vector Search**: TF-IDF keyword matching
- **Reranking**: Lightweight scoring for better relevance

This solves the "proper noun" problem where semantic search alone cannot find specific framework class names like `Weline\Framework\App\Context`.

### 4. Semantic Chunking

Uses sliding window technique to:
- Split long documents into searchable chunks
- Preserve context across chunk boundaries
- Keep API definitions and examples together

## MCP Tools

### search_docs
Search WelineFramework documentation and codebase.

```json
{
  "name": "search_docs",
  "arguments": {
    "query": "how to create a database migration",
    "type": "docs",
    "limit": 10
  }
}
```

### get_api_structure
Get API structure for a module.

```json
{
  "name": "get_api_structure",
  "arguments": {
    "module": "Weline_Ai",
    "include_phpdoc": true
  }
}
```

### get_schema_info
Get database schema information.

```json
{
  "name": "get_schema_info",
  "arguments": {
    "table": "weline_ai_model"
  }
}
```

## MCP Resources

- `weline://docs/framework/...` - Framework documentation
- `weline://docs/{module}/...` - Module documentation
- `weline://config_templates/register.php` - Module templates

## MCP Prompts

- `debug-weline-error` - Guide for debugging errors
- `create-module` - Guide for creating modules
- `database-migration` - Guide for migrations

## Configuration

Edit `etc/env.php`:

```php
return [
    'mcp' => [
        'enabled' => true,
        'log_requests' => true,
    ],
    'vector_store' => [
        'driver' => 'sqlite', // or 'qdrant'
    ],
    'search' => [
        'hybrid_enabled' => true,
        'dense_weight' => 0.7,
        'sparse_weight' => 0.3,
        'rerank_enabled' => true,
    ],
];
```

## Console Commands

```bash
# Start MCP server
php weline ai:mcp

# Build search index
php weline ai:index

# Show index statistics
php weline ai:index --stats

# Clear index
php weline ai:index --clear

# Run specific collector
php weline ai:index --collector=reflect_api
```

## Cursor Integration

Add to your Cursor MCP configuration:

```json
{
  "mcpServers": {
    "weline": {
      "command": "php",
      "args": ["weline", "ai:mcp"],
      "cwd": "/path/to/weline/project"
    }
  }
}
```

## Architecture

```
Weline/AiKnowledge/
├── Mcp/                    # MCP Protocol Layer
│   ├── Server.php          # Stdio transport handler
│   ├── Tool/               # Tool implementations
│   │   ├── SearchDocsTool.php
│   │   ├── GetApiStructureTool.php
│   │   └── GetSchemaInfoTool.php
│   └── Resource/           # Resource handlers
│       ├── DocsResource.php
│       └── ConfigTemplatesResource.php
├── Service/
│   ├── VectorService.php   # Vector storage abstraction
│   ├── SearchService.php   # Hybrid search implementation
│   └── Collector/          # Knowledge collectors
│       ├── DbDocCollector.php
│       ├── ReflectApiCollector.php
│       ├── SourceGraphCollector.php
│       └── SemanticChunker.php
├── Console/
│   ├── McpServerCommand.php
│   └── IndexCommand.php
├── Model/
│   └── CallHistory.php     # Analytics tracking
└── etc/
    ├── env.php             # Module configuration
    ├── mcp.xml             # MCP definitions
    └── event.xml           # Event observers
```

## License

Proprietary - WelineFramework
