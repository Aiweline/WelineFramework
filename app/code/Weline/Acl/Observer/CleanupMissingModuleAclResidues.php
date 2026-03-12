<?php
declare(strict_types=1);

namespace Weline\Acl\Observer;

use Weline\Acl\Service\AclOrphanCleanupService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class CleanupMissingModuleAclResidues implements ObserverInterface
{
    public function __construct(
        private AclOrphanCleanupService $aclOrphanCleanupService
    ) {
    }

    public function execute(Event &$event): void
    {
        $moduleNames = $event->getData('module_names');
        if (!is_array($moduleNames) || empty($moduleNames)) {
            return;
        }
        $moduleNames = array_values(array_filter(array_map('strval', $moduleNames)));
        if (empty($moduleNames)) {
            return;
        }

        try {
            $cleanedCount = $this->aclOrphanCleanupService->cleanupByModules($moduleNames);
            $event->setData('cleaned_count', (int)$cleanedCount);
            $event->setData('result', [
                'success' => true,
                'cleaned_count' => (int)$cleanedCount,
            ]);
        } catch (\Throwable $throwable) {
            $event->setData('result', [
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
