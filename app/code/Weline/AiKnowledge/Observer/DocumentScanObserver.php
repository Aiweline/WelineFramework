<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Observer;

use Weline\AiKnowledge\Service\SearchService;
use Weline\AiKnowledge\Service\Collector\SemanticChunker;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Document Scan Observer
 * 
 * Listens to document scan events from the Developer Workspace module
 * and updates the AI knowledge index accordingly.
 */
class DocumentScanObserver implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        try {
            $data = $event->getData('data');
            $documents = $data->getData('documents') ?? [];
            
            if (empty($documents)) {
                return;
            }
            
            $searchService = ObjectManager::getInstance(SearchService::class);
            $chunker = ObjectManager::getInstance(SemanticChunker::class);
            
            $indexed = 0;
            
            foreach ($documents as $doc) {
                if (empty($doc['content'])) {
                    continue;
                }
                
                $document = [
                    'type' => 'documentation',
                    'source' => 'developer_workspace',
                    'name' => $doc['title'] ?? $doc['file_name'] ?? 'Untitled',
                    'module' => $doc['module_name'] ?? null,
                    'content' => $doc['content'],
                    'metadata' => [
                        'file_path' => $doc['file_path'] ?? null,
                        'category_id' => $doc['category_id'] ?? null,
                    ],
                ];
                
                $searchService->indexDocument($document);
                $indexed++;
            }
            
            if ($indexed > 0) {
                $this->log("AI Knowledge index updated: {$indexed} documents from Developer Workspace");
            }
            
        } catch (\Throwable $e) {
            // Don't break the scan process if indexing fails
            $this->log("AI Knowledge index update failed: " . $e->getMessage());
        }
    }
    
    /**
     * Log a message
     */
    private function log(string $message): void
    {
        $logFile = BP . '/var/log/ai_knowledge.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
