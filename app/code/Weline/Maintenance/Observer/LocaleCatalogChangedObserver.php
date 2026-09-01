<?php

declare(strict_types=1);

namespace Weline\Maintenance\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Maintenance\Service\MaintenanceStaticGenerator;

/**
 * Regenerates pub/errors/maintenance snapshots when the installed locale catalog changes.
 */
final class LocaleCatalogChangedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if ($event->getName() === 'Weline_Framework_Setup::upgrade_after') {
            $eventData = $event->getData();
            if (($eventData['is_partial_upgrade'] ?? false) === true) {
                return;
            }
        }

        try {
            $retryAfter = (int)(Env::getInstance()->getConfig('maintenance_retry_after', 60));
            (new MaintenanceStaticGenerator())->publishAll($retryAfter);
        } catch (\Throwable $exception) {
            w_log_error(
                'Maintenance static page publish failed after locale catalog change: ' . $exception->getMessage(),
                [],
                'maintenance',
            );
        }
    }
}
