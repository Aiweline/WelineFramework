<?php

declare(strict_types=1);

/**
 * AiKnowledge Module Environment Configuration
 */
return [
    // MCP Server Configuration
    'mcp' => [
        'enabled' => true,
        'log_requests' => true,
        'max_results' => 20,
    ],
    
    // Vector Store Configuration
    'vector_store' => [
        // 'sqlite' or 'qdrant'
        'driver' => 'sqlite',
        
        // SQLite configuration
        'sqlite' => [
            'database' => 'var/ai_knowledge/vectors.db',
        ],
        
        // Qdrant configuration (optional external vector database)
        'qdrant' => [
            'host' => 'localhost',
            'port' => 6333,
            'collection' => 'weline_docs',
        ],
    ],
    
    // Search Configuration
    'search' => [
        // Enable hybrid search (dense + sparse vectors)
        'hybrid_enabled' => true,
        
        // Weight for dense vector similarity (0-1)
        'dense_weight' => 0.7,
        
        // Weight for sparse vector (keyword) matching (0-1)
        'sparse_weight' => 0.3,
        
        // Enable reranking for better results
        'rerank_enabled' => true,
        
        // Maximum number of results before reranking
        'rerank_candidates' => 50,
    ],
    
    // Collector Configuration
    'collectors' => [
        'db_doc' => [
            'enabled' => true,
            'include_comments' => true,
        ],
        'reflect_api' => [
            'enabled' => true,
            'parse_phpdoc' => true,
            'parse_attributes' => true,
        ],
        'source_graph' => [
            'enabled' => true,
            'scan_paths' => ['app/code'],
        ],
    ],
    
    // Semantic Chunking Configuration
    'chunking' => [
        // Chunk size in characters
        'chunk_size' => 1000,
        
        // Overlap between chunks (sliding window)
        'overlap_size' => 200,
        
        // Minimum chunk size (avoid very small chunks)
        'min_chunk_size' => 100,
    ],
];
