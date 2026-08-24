<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * Vector Service
 * 
 * Provides abstraction for vector storage and retrieval.
 * Supports multiple backends:
 * - SQLite with VSS extension (embedded, no external dependencies)
 * - Qdrant (external vector database for production)
 */
class VectorService
{
    /**
     * @var array Configuration
     */
    private array $config;
    
    /**
     * @var \PDO|null SQLite connection
     */
    private ?\PDO $sqliteDb = null;
    
    /**
     * Vector dimension (for dense vectors)
     */
    private int $vectorDimension = 384;
    
    public function __construct()
    {
        $this->loadConfig();
        $this->initialize();
    }
    
    /**
     * Load configuration
     */
    private function loadConfig(): void
    {
        $configFile = __DIR__ . '/../etc/env.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $this->config = $config['vector_store'] ?? [];
        } else {
            $this->config = [
                'driver' => 'sqlite',
                'sqlite' => [
                    'database' => 'var/ai_knowledge/vectors.db',
                ],
            ];
        }
    }
    
    /**
     * Initialize vector storage
     */
    private function initialize(): void
    {
        $driver = $this->config['driver'] ?? 'sqlite';
        
        if ($driver === 'sqlite') {
            $this->initializeSqlite();
        }
    }
    
    /**
     * Initialize SQLite storage
     */
    private function initializeSqlite(): void
    {
        $dbPath = BP . '/' . ($this->config['sqlite']['database'] ?? 'var/ai_knowledge/vectors.db');
        $dbDir = dirname($dbPath);
        
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        
        $this->sqliteDb = new \PDO("sqlite:{$dbPath}");
        $this->sqliteDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        // Create tables if not exist
        $this->createTables();
    }
    
    /**
     * Create required tables
     */
    private function createTables(): void
    {
        // Documents table
        $this->sqliteDb->exec("
            CREATE TABLE IF NOT EXISTS documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                source TEXT NOT NULL,
                name TEXT NOT NULL,
                module TEXT,
                content TEXT NOT NULL,
                metadata TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Chunks table (for semantic chunks)
        $this->sqliteDb->exec("
            CREATE TABLE IF NOT EXISTS chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                content TEXT NOT NULL,
                chunk_index INTEGER NOT NULL,
                start_pos INTEGER,
                end_pos INTEGER,
                dense_vector BLOB,
                sparse_terms TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
            )
        ");
        
        // Keywords index table (for sparse vector search)
        $this->sqliteDb->exec("
            CREATE TABLE IF NOT EXISTS keywords (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chunk_id INTEGER NOT NULL,
                keyword TEXT NOT NULL,
                tf_idf REAL NOT NULL,
                FOREIGN KEY (chunk_id) REFERENCES chunks(id) ON DELETE CASCADE
            )
        ");
        
        // Create indexes
        $this->sqliteDb->exec("CREATE INDEX IF NOT EXISTS idx_documents_type ON documents(type)");
        $this->sqliteDb->exec("CREATE INDEX IF NOT EXISTS idx_documents_module ON documents(module)");
        $this->sqliteDb->exec("CREATE INDEX IF NOT EXISTS idx_documents_name ON documents(name)");
        $this->sqliteDb->exec("CREATE INDEX IF NOT EXISTS idx_chunks_document_id ON chunks(document_id)");
        $this->sqliteDb->exec("CREATE INDEX IF NOT EXISTS idx_keywords_keyword ON keywords(keyword)");
        $this->sqliteDb->exec("CREATE INDEX IF NOT EXISTS idx_keywords_chunk_id ON keywords(chunk_id)");
    }
    
    /**
     * Store a document with its chunks
     * 
     * @param array $document Document data
     * @param array $chunks Chunk data with vectors
     * @return int Document ID
     */
    public function store(array $document, array $chunks): int
    {
        // Check if document already exists
        $stmt = $this->sqliteDb->prepare("SELECT id FROM documents WHERE name = ? AND type = ?");
        $stmt->execute([$document['name'], $document['type']]);
        $existingId = $stmt->fetchColumn();
        
        if ($existingId) {
            // Delete existing document and chunks
            $this->delete((int)$existingId);
        }
        
        // Insert document
        $stmt = $this->sqliteDb->prepare("
            INSERT INTO documents (type, source, name, module, content, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $document['type'],
            $document['source'],
            $document['name'],
            $document['module'] ?? null,
            $document['content'],
            json_encode($document['metadata'] ?? []),
        ]);
        
        $documentId = (int)$this->sqliteDb->lastInsertId();
        
        // Insert chunks
        foreach ($chunks as $chunk) {
            $this->storeChunk($documentId, $chunk);
        }
        
        return $documentId;
    }
    
    /**
     * Store a chunk with its vectors
     */
    private function storeChunk(int $documentId, array $chunk): int
    {
        $stmt = $this->sqliteDb->prepare("
            INSERT INTO chunks (document_id, content, chunk_index, start_pos, end_pos, dense_vector, sparse_terms)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $denseVector = isset($chunk['dense_vector']) 
            ? pack('f*', ...$chunk['dense_vector']) 
            : null;
        
        $stmt->execute([
            $documentId,
            $chunk['content'],
            $chunk['index'] ?? 0,
            $chunk['start'] ?? null,
            $chunk['end'] ?? null,
            $denseVector,
            json_encode($chunk['sparse_terms'] ?? []),
        ]);
        
        $chunkId = (int)$this->sqliteDb->lastInsertId();
        
        // Store keywords for sparse search
        if (!empty($chunk['keywords'])) {
            foreach ($chunk['keywords'] as $keyword => $tfIdf) {
                $stmt = $this->sqliteDb->prepare("
                    INSERT INTO keywords (chunk_id, keyword, tf_idf)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$chunkId, $keyword, $tfIdf]);
            }
        }
        
        return $chunkId;
    }
    
    /**
     * Delete a document and its chunks
     */
    public function delete(int $documentId): void
    {
        // Get chunk IDs first
        $stmt = $this->sqliteDb->prepare("SELECT id FROM chunks WHERE document_id = ?");
        $stmt->execute([$documentId]);
        $chunkIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // Delete keywords
        if (!empty($chunkIds)) {
            $placeholders = implode(',', array_fill(0, count($chunkIds), '?'));
            $this->sqliteDb->prepare("DELETE FROM keywords WHERE chunk_id IN ({$placeholders})")
                ->execute($chunkIds);
        }
        
        // Delete chunks
        $this->sqliteDb->prepare("DELETE FROM chunks WHERE document_id = ?")
            ->execute([$documentId]);
        
        // Delete document
        $this->sqliteDb->prepare("DELETE FROM documents WHERE id = ?")
            ->execute([$documentId]);
    }
    
    /**
     * Search using keywords (sparse vector search)
     * 
     * @param array $keywords Search keywords with weights
     * @param array $filters Additional filters
     * @param int $limit Maximum results
     * @return array Search results
     */
    public function searchSparse(array $keywords, array $filters = [], int $limit = 20): array
    {
        if (empty($keywords)) {
            return [];
        }
        
        // Build query to find chunks matching keywords
        $placeholders = implode(',', array_fill(0, count($keywords), '?'));
        $keywordList = array_keys($keywords);
        
        $sql = "
            SELECT 
                c.id as chunk_id,
                c.document_id,
                c.content,
                c.chunk_index,
                d.type,
                d.name,
                d.module,
                d.source,
                SUM(k.tf_idf) as score
            FROM chunks c
            JOIN keywords k ON c.id = k.chunk_id
            JOIN documents d ON c.document_id = d.id
            WHERE k.keyword IN ({$placeholders})
        ";
        
        $params = $keywordList;
        
        // Apply filters
        if (!empty($filters['type'])) {
            $sql .= " AND d.type = ?";
            $params[] = $filters['type'];
        }
        
        if (!empty($filters['module'])) {
            $sql .= " AND d.module = ?";
            $params[] = $filters['module'];
        }
        
        $sql .= " GROUP BY c.id ORDER BY score DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->sqliteDb->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Search using text (full-text search fallback)
     * 
     * @param string $query Search query
     * @param array $filters Additional filters
     * @param int $limit Maximum results
     * @return array Search results
     */
    public function searchText(string $query, array $filters = [], int $limit = 20): array
    {
        $sql = "
            SELECT 
                c.id as chunk_id,
                c.document_id,
                c.content,
                c.chunk_index,
                d.type,
                d.name,
                d.module,
                d.source
            FROM chunks c
            JOIN documents d ON c.document_id = d.id
            WHERE c.content LIKE ?
        ";
        
        $params = ["%{$query}%"];
        
        // Apply filters
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $sql .= " AND d.type = ?";
            $params[] = $this->mapTypeFilter($filters['type']);
        }
        
        if (!empty($filters['module'])) {
            $sql .= " AND d.module = ?";
            $params[] = $filters['module'];
        }
        
        $sql .= " LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->sqliteDb->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Map user-friendly type filter to internal type
     */
    private function mapTypeFilter(string $type): string
    {
        return match ($type) {
            'docs' => 'documentation',
            'api' => 'api_endpoint',
            'code' => 'source_graph',
            'config' => 'configuration',
            default => $type,
        };
    }
    
    /**
     * Get document by ID
     */
    public function getDocument(int $id): ?array
    {
        $stmt = $this->sqliteDb->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$doc) {
            return null;
        }
        
        $doc['metadata'] = json_decode($doc['metadata'] ?? '{}', true);
        return $doc;
    }
    
    /**
     * Get chunks for a document
     */
    public function getChunks(int $documentId): array
    {
        $stmt = $this->sqliteDb->prepare("SELECT * FROM chunks WHERE document_id = ? ORDER BY chunk_index");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get total document count
     */
    public function getDocumentCount(): int
    {
        $stmt = $this->sqliteDb->query("SELECT COUNT(*) FROM documents");
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Get total chunk count
     */
    public function getChunkCount(): int
    {
        $stmt = $this->sqliteDb->query("SELECT COUNT(*) FROM chunks");
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Clear all data
     */
    public function clear(): void
    {
        $this->sqliteDb->exec("DELETE FROM keywords");
        $this->sqliteDb->exec("DELETE FROM chunks");
        $this->sqliteDb->exec("DELETE FROM documents");
    }
    
    /**
     * Get database connection (for direct queries)
     */
    public function getConnection(): \PDO
    {
        return $this->sqliteDb;
    }
}
