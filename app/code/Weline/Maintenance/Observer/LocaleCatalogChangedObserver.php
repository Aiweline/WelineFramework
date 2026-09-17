<?php

declare(strict_types=1);

namespace Weline\Maintenance\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
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

        $printing = null;
        if (PHP_SAPI === 'cli') {
            try {
                /** @var Printing $printing */
                $printing = ObjectManager::getInstance(Printing::class);
            } catch (\Throwable) {
                $printing = null;
            }
        }

        try {
            $printing?->note(__('开始发布维护模式静态页…'));
            $retryAfter = (int)(Env::getInstance()->getConfig('maintenance_retry_after', 60));
            $written = (new MaintenanceStaticGenerator())->publishAll($retryAfter);
            $printing?->success(__('维护模式静态页发布完成：共 %{count} 个快照', [
                'count' => count($written),
            ]));
        } catch (\Throwable $exception) {
            $printing?->warning(__('维护模式静态页发布失败：%{msg}', ['msg' => $exception->getMessage()]));
            w_log_error(
                'Maintenance static page publish failed after locale catalog change: ' . $exception->getMessage(),
                [],
                'maintenance',
            );
        }
    }
}
