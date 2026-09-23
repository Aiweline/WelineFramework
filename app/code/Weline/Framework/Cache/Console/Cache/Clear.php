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
    public function execute(array $args = [], array $data = [])
    {
        $isForce = \in_array('-f', $args, true) || \in_array('--force', $args, true);
        $pools = $this->scanner->getCaches()['pools'] ?? [];
        $totalPools = \count($pools);

        if ($totalPools === 0) {
            $this->printing->error(__('没有任何类型的缓存需要清理！'));
            $this->clearViewCompileCaches();

            return;
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

        $this->clearViewCompileCaches();

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
        if ($this->sendWlsCacheClearCommand()) {
            $this->warmStorefrontFpcAfterClear();
        }
    }

    /**
     * Rebuild storefront FPC so the next visitor is not the cold SSR victim.
     */
    private function warmStorefrontFpcAfterClear(): void
    {
        try {
            $registry = $this->cacheWarmerRegistry
                ??= ObjectManager::getInstance(CacheWarmerRegistry::class);
            if (!$registry->has('theme.storefront_fpc')) {
                if (!\class_exists(\Weline\Theme\Service\StorefrontFpcWarmer::class)) {
                    return;
                }
                $registry->register(ObjectManager::getInstance(\Weline\Theme\Service\StorefrontFpcWarmer::class));
            }
            $result = $registry->warmUp('fpc');
            $warmed = (int)($result['warmed'] ?? 0);
            if ($warmed > 0) {
                $this->printing->successIcon(__('店面 FPC 已预热 %{1} 条路径', [$warmed]));
            } else {
                $this->printing->note(__('店面 FPC 预热未命中可暖路径（WLS 未就绪或主机不可达）'));
            }
        } catch (\Throwable $e) {
            $this->printing->note(__('店面 FPC 预热跳过：%{1}', [$e->getMessage()]));
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
            $result = $service->cacheClearAndWait(null, 12.0);

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
            // WLS 未运行时静默忽略，不影响本地缓存清理的展示
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
            ],
            [],
            []
        );
    }
}
