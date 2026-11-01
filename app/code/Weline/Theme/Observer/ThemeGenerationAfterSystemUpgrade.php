<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Deploy\SystemReleaseManager;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;

/**
 * 系统更新完成 → 推进世代版本号（Task 0.1，定论 15）。
 *
 * 挂在 `Weline_Framework_Deploy::upgrade_after`（`Upgrade.php:83` 派发）。
 * 依据用户 2026-11-01 口径：**不做"代码是否变更"判定**（代码量太大不比对），
 * 系统更新完成一律推进——只改 `deploy_build`，主题代次原样保留。
 *
 * 失败时：
 * - active 保持旧值（站点继续服务旧版，不掉样式、不 404）；
 * - 写 `var/log/warning.log`，并置 `$event` 的 `generation_promote_failed=true`，
 *   由 `s:up` 决定退出码（D4）。
 *
 * Marker: theme-generation-after-system-upgrade-v1
 */
class ThemeGenerationAfterSystemUpgrade implements ObserverInterface
{
    public function __construct(
        private readonly Printing $printing
    ) {
    }

    public function execute(Event &$event): void
    {
        $skip = (bool)($event->getData('skip_invalidation') ?? false);
        if ($skip) {
            // D14：不再隐式跳过固化，改为显式提示，便于排查"为什么版本没动"。
            $this->printing->warning(__('系统更新带了 skip_invalidation：世代版本号已按要求跳过推进'));
            return;
        }

        try {
            $stage = SystemReleaseManager::stage();
            if (($stage['staged'] ?? false) !== true) {
                return;
            }
            $result = SystemReleaseManager::promoteAllChains();
            if (($result['ok'] ?? false) !== true) {
                $this->markFailed($event, (string)\json_encode($result['failed'] ?? []));
                $this->printing->warning(__(
                    '系统更新完成，但世代版本号推进失败（站点继续服务旧版）：%{1}',
                    [\implode(',', (array)($result['failed'] ?? []))]
                ));

                return;
            }
            $this->printing->success(__(
                '世代版本号已推进：deploy_build=%{1}，链=%{2}',
                [(string)($stage['deploy_build'] ?? ''), (string)\count((array)($result['promoted'] ?? []))]
            ));
        } catch (\Throwable $e) {
            $this->markFailed($event, $e->getMessage());
            $this->printing->warning(__('世代版本号推进异常（站点继续服务旧版）：%{1}', [$e->getMessage()]));
        }
    }

    private function markFailed(Event $event, string $detail): void
    {
        $event->setData('generation_promote_failed', true);
        $event->setData('generation_promote_detail', $detail);
        try {
            $dir = \rtrim((string)BP, '/\\') . '/var/log/deploy';
            if (!\is_dir($dir)) {
                @\mkdir($dir, 0775, true);
            }
            @\file_put_contents(
                $dir . '/theme-generation-failed.log',
                \date('c') . ' ' . $detail . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable) {
            // 日志不可用不影响主流程
        }
    }
}
