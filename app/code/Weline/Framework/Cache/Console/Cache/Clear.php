<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\Cache\Scanner;
use Weline\Framework\Cache\Service\CacheWarmerRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\TemplateCacheManager;

class Clear implements \Weline\Framework\Console\CommandInterface
{
    private Scanner $scanner;

    private Printing $printing;

    private ?RuntimeControlBroadcasterInterface $broadcastService = null;

    private ?CacheWarmerRegistry $cacheWarmerRegistry = null;

    public function __construct(
        Scanner  $scanner,
        Printing $printing,
        ?RuntimeControlBroadcasterInterface $broadcastService = null,
        ?CacheWarmerRegistry $cacheWarmerRegistry = null,
    ) {
        $this->scanner = $scanner;
        $this->printing = $printing;
        $this->broadcastService = $broadcastService;
        $this->cacheWarmerRegistry = $cacheWarmerRegistry;
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
        // pools-only / skip_view_compile：只 flush 缓存池（+可选 WLS IPC），不删模板编译目录。
        // 编译产物（view/tpl、generated/complicate）应由调用方一次 rm -rf，避免与 flush 叠成慢路径。
        $skipViewCompile = !empty($data['skip_view_compile'])
            || \in_array('--pools-only', $args, true)
            || \in_array('--skip-view-compile', $args, true);
        $pools = $this->scanner->getCaches()['pools'] ?? [];
        $totalPools = \count($pools);

        if ($totalPools === 0) {
            $this->printing->error(__('没有任何类型的缓存需要清理！'));
            if (!$skipViewCompile) {
                $this->clearViewCompileCaches();
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

        if (!$skipViewCompile) {
            $this->clearViewCompileCaches();
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
            // Soft-clear (--pools-only) must warm after flush so quiet-cold HTTP
            // sees chrome/shelf L2. Full clear warms after view/tpl delete.
            // Skip only when WLS clear itself failed (caller already returned).
            $fpcWarmOk = $this->warmStorefrontFpcAfterClear();
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
     * Rebuild storefront FPC so the next visitor is not the cold SSR victim.
     *
     * @return bool true only when fpc pool warmed at least one path (chrome/bags may still warn)
     */
    private function warmStorefrontFpcAfterClear(): bool
    {
        $this->printing->note(__('店面 Clear→warm 编排开始：fpc→website→layout_projection→product→search→chrome(子进程)'));
        $poolNotes = [];
        $fpcWarmed = 0;
        try {
            $registry = $this->cacheWarmerRegistry
                ??= ObjectManager::getInstance(CacheWarmerRegistry::class);
            // Owning modules register via provides cache.warmer.* (no Theme FQCN soft-pull).
            $fpc = $registry->warmUp('fpc');
            $fpcWarmed = (int)($fpc['warmed'] ?? 0);
            if ($fpcWarmed > 0) {
                $this->printing->successIcon(__('店面 FPC 已预热 %{1} 条路径', [$fpcWarmed]));
            } else {
                $this->printing->warning(__('店面 FPC 预热未命中可暖路径（WLS 未就绪或主机不可达）'));
            }
            $poolNotes[] = 'fpc:warmed=' . $fpcWarmed;

            // Website pool: store/channel catalog HotCache (soft-clear cold hit1 tax).
            $website = $registry->warmUp('website');
            $this->reportWarmUpDetails($website, '网站范围目录已预热 %{1} 条');
            $poolNotes[] = 'website:warmed=' . (int)($website['warmed'] ?? 0);

            // Layout-entity projection pool (Theme warmer provides; no Theme FQCN soft-pull).
            $layout = $registry->warmUp('weline_theme_layout_entity_published_projection');
            $this->reportWarmUpDetails($layout, '部件静态源袋已预热 %{1} 条');
            $poolNotes[] = 'layout_projection:warmed=' . (int)($layout['warmed'] ?? 0);

            // Product pool: search projection + shelf/offers + PLP light warmers (Registry details).
            $product = $registry->warmUp('product');
            $this->reportWarmUpDetails($product, '商品池缓存已预热 %{1} 条');
            $poolNotes[] = 'product:warmed=' . (int)($product['warmed'] ?? 0);

            // Search pool: bounded tip search.query_result warmer (owning module provides).
            $search = $registry->warmUp('search');
            $this->reportWarmUpDetails($search, '搜索池缓存已预热 %{1} 条');
            $poolNotes[] = 'search:warmed=' . (int)($search['warmed'] ?? 0);

            // Chrome must be re-seeded in a fresh CLI process after WLS IPC clear.
            // In-process warmUp here shares the Clear RequestContext / generation
            // dust and quiet dig still rebuilds theme.storefront_chrome (G-chrome-ex).
            $chromeOk = $this->reseedStorefrontChromeLocalesViaSubprocess();
            $poolNotes[] = $chromeOk ? 'chrome:gate_ok' : 'chrome:gate_miss_or_fail';

            $this->printing->note(__('店面 Clear→warm 编排结束：%{1}', [\implode(' · ', $poolNotes)]));
            $fpcOk = $fpcWarmed > 0;
            // Machine-readable markers — acceptance MUST NOT treat fpc:0 as mix-ready.
            $this->printing->note('fpc_warm_ok=' . ($fpcOk ? '1' : '0'));
            $this->printing->note('storefront_mix_ready=' . ($fpcOk ? '1' : '0'));
            if (!$fpcOk) {
                $this->printing->warning(__('店面 FPC warmed=0：禁止假绿「暖后混发」；chrome/袋 gate_ok 不可冒充整页 FPC'));
            } elseif (!$chromeOk) {
                $this->printing->warning(__('店面 Clear→warm FPC 已暖但 chrome 未完整；禁止把裸冷混发当唯一成功标准'));
            }

            return $fpcOk;
        } catch (\Throwable $e) {
            $this->printing->warning(__('店面 Clear→warm 编排失败（未假成功）：%{1}', [$e->getMessage()]));
            if ($poolNotes !== []) {
                $this->printing->note(__('店面 Clear→warm 编排结束（部分）：%{1}', [\implode(' · ', $poolNotes)]));
            } else {
                $this->printing->note(__('店面 Clear→warm 编排结束：失败可见'));
            }
            $this->printing->warning('fpc_warm_ok=0');
            $this->printing->warning('storefront_mix_ready=0');

            return false;
        }
    }

    /**
     * Fresh-process chrome locale warm after soft-clear IPC.
     * In-process Registry warm shares Clear dust and fails quiet G-chrome-ex.
     *
     * @return bool true when subprocess output contains gate_ok (honest success)
     */
    private function reseedStorefrontChromeLocalesViaSubprocess(): bool
    {
        if (!\defined('BP')) {
            $this->printing->warning(__('店面 chrome 子进程预热跳过：BP 未定义'));

            return false;
        }
        $php = \PHP_BINARY !== '' ? \PHP_BINARY : 'php';
        $bin = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
        if (!\is_file($bin)) {
            $this->printing->warning(__('店面 chrome 子进程预热跳过：找不到 bin/w'));

            return false;
        }
        $cmd = \escapeshellarg($php)
            . ' -d memory_limit=512M -d opcache.enable_cli=0 '
            . \escapeshellarg($bin)
            . ' cache:warm --pool=weline_theme_storefront_chrome 2>&1';
        $this->printing->note(__('店面 chrome 语种袋：子进程二次预热…'));
        $output = [];
        $code = 0;
        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- intentional Clear subprocess
        @\exec($cmd, $output, $code);
        $joined = \implode("\n", $output);
        $sawGateOk = \str_contains($joined, 'gate_ok:');
        foreach ($output as $line) {
            $line = \trim((string)$line);
            if ($line === '') {
                continue;
            }
            if (\str_contains($line, 'gate_ok:') || \str_contains($line, 'theme.storefront_chrome_locales')) {
                if (\str_contains($line, 'warm_partial=1') || \str_contains($line, 'remaining=')) {
                    $this->printing->warning($line);
                } else {
                    $this->printing->successIcon($line);
                }
            }
        }
        if ($code !== 0 && !$sawGateOk) {
            $this->printing->warning(__('店面 chrome 子进程预热失败 exit=%{1}', [(string)$code]));

            return false;
        }
        if (!$sawGateOk) {
            $this->printing->warning(__('店面 chrome 子进程预热未见 gate_ok（exit=%{1}；CLI 暖≠Worker HIT）', [(string)$code]));

            return false;
        }

        return true;
    }

    /**
     * Print each warmer detail line so multi-warmer pools (e.g. product) do not
     * collapse into one aggregate count that hides shelf/offers messages.
     *
     * @param array<string, mixed> $result CacheWarmerRegistry::warmUp result
     */
    private function reportWarmUpDetails(array $result, string $aggregateFallbackTemplate): void
    {
        $details = \is_array($result['details'] ?? null) ? $result['details'] : [];
        if ($details === []) {
            $warmed = (int)($result['warmed'] ?? 0);
            if ($warmed > 0) {
                $this->printing->successIcon(__($aggregateFallbackTemplate, [$warmed]));
            } else {
                $this->printing->warning(__($aggregateFallbackTemplate, [$warmed]) . ' (warmed=0)');
            }

            return;
        }

        foreach ($details as $detail) {
            if (!\is_array($detail)) {
                continue;
            }
            $name = (string)($detail['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $status = (string)($detail['status'] ?? '');
            $warmed = (int)($detail['warmed'] ?? 0);
            $message = \trim((string)($detail['message'] ?? ''));

            if ($status === 'error') {
                $this->printing->note(__('缓存预热失败 %{1}：%{2}', [$name, $message !== '' ? $message : 'error']));
                continue;
            }
            if ($status === 'skipped') {
                $this->printing->note(__('缓存预热跳过 %{1}：%{2}', [$name, $message !== '' ? $message : 'skipped']));
                continue;
            }

            // Always surface ok lines that carry L2 / remaining evidence — even warmed=0 peek_hit.
            $line = $message !== ''
                ? __('缓存预热 %{1} warmed=%{2} %{3}', [$name, $warmed, $message])
                : __('缓存预热 %{1} warmed=%{2}', [$name, $warmed]);
            $partial = $message !== '' && (
                \str_contains($message, 'remaining=')
                || \str_contains($message, 'warm_partial=1')
                || \str_contains($message, 'host=unresolved')
                || \str_contains($message, 'gate_miss:')
            );
            $hasL2Evidence = \str_contains($message, 'warmed_l2_hit')
                || \str_contains($message, 'peek_hit')
                || \str_contains($message, 'shelf_l2');
            if ($partial) {
                // Budget cut / host miss / gate miss — never green as full L2 PASS.
                $this->printing->warning($line);
            } elseif ($warmed > 0 || $hasL2Evidence) {
                $this->printing->successIcon($line);
            } else {
                $this->printing->note($line);
            }
        }
    }

    /**
     * 清理模板编译相关缓存，避免源码已更新但继续读取旧编译产物。
     */
    private function clearViewCompileCaches(): void
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

        try {
            ObjectManager::getInstance(\Weline\Framework\Cache\Console\Template\Clear::class)
                ->execute([], ['silent' => true]);
        } catch (\Throwable) {
            // Template cache CLI clear is best-effort during global cache clear.
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
                '--pools-only, --skip-view-compile' => '仅 flush 缓存池（不删 view/tpl / generated/complicate）',
            ],
            [],
            []
        );
    }
}
