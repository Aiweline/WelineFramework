<?php

declare(strict_types=1);

namespace Weline\AiKnowledge\Observer;

use Weline\AiKnowledge\Service\SearchService;
use Weline\AiKnowledge\Service\Collector\ReflectApiCollector;
use Weline\AiKnowledge\Service\Collector\SourceGraphCollector;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Module Upgrade Observer
 * 
 * Automatically updates the AI knowledge index when a module is upgraded.
 * This ensures the knowledge base stays current with code changes.
 */
class ModuleUpgradeObserver implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        try {
            $data = $event->getData('data');
            if ($data === null) {
                return;
            }
            $moduleName = $data->getData('module_name') ?? null;
            
            if (empty($moduleName)) {
                return;
            }
            
            // Get the search service
            $searchService = ObjectManager::getInstance(SearchService::class);
            
            // Rebuild index for API and source graph collectors
            // These are the ones most likely to change with module upgrades
            $collectors = [
                ReflectApiCollector::class,
                SourceGraphCollector::class,
            ];
            
            $searchService->buildIndex($collectors);
            
            // Log the update
            $this->log("AI Knowledge index updated after module upgrade: {$moduleName}");
            
        } catch (\Throwable $e) {
            // Don't break the upgrade process if indexing fails
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
