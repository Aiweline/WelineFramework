<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Service;

use Weline\AiKnowledge\Service\Collector\SemanticChunker;
use Weline\Framework\Manager\ObjectManager;

/**
 * Search Service
 * 
 * Implements hybrid search combining:
 * - Dense Vector Search: Semantic similarity using embeddings
 * - Sparse Vector Search: Keyword matching using TF-IDF
 * - Reranking: Lightweight model to improve result relevance
 * 
 * This solves the "专有名词" (proper noun) problem where semantic
 * search alone cannot find specific framework class names like
 * "Weline\Framework\App\Context".
 */
class SearchService
{
    private VectorService $vectorService;
    private SemanticChunker $chunker;
    private array $config;
    
    public function __construct()
    {
        $this->vectorService = ObjectManager::getInstance(VectorService::class);
        $this->chunker = ObjectManager::getInstance(SemanticChunker::class);
        $this->loadConfig();
    }
    
    /**
     * Load configuration
     */
    private function loadConfig(): void
    {
        $configFile = __DIR__ . '/../etc/env.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $this->config = $config['search'] ?? [];
        } else {
            $this->config = [
                'hybrid_enabled' => true,
                'dense_weight' => 0.7,
                'sparse_weight' => 0.3,
                'rerank_enabled' => true,
                'rerank_candidates' => 50,
            ];
        }
    }
    
    /**
     * Perform hybrid search
     * 
     * @param string $query Search query
     * @param array $options Search options
     * @return array Search results
     */
    public function search(string $query, array $options = []): array
    {
        $type = $options['type'] ?? 'all';
        $module = $options['module'] ?? null;
        $limit = $options['limit'] ?? 10;
        
        $filters = [];
        if ($type !== 'all') {
            $filters['type'] = $type;
        }
        if ($module) {
            $filters['module'] = $module;
        }
        
        // Get more candidates for reranking
        $candidateLimit = $this->config['rerank_enabled'] 
            ? ($this->config['rerank_candidates'] ?? 50) 
            : $limit;
        
        if ($this->config['hybrid_enabled'] ?? true) {
            // Hybrid search: combine dense and sparse results
            $results = $this->hybridSearch($query, $filters, $candidateLimit);
        } else {
            // Fallback to text search
            $results = $this->textSearch($query, $filters, $candidateLimit);
        }
        
        // Rerank results
        if ($this->config['rerank_enabled'] ?? true) {
            $results = $this->rerank($query, $results);
        }
        
        // Apply final limit
        $results = array_slice($results, 0, $limit);
        
        // Format results
        return array_map(function ($result) {
            return $this->formatResult($result);
        }, $results);
    }
    
    /**
     * Perform hybrid search combining dense and sparse results
     */
    private function hybridSearch(string $query, array $filters, int $limit): array
    {
        // Extract keywords for sparse search
        $keywords = $this->extractKeywords($query);
        
        // Sparse search (keyword matching)
        $sparseResults = [];
        if (!empty($keywords)) {
            $sparseResults = $this->vectorService->searchSparse($keywords, $filters, $limit);
        }
        
        // Text search as dense fallback (until we have real embeddings)
        $textResults = $this->vectorService->searchText($query, $filters, $limit);
        
        // Merge results with weighted scores
        $denseWeight = $this->config['dense_weight'] ?? 0.7;
        $sparseWeight = $this->config['sparse_weight'] ?? 0.3;
        
        $mergedResults = $this->mergeResults($textResults, $sparseResults, $denseWeight, $sparseWeight);
        
        // Sort by combined score
        usort($mergedResults, function ($a, $b) {
            return ($b['combined_score'] ?? 0) <=> ($a['combined_score'] ?? 0);
        });
        
        return array_slice($mergedResults, 0, $limit);
    }
    
    /**
     * Perform simple text search
     */
    private function textSearch(string $query, array $filters, int $limit): array
    {
        return $this->vectorService->searchText($query, $filters, $limit);
    }
    
    /**
     * Extract keywords from query
     * 
     * Identifies important terms including:
     * - Proper nouns (capitalized words)
     * - Class names (CamelCase or with namespace separators)
     * - Technical terms
     */
    private function extractKeywords(string $query): array
    {
        $keywords = [];
        
        // Normalize query
        $query = trim($query);
        
        // Extract class names with namespaces (e.g., Weline\Framework\App)
        if (preg_match_all('/[A-Z][a-zA-Z0-9]*(?:\\\\[A-Z][a-zA-Z0-9]*)+/', $query, $matches)) {
            foreach ($matches[0] as $className) {
                $keywords[$className] = 2.0; // High weight for class names
                
                // Also extract parts
                $parts = explode('\\', $className);
                foreach ($parts as $part) {
                    $keywords[$part] = 1.0;
                }
            }
        }
        
        // Extract CamelCase words
        if (preg_match_all('/[A-Z][a-z]+(?:[A-Z][a-z]+)+/', $query, $matches)) {
            foreach ($matches[0] as $word) {
                $keywords[$word] = 1.5;
            }
        }
        
        // Extract regular words (lowercase, 3+ chars)
        $words = preg_split('/\s+/', strtolower($query));
        $stopwords = ['the', 'and', 'for', 'with', 'how', 'what', 'when', 'where', 'why', 'is', 'are', 'in', 'to', 'of', 'a', 'an'];
        
        foreach ($words as $word) {
            $word = preg_replace('/[^a-z0-9]/', '', $word);
            if (strlen($word) >= 3 && !in_array($word, $stopwords)) {
                if (!isset($keywords[$word])) {
                    $keywords[$word] = 1.0;
                }
            }
        }
        
        return $keywords;
    }
    
    /**
     * Merge results from different search strategies
     */
    private function mergeResults(array $denseResults, array $sparseResults, float $denseWeight, float $sparseWeight): array
    {
        $merged = [];
        $seen = [];
        
        // Normalize and weight dense results
        $maxDenseScore = max(array_column($denseResults, 'score') ?: [1]);
        foreach ($denseResults as $result) {
            $key = $result['chunk_id'];
            $normalizedScore = ($result['score'] ?? 1) / $maxDenseScore;
            
            $merged[$key] = $result;
            $merged[$key]['dense_score'] = $normalizedScore;
            $merged[$key]['combined_score'] = $normalizedScore * $denseWeight;
            $seen[$key] = true;
        }
        
        // Normalize and weight sparse results
        $maxSparseScore = max(array_column($sparseResults, 'score') ?: [1]);
        foreach ($sparseResults as $result) {
            $key = $result['chunk_id'];
            $normalizedScore = ($result['score'] ?? 1) / $maxSparseScore;
            
            if (isset($merged[$key])) {
                // Combine scores
                $merged[$key]['sparse_score'] = $normalizedScore;
                $merged[$key]['combined_score'] += $normalizedScore * $sparseWeight;
            } else {
                $merged[$key] = $result;
                $merged[$key]['sparse_score'] = $normalizedScore;
                $merged[$key]['combined_score'] = $normalizedScore * $sparseWeight;
            }
        }
        
        return array_values($merged);
    }
    
    /**
     * Rerank results for better relevance
     * 
     * Uses a lightweight scoring algorithm based on:
     * - Query term overlap
     * - Position of matches
     * - Document type relevance
     */
    private function rerank(string $query, array $results): array
    {
        $queryTerms = $this->extractKeywords($query);
        $queryLower = strtolower($query);
        
        foreach ($results as &$result) {
            $content = strtolower($result['content'] ?? '');
            $rerankScore = 0;
            
            // Exact query match
            if (str_contains($content, $queryLower)) {
                $rerankScore += 2.0;
            }
            
            // Term overlap score
            foreach ($queryTerms as $term => $weight) {
                $termLower = strtolower($term);
                if (str_contains($content, $termLower)) {
                    $rerankScore += $weight * 0.5;
                    
                    // Bonus for early match
                    $position = strpos($content, $termLower);
                    if ($position !== false && $position < 200) {
                        $rerankScore += 0.3;
                    }
                }
            }
            
            // Type relevance bonus
            $type = $result['type'] ?? '';
            $typeBonus = match ($type) {
                'api_endpoint' => 0.5,
                'db_schema' => 0.3,
                'source_graph' => 0.2,
                default => 0,
            };
            $rerankScore += $typeBonus;
            
            // Combine with original score
            $originalScore = $result['combined_score'] ?? 1;
            $result['rerank_score'] = $rerankScore;
            $result['final_score'] = ($originalScore * 0.4) + ($rerankScore * 0.6);
        }
        
        // Sort by final score
        usort($results, function ($a, $b) {
            return ($b['final_score'] ?? 0) <=> ($a['final_score'] ?? 0);
        });
        
        return $results;
    }
    
    /**
     * Format a search result for output
     */
    private function formatResult(array $result): array
    {
        return [
            'id' => $result['chunk_id'] ?? $result['document_id'] ?? null,
            'type' => $result['type'] ?? 'unknown',
            'name' => $result['name'] ?? '',
            'module' => $result['module'] ?? null,
            'content' => $this->truncateContent($result['content'] ?? '', 500),
            'score' => round($result['final_score'] ?? $result['combined_score'] ?? 0, 4),
            'source' => $result['source'] ?? 'unknown',
        ];
    }
    
    /**
     * Truncate content with ellipsis
     */
    private function truncateContent(string $content, int $maxLength): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }
        
        return substr($content, 0, $maxLength) . '...';
    }
    
    /**
     * Index a document
     * 
     * @param array $document Document to index
     * @return int Document ID
     */
    public function indexDocument(array $document): int
    {
        // Chunk the content
        $chunks = $this->chunker->chunk($document['content'] ?? '');
        
        // Add keyword information to chunks
        foreach ($chunks as &$chunk) {
            $chunk['keywords'] = $this->extractKeywords($chunk['content']);
        }
        
        return $this->vectorService->store($document, $chunks);
    }
    
    /**
     * Build index from collectors
     * 
     * @param array $collectors List of collector class names
     * @return array Index statistics
     */
    public function buildIndex(array $collectors = []): array
    {
        $stats = [
            'documents' => 0,
            'chunks' => 0,
            'errors' => [],
        ];
        
        foreach ($collectors as $collectorClass) {
            try {
                $collector = ObjectManager::getInstance($collectorClass);
                $items = $collector->collect();
                
                foreach ($items as $item) {
                    try {
                        $this->indexDocument($item);
                        $stats['documents']++;
                    } catch (\Exception $e) {
                        $stats['errors'][] = "Error indexing {$item['name']}: {$e->getMessage()}";
                    }
                }
            } catch (\Exception $e) {
                $stats['errors'][] = "Error with collector {$collectorClass}: {$e->getMessage()}";
            }
        }
        
        $stats['chunks'] = $this->vectorService->getChunkCount();
        
        return $stats;
    }
    
    /**
     * Clear the search index
     */
    public function clearIndex(): void
    {
        $this->vectorService->clear();
    }
    
    /**
     * Get index statistics
     */
    public function getStats(): array
    {
        return [
            'documents' => $this->vectorService->getDocumentCount(),
            'chunks' => $this->vectorService->getChunkCount(),
        ];
    }
}
