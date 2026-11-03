<?php

declare(strict_types=1);

namespace Weline\Maintenance\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Lock\SetupDatabaseAccessLock;
use Weline\Maintenance\Console\Maintenance\Status;
use Weline\Maintenance\Helper\MaintenanceSessionMarker;
use Weline\Maintenance\Service\MaintenanceModeToggleService;

/**
 * 服务启动时自动收尾「升级被打断后的残留维护」。
 *
 * 判据复用 maintenance:status 的 assess()：维护开着、升级锁空闲、开启进程已死
 * 或超过 STALE_AFTER_SECONDS。只在明确 stale 时 disableRuntimeGate，避免误关
 * 正在进行的部署窗。
 */
class ClearStaleMaintenanceOnServerStart implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            if (!(bool)Env::get('maintenance.auto_clear_stale_on_start', true)) {
                return;
            }
        } catch (\Throwable) {
            // env 读不到时仍尝试评估
        }

        try {
            $enabled = (bool)Env::getInstance()->getConfig('system.maintenance', false);
            if (!$enabled) {
                return;
            }

            $marker = new MaintenanceSessionMarker();
            $session = $marker->read();
            $age = $marker->ageSeconds();
            $ownerAlive = $session !== null && MaintenanceSessionMarker::pidAlive($session['pid']);
            $upgradeActive = $this->isUpgradeActive();
            $assessment = Status::assess($enabled, $upgradeActive, $ownerAlive, $age);
            if (!$assessment['stale']) {
                return;
            }

            $printing = new Printing();
            foreach ($assessment['warnings'] as $warning) {
                $printing->warning('[StaleMaintenance] ' . $warning);
            }

            /** @var MaintenanceModeToggleService $toggle */
            $toggle = ObjectManager::getInstance(MaintenanceModeToggleService::class);
            $toggle->disableRuntimeGate($printing);
            $this->log('auto-cleared stale maintenance on server start');
        } catch (\Throwable $e) {
            $this->log('auto-clear failed: ' . $e->getMessage());
        }
    }

    private function isUpgradeActive(): bool
    {
        $lease = new SetupDatabaseAccessLock();
        if ($lease->acquireShared()) {
            $lease->release();

            return false;
        }

        return true;
    }

    private function log(string $message): void
    {
        if (\function_exists('w_log_info')) {
            \w_log_info('[StaleMaintenance] ' . $message);
        }
    }
}
