<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Lock\SoakExclusiveGate;
use Weline\Maintenance\Helper\MaintenanceEdgeFlag;
use Weline\Maintenance\Service\MaintenanceModeToggleService;

/**
 * Owns the deploy-time maintenance window (runtime gate only — no upgrade wave/gift).
 */
final class DeployMaintenanceWindowService
{
    private bool $active = false;

    private bool $priorMaintenance = false;

    private bool $priorEdge = false;

    private readonly MaintenanceModeToggleService $toggle;

    public function __construct(?MaintenanceModeToggleService $toggle = null)
    {
        $this->toggle = $toggle ?? new MaintenanceModeToggleService();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Snapshot prior state, assert soak clear, enable runtime gate.
     *
     * @throws \Weline\Framework\App\Exception when soak exclusive lock is active
     */
    public function begin(Printing $printing): void
    {
        if ($this->active) {
            return;
        }

        SoakExclusiveGate::assertClearForMaintenanceFlip('deploy:maintenance-window');

        $this->priorMaintenance = (bool)Env::getInstance()->getConfig('system.maintenance', false);
        $this->priorEdge = MaintenanceEdgeFlag::isOn();

        $this->toggle->enableRuntimeGate(
            $printing,
            [],
            false,
            'deploy:maintenance-window'
        );
        $this->active = true;
        $printing->note(__('部署维护窗已开启（runtime gate）。'));
    }

    /**
     * Restore prior maintenance state. Safe to call when begin() never succeeded.
     */
    public function end(Printing $printing): void
    {
        if (!$this->active) {
            return;
        }

        try {
            if ($this->priorMaintenance) {
                // Leave maintenance on; ensure edge matches prior if it was on.
                if ($this->priorEdge && !MaintenanceEdgeFlag::isOn()) {
                    MaintenanceEdgeFlag::enable();
                }
                $printing->note(__('部署维护窗结束：部署前已是维护态，保持开启。'));
            } else {
                $this->toggle->disableRuntimeGate($printing, []);
                $printing->note(__('部署维护窗已关闭。'));
            }
        } finally {
            $this->active = false;
            $this->priorMaintenance = false;
            $this->priorEdge = false;
        }
    }
}
