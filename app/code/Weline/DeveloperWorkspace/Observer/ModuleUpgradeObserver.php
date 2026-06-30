<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Observer;

use Weline\DeveloperWorkspace\Model\Document;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\DeveloperWorkspace\Service\DocumentScanner;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class ModuleUpgradeObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!$this->storageReady()) {
            return;
        }

        try {
            /** @var DocumentScanner $scanner */
            $scanner = ObjectManager::getInstance(DocumentScanner::class);
            $scanner->scanAllModules(false);
        } catch (\Throwable) {
            // Module upgrade must not fail because the optional document index is unavailable.
        }
    }

    private function storageReady(): bool
    {
        try {
            /** @var Document $document */
            $document = ObjectManager::make(Document::class);
            /** @var Catalog $catalog */
            $catalog = ObjectManager::make(Catalog::class);
            $connector = $document->getConnection()->getConnector();

            return $connector->tableExist($document->getTable())
                && $connector->tableExist($catalog->getTable());
        } catch (\Throwable) {
            return false;
        }
    }
}
