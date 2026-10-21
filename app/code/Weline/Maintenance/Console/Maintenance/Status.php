<?php

declare(strict_types=1);

namespace Weline\Maintenance\Console\Maintenance;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Lock\SetupDatabaseAccessLock;
use Weline\Maintenance\Helper\MaintenanceEdgeFlag;
use Weline\Maintenance\Helper\MaintenanceSessionMarker;

/**
 * 维护模式体检：状态 + 开启现场 + 升级锁，识别「升级被打断后维护没关」的残留。
 *
 * 典型事故：setup:upgrade / deploy 窗口内进程被 kill，`system.maintenance=true`
 * 留在 env.php，后台与 Webhook 全 503，而店面可能仍从缓存返回 200。
 * 本命令把「开了多久 / 谁开的 / 升级还在不在跑」讲清楚，便于收尾或继续。
 */
class Status implements CommandInterface
{
    public const STALE_AFTER_SECONDS = 1800;

    public function __construct(private readonly Printing $printing)
    {
    }

    public function execute(array $args = [], array $data = [])
    {
        $enabled = (bool)Env::getInstance()->getConfig('system.maintenance', false);
        $edge = MaintenanceEdgeFlag::isOn();
        $marker = new MaintenanceSessionMarker();
        $session = $marker->read();
        $age = $marker->ageSeconds();
        $ownerAlive = $session !== null && MaintenanceSessionMarker::pidAlive($session['pid']);
        $upgradeActive = $this->isUpgradeActive();
        $assessment = self::assess($enabled, $upgradeActive, $ownerAlive, $age);

        $this->printing->note('');
        $this->printing->setup('═══════════════════════════════════════════════════════════════');
        $this->printing->setup(__('维护模式状态'));
        $this->printing->setup('═══════════════════════════════════════════════════════════════');
        $this->printing->note(__('维护模式：%{1}', [$enabled ? __('开启') : __('关闭')]));
        $this->printing->note(__('边缘维护标志（%{1}）：%{2}', [
            MaintenanceEdgeFlag::RELATIVE_PATH,
            $edge ? __('存在') : __('不存在'),
        ]));

        if ($session === null) {
            $this->printing->note(__('开启现场：无标记（旧版本手工改 env.php，或从未开启过）'));
        } else {
            $this->printing->note(__('开启现场：%{1}，动作 %{2}，pid=%{3}（%{4}）', [
                $session['started_at_iso'] !== '' ? $session['started_at_iso'] : date('c', $session['started_at']),
                $session['action'] !== '' ? $session['action'] : '?',
                (string)$session['pid'],
                $ownerAlive ? __('进程仍存活') : __('进程已退出'),
            ]));
            if ($age !== null) {
                $this->printing->note(__('已持续：%{1} 分钟', [(string)(int)floor($age / 60)]));
            }
        }

        $this->printing->note(__('系统升级（setup:upgrade）锁：%{1}', [
            $upgradeActive ? __('进行中') : __('空闲'),
        ]));

        foreach ($assessment['warnings'] as $warning) {
            $this->printing->warning($warning);
        }
        if ($assessment['stale']) {
            $this->printing->note(__('收尾建议：确认没有升级在跑后执行 php bin/w maintenance:disable。'));
        }

        $this->printing->note('');

        return \Weline\Framework\Console\CommandResult::shortCircuit($assessment['stale'] ? 1 : 0);
    }

    /**
     * @return array{stale:bool, warnings:list<string>}
     */
    public static function assess(bool $enabled, bool $upgradeActive, bool $ownerAlive, ?int $age): array
    {
        $warnings = [];
        $stale = false;

        if (!$enabled) {
            if ($upgradeActive) {
                $warnings[] = (string)__('升级锁被占用但维护模式是关闭的：升级可能还没进入维护窗，或锁残留。');
            }

            return ['stale' => false, 'warnings' => $warnings];
        }

        if ($upgradeActive) {
            return ['stale' => false, 'warnings' => $warnings];
        }

        if ($age === null) {
            $warnings[] = (string)__('维护开启但没有开启现场标记：无法判断是谁开的，收尾前先确认没有其它升级在跑。');
            $stale = true;
        } elseif (!$ownerAlive) {
            $warnings[] = (string)__('维护开启，但开启它的进程已退出且升级锁空闲：疑似残留维护。');
            $stale = true;
        } elseif ($age > self::STALE_AFTER_SECONDS) {
            $warnings[] = (string)__('维护已持续超过 %{1} 分钟且升级锁空闲：疑似残留维护。', [
                (string)(int)floor(self::STALE_AFTER_SECONDS / 60),
            ]);
            $stale = true;
        }

        return ['stale' => $stale, 'warnings' => $warnings];
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

    public function tip(): string
    {
        return __('查看维护模式状态、开启现场与残留风险');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'maintenance:status',
            $this->tip(),
            [],
            [
                '退出码' => '0=正常或维护中；1=疑似残留维护（升级锁空闲且开启进程已退出/超时）',
            ],
            ['查看状态' => 'php bin/w maintenance:status'],
            'php bin/w maintenance:status'
        );
    }
}
