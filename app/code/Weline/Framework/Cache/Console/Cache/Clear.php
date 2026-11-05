<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\App\SystemUpdateActivity;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Setup\Lock\SetupUpgradeIntent;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\TemplateCacheManager;

class Clear implements \Weline\Framework\Console\CommandInterface
{
    private Scanner $scanner;

    private Printing $printing;

    private ?RuntimeControlBroadcasterInterface $broadcastService = null;

    public function __construct(
        Scanner  $scanner,
        Printing $printing,
        ?RuntimeControlBroadcasterInterface $broadcastService = null,
    ) {
        $this->scanner = $scanner;
        $this->printing = $printing;
        $this->broadcastService = $broadcastService;
    }

    /**
     * 获取广播控制服务（延迟初始化，避免 CLI 场景不必要开销）
     */
    private function getBroadcastService(): RuntimeControlBroadcasterInterface
    {
        if ($this->broadcastService === null) {
            $provider = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(RuntimeControlBroadcasterInterface::class);
            if (!$provider instanceof RuntimeControlBroadcasterInterface) {
                throw new \RuntimeException('No runtime control broadcaster is registered.');
            }
            $this->broadcastService = $provider;
        }

        return $this->broadcastService;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): ?int
    {
        $isForce = \in_array('-f', $args, true) || \in_array('--force', $args, true);
        // pools-only：只 flush 缓存池（+可选 WLS IPC），跳过 taglib/模板缓存失效。
        // 编译产物（view/tpl、generated/complicate）不归缓存管理：任何参数下都不删除。
        $skipViewCaches = \in_array('--pools-only', $args, true);
        // 仅失效不预热（如部署模式切换）：店面预热交给定时任务按构建世代变化触发，
        // 避免每次切换模式都在这里全量重暖。
        $skipWarm = !empty($data['skip_warm'])
            || \in_array('--no-warm', $args, true)
            || \in_array('--skip-warm', $args, true);
        // 预热不属于系统更新/维护窗口的职责：更新期间（维护门禁开、升级意图活跃）只失效不预热。
        // 否则一次更新里由任意子命令触发的清缓存都会同步跑满店面预热预算（实测 5 分钟级），
        // 而更新后页面本就会按新世代重建；预热交给定时任务按构建世代变化触发。
        if (!$skipWarm && self::systemUpdateWindowActive()) {
            $skipWarm = true;
            $this->printing->note(__('系统更新/维护窗口进行中：本次只失效不预热（预热由定时任务按构建世代触发）。'));
        }
        $pools = $this->scanner->getCaches()['pools'] ?? [];
        $totalPools = \count($pools);

        if ($totalPools === 0) {
            $this->printing->error(__('没有任何类型的缓存需要清理！'));
            if (!$skipViewCaches) {
                $this->clearViewCaches();
            }

            return 0;
        }

        $cleared = 0;
        $skippedPermanent = 0;
        $currentIndex = 0;
        $title = $isForce ? __('强制清理全部缓存池...') : __('清理非持久缓存池...');
        $this->printing->progressBar(0, $totalPools, $title, 30);

        foreach ($pools as $poolInfo) {
            $currentIndex++;
            $identity = (string)($poolInfo['identity'] ?? '');
            $permanent = (bool)($poolInfo['permanent'] ?? false);
            $this->printing->progressBar($currentIndex, $totalPools, $title, 30);

            if ($identity === '') {
                continue;
            }

            if (!$isForce && $permanent) {
                $skippedPermanent++;
                continue;
            }

            if ($this->scanner->clearPool($identity)) {
                $cleared++;
            }
        }

        if (!$skipViewCaches) {
            $this->clearViewCaches();
        }

        if ($cleared > 0) {
            $this->printing->doneIcon(__('缓存清理完成！'));
            $this->printing->coloredText(__('   📊 已清理缓存池: %{1} 个', [$cleared]), $this->printing::NOTE);
            if ($skippedPermanent > 0) {
                $this->printing->coloredText(
                    __('   ⏭️  跳过持久缓存池: %{1} 个（使用 -f 强制清理）', [$skippedPermanent]),
                    $this->printing::NOTE
                );
            }
        } else {
            $this->printing->infoIcon(__('所有缓存都是最新的，无需清理'));
            if ($skippedPermanent > 0) {
                $this->printing->note(__('有 %{1} 个持久缓存池未清理；需要时请加 -f', [$skippedPermanent]));
            }
        }

        // 向 WLS 发送缓存清理命令（进程内缓存失效，不重启 Worker）
        $fpcWarmOk = false;
        if ($this->sendWlsCacheClearCommand()) {
            // 预热统一交给 cron 定时任务（按构建世代触发）；清缓存只做失效，
            // 不在命令里发起店面 HTTP 预热请求，避免清缓存顺带压源站。
            $this->printing->note(__('已跳过店面预热：预热由 cron 定时任务按构建世代执行。'));
            $fpcWarmOk = true;
        } else {
            // Machine-readable honesty markers for acceptance scripts (W6).
            $this->printing->warning('fpc_warm_ok=0');
            $this->printing->warning('storefront_mix_ready=0');
            $this->printing->warning(__('店面 Clear→warm 跳过：WLS 清理未完成；禁止宣称暖后可抗混发'));
        }

        // Fail-closed exit: fpc:warmed=0 must not look like a green clear for mix scripts.
        return $fpcWarmOk ? 0 : 2;
    }

    /**
     * 系统更新 / 维护窗口判定：维护门禁已开，或 setup:upgrade 升级意图活跃。
     *
     * 这两个信号本身就是「系统更新命令正在跑」的机器可判定事实；
     * 更新窗口内清缓存只做失效，不做店面预热。
     */
    private static function systemUpdateWindowActive(): bool
    {
        try {
            if (SystemUpdateActivity::inProgress()) {
                return true;
            }
        } catch (\Throwable) {
            // 判定失败不阻断清理，按无更新窗口处理（保持旧行为）。
        }
        try {
            return SetupUpgradeIntent::isActive();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 清理视图/模板增强缓存（taglib 注册表 + 模板缓存袋）。
     *
     * 不删除编译产物：模块 view/tpl 与 generated/complicate/<Vendor>/<Module>/view
     * 是编译产物，cache:clear 只做失效，需要删产物请显式执行 cache:template:clear。
     */
    private function clearViewCaches(): void
    {
        try {
            $taglib = ObjectManager::getInstance(Taglib::class);
            if ($taglib instanceof Taglib) {
                $taglib->clearCache();
            }
        } catch (\Throwable) {
            // View may be unavailable in stripped CLI contexts.
        }

        try {
            TemplateCacheManager::getInstance()->clearAll();
        } catch (\Throwable) {
            // Enhanced template cache clear is best-effort during global cache clear.
        }
    }

    /**
     * 向 WLS 发送缓存清理 IPC 命令
     */
    private function sendWlsCacheClearCommand(): bool
    {
        try {
            $service = $this->getBroadcastService();
            // Mix30 / multi-Worker soft-clear needs headroom above the historical 12s
            // (8 Ready Workers often finish after ~12–20s). Cap is 30s in RuntimeControlBroadcaster.
            $result = $service->cacheClearAndWait(null, 30.0);

            if (!empty($result['success']) && !empty($result['completed'])) {
                $this->printing->successIcon(__('WLS 缓存清理已完成'));
                if (!empty($result['message'])) {
                    $this->printing->note($result['message']);
                }

                return true;
            }

            $this->printing->warning(__(
                'WLS 缓存清理未完成，已跳过店面 FPC 预热：%{1}',
                [$result['message'] ?? __('未知错误')]
            ));
        } catch (\Throwable $e) {
            // Must stay failure-visible (C4): skipping warm after IPC failure must not look like success.
            $this->printing->warning(__(
                'WLS 缓存清理未完成，已跳过店面 FPC 预热：%{1}',
                [$e->getMessage() !== '' ? $e->getMessage() : __('WLS 不可达或广播失败')]
            ));
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '缓存清理。';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-f, --force' => '强制清理（含持久缓存池）',
                '--pools-only' => '仅 flush 缓存池（跳过 taglib/模板缓存失效；编译产物从不删除）',
                '--no-warm, --skip-warm' => '只失效不预热店面（预热由定时任务按构建世代触发）',
            ],
            [],
            []
        );
    }
}
