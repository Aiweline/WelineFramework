<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\Warmup\StorefrontFpcPrewarmer;

/**
 * WLS 启动完成后：先把待固化布局跑完，再预热店面 FPC。
 *
 * 换代/部署后布局固化是 pending 时，页面会走「原始源回退」并被禁止进共享缓存
 * （实测 `x-weline-shared-cache-forbidden: theme_layout_solidify_fallback_origin`，
 * 首屏 233–1350 ms）；预热一次后首访 25–42 ms。这里把两步固化进启动流程，
 * 不再依赖命令行参数或 sleep 兜底。
 *
 * 开关：`theme_fpc_prewarm.on_start`（默认 true）；路径上限 `theme_fpc_prewarm.limit`（默认 8）。
 * 另支持 `theme_fpc_prewarm.products`（热度 PDP）、`extra_locales`、`max`。
 * 失败只记日志，不影响服务启动。
 */
class PrewarmStorefrontOnServerStart implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!$this->enabled()) {
            return;
        }
        // 绝不能把预热挂在启动路径上同步跑：固化+多语种首渲染实测可达分钟级，
        // 会拖住 READY。这里派生独立进程，由它自己按 --delay 排在重载之后执行。
        try {
            $host = \Weline\Framework\Manager\ObjectManager::getInstance(StorefrontFpcPrewarmer::class)->resolveHost();
            if ($host === '') {
                $this->log('skip: no host');

                return;
            }
            $delay = (int)(Env::get('theme_fpc_prewarm.start_delay', 10) ?: 10);
            $limit = (int)(Env::get('theme_fpc_prewarm.limit', 8) ?: 8);
            $products = (int)(Env::get(
                'theme_fpc_prewarm.products',
                StorefrontFpcPrewarmer::DEFAULT_PRODUCT_LIMIT
            ) ?: StorefrontFpcPrewarmer::DEFAULT_PRODUCT_LIMIT);
            $extraLocales = (int)(Env::get('theme_fpc_prewarm.extra_locales', 3) ?: 3);
            $max = (int)(Env::get('theme_fpc_prewarm.max', 32) ?: 32);
            $cmd = 'sleep ' . \max(0, $delay) . ' && ' . \escapeshellarg(PHP_BINARY)
                . ' ' . \escapeshellarg(BP . 'bin/w') . ' theme:fpc-prewarm'
                . ' --site=' . \escapeshellarg($host)
                . ' --limit=' . $limit
                . ' --products=' . \max(0, $products)
                . ' --extra-locales=' . \max(0, $extraLocales)
                . ' --max=' . \max(1, $max);
            \exec($cmd . ' > /dev/null 2>&1 &');
            $this->log(
                'queued host=' . $host
                . ' delay=' . $delay . 's'
                . ' limit=' . $limit
                . ' products=' . $products
                . ' extra_locales=' . $extraLocales
                . ' max=' . $max
            );
        } catch (\Throwable $e) {
            $this->log('failed: ' . $e->getMessage());
        }
    }

    private function enabled(): bool
    {
        try {
            return (bool)Env::get('theme_fpc_prewarm.on_start', true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function log(string $message): void
    {
        if (\function_exists('w_log_info')) {
            \w_log_info('[FpcPrewarmOnStart] ' . $message);
        }
    }
}
