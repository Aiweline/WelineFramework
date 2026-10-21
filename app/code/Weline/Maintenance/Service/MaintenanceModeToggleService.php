<?php

declare(strict_types=1);

namespace Weline\Maintenance\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Lock\SoakExclusiveGate;
use Weline\Maintenance\Helper\MaintenanceEdgeFlag;
use Weline\Maintenance\Helper\MaintenanceSessionMarker;
use Weline\Maintenance\Helper\WlsMaintenanceSync;

/**
 * Layered maintenance toggles:
 * - runtime gate: env + edge + static pages + WLS sync (deploy windows)
 * - full: runtime gate plus upgrade-wave / gift (CLI maintenance:enable|disable only)
 */
final class MaintenanceModeToggleService
{
    /**
     * @param array<string, mixed> $args CLI-style args for WLS instance selection (-n/--name)
     * @return list<string> published static locales
     */
    public function enableRuntimeGate(
        Printing $printing,
        array $args = [],
        bool $assertSoak = true,
        string $soakAction = 'maintenance:runtime-enable'
    ): array {
        if ($assertSoak) {
            SoakExclusiveGate::assertClearForMaintenanceFlip($soakAction);
        }

        Env::getInstance()->setConfig('system.maintenance', true);
        // 记录「谁开的维护」，进程被 kill 后 maintenance:status 才能判断残留。
        (new MaintenanceSessionMarker())->start($soakAction);
        if (!MaintenanceEdgeFlag::enable()) {
            $printing->warning(__(
                '未能写入边缘维护标志 %{1}；托管 Nginx 可能仍对店面 html-disk 返回 HIT。',
                [MaintenanceEdgeFlag::RELATIVE_PATH]
            ));
        }
        $retryAfter = (int)(Env::getInstance()->getConfig('maintenance_retry_after', 60));
        $locales = (new MaintenanceStaticGenerator())->publishAll($retryAfter);
        $printing->note(__('维护静态页已生成：%{1}', [\implode(', ', $locales)]));
        WlsMaintenanceSync::syncAfterCliToggle($printing, true, $args);

        return $locales;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function disableRuntimeGate(Printing $printing, array $args = []): void
    {
        Env::getInstance()->setConfig('system.maintenance', false);
        (new MaintenanceSessionMarker())->clear();
        if (!MaintenanceEdgeFlag::disable()) {
            $printing->warning(__(
                '未能删除边缘维护标志 %{1}；托管 Nginx 可能仍跳过店面 html-disk。',
                [MaintenanceEdgeFlag::RELATIVE_PATH]
            ));
        }
        WlsMaintenanceSync::syncAfterCliToggle($printing, false, $args);
    }

    /**
     * CLI maintenance:enable — wave/gift then runtime gate.
     *
     * @param array<string, mixed> $args
     */
    public function enableFull(Printing $printing, array $args = []): void
    {
        SoakExclusiveGate::assertClearForMaintenanceFlip('maintenance:enable');

        $waves = new UpgradeWaveService();
        $gift = $waves->readGiftConfig();
        if (!empty($gift['enabled'])) {
            (new WaitGiftCampaignSyncService())->sync($gift);
        }
        $wave = $waves->beginWave();
        $printing->note(__('升级波次已固化：%{1}（SYS %{2} · Theme %{3}）', [
            (string)($wave['wave_id'] ?? ''),
            (string)($wave['system_version_to'] ?? ''),
            (string)($wave['theme_version_to'] ?? ''),
        ]));

        $this->enableRuntimeGate($printing, $args, false);
        $printing->success(__('维护模式已开启！'));
    }

    /**
     * CLI maintenance:disable — runtime gate then wave redeem/close.
     *
     * @param array<string, mixed> $args
     */
    public function disableFull(Printing $printing, array $args = []): void
    {
        $this->disableRuntimeGate($printing, $args);

        $waves = new UpgradeWaveService();
        $wave = $waves->markRecovered();
        if ($wave !== null) {
            $deadline = (int)($wave['redeem_deadline_at'] ?? 0);
            $printing->note(__('维护礼金兑礼窗口已开启，截止时间戳：%{1}', [(string)$deadline]));
        }
        $closed = $waves->closeExpiredWave();
        if ($closed !== null && (string)($closed['status'] ?? '') === 'closed') {
            $printing->note(__('已过期的兑礼波次状态已关闭（status=closed）。'));
        }
        $printing->success(__('维护模式已关闭！'));
        $printing->note(__('本机店面验收一键关维护：php bin/w maintenance:disable（会同步本机 WLS Worker；禁止用于假关生产）。'));
    }

    public function isMaintenanceEnabled(): bool
    {
        return (bool)Env::getInstance()->getConfig('system.maintenance', false);
    }
}
