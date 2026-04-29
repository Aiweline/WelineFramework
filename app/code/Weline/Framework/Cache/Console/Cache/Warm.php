<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\Cache\Service\CachePoolHealthWarmer;
use Weline\Framework\Cache\Service\CacheWarmerRegistry;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 缓存预热命令
 *
 * 用法：
 *   php bin/w cache:warm                    # 全量预热
 *   php bin/w cache:warm --pool=router      # 仅预热某个池的 warmer
 *   php bin/w cache:warm --list             # 列出所有已注册 Warmer
 *
 * @package Weline_Framework
 */
class Warm extends CommandAbstract implements CommandInterface
{
    public function tip(): string
    {
        return __('执行 CacheWarmerRegistry 中所有可用的预热器');
    }

    /**
     * @return array|string
     */
    public function help(): array|string
    {
        return [
            __('用法: php bin/w cache:warm [选项]'),
            __(''),
            __('选项:'),
            __('  --pool=池名      仅预热目标 pool 的 warmer'),
            __('  --list          仅列出已注册的预热器，不执行'),
            __(''),
            __('示例:'),
            __('  php bin/w cache:warm'),
            __('  php bin/w cache:warm --pool=router'),
            __('  php bin/w cache:warm --list'),
        ];
    }

    /**
     * @param array $args
     * @param array $data
     * @return mixed|void
     */
    public function execute(array $args = [], array $data = [])
    {
        $registry = ObjectManager::getInstance(CacheWarmerRegistry::class);
        if (!$registry->has('framework.cache_pool_health')) {
            $registry->register(new CachePoolHealthWarmer());
        }

        if (isset($data['list']) || \in_array('--list', $args, true)) {
            $this->renderList($registry);
            return;
        }

        $pool = isset($data['pool']) ? (string)$data['pool'] : null;

        $this->printer->note(__('开始执行缓存预热...'));
        $result = $registry->warmUp($pool !== '' ? $pool : null);

        $this->printer->note(__('预热汇总：total=%{1} ran=%{2} skipped=%{3} warmed=%{4} duration=%{5}ms', [
            $result['total'],
            $result['ran'],
            $result['skipped'],
            $result['warmed'],
            $result['duration_ms'],
        ]));

        foreach ($result['details'] as $detail) {
            $line = \sprintf(
                '  [%s] %s -> pool=%s priority=%d warmed=%d skipped=%d %s',
                $detail['status'],
                $detail['name'],
                $detail['pool'],
                $detail['priority'],
                $detail['warmed'],
                $detail['skipped'],
                $detail['message'] !== '' ? "({$detail['message']})" : ''
            );
            if ($detail['status'] === 'error') {
                $this->printer->error($line);
            } else {
                $this->printer->note($line);
            }
        }

        if ($result['errors'] !== []) {
            $this->printer->warning(__('预热中出现 %{1} 个错误', [\count($result['errors'])]));
        } else {
            $this->printer->success(__('预热完成'));
        }
    }

    /**
     * @return array<int, string>
     */
    public function aliases(): array
    {
        return [
            'cache:warmup',
            'cache:prewarm',
        ];
    }

    private function renderList(CacheWarmerRegistry $registry): void
    {
        $warmers = $registry->all();
        if ($warmers === []) {
            $this->printer->warning(__('尚未注册任何 CacheWarmer'));
            return;
        }
        $this->printer->note(__('已注册预热器：'));
        foreach ($warmers as $w) {
            $this->printer->note(\sprintf(
                '  - %s | pool=%s | priority=%d | enabled=%s',
                $w->getName(),
                $w->getTargetPool(),
                $w->getPriority(),
                $w->canWarm() ? 'true' : 'false'
            ));
        }
    }
}
