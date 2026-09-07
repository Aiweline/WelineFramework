<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\WarmCache\Console\Cache;

use GuzzleHttp\Client;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\Service\CachePoolHealthWarmer;
use Weline\Framework\Cache\Service\CacheWarmerRegistry;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Warm implements CommandInterface
{
    private ?Client $client = null;
    private Printing $printing;

    public function __construct(Printing $printing, ?Client $client = null)
    {
        $this->printing = $printing;
        // 如果 Client 未提供，延迟创建
        $this->client = $client ?? new Client();
    }

    public function execute(array $args = [], array $data = [])
    {
        if ($this->shouldUseRegistryWarmers($args, $data)) {
            $this->runRegistryWarmers($args, $data);

            return;
        }

        $domain = $args[1] ?? $args['domain'] ?? '';
        if (!$domain) {
            $domain = Env::get('domain') ?: '';
            if (!$domain) {
                $env_path = str_replace(BP, '', Env::path_ENV_FILE);
                $this->printing->warning(__('请输入域名！或者在 %{1} 中添加domain键【指定域名后无需输入域名即可运行命令：php bin/w cache:warm】。命令行指定示例：php bin/w cache:warm www.aiweline.com ', $env_path));
                exit(1);
            }
        }
        $frontend_routers = (array)(require Env::path_FRONTEND_PC_ROUTER_FILE);
        $this->printing->warning(__('缓存预热开始...'));
        foreach ($frontend_routers as $frontend_router => $router_data) {
            $frontend_router = explode('::', $frontend_router);
            $frontend_url = array_shift($frontend_router);
            $method = 'get';
            if ($frontend_router) {
                $method = strtolower(array_shift($frontend_router));
            }
            $url = 'http://' . $domain . '/' . $frontend_url;
            $this->printing->note($url);
            try {
                $this->client->$method($url);
            } catch (\Exception $exception) {
                $this->printing->warning($exception->getMessage());
            }
        }
        $this->printing->success('缓存预热完成！');
    }

    /**
     * The legacy command accepted a positional domain and warmed every
     * frontend route through Guzzle. When no domain is supplied, route
     * warming cannot work, so use the framework registry and its scope-aware
     * storefront warmers instead.
     */
    private function shouldUseRegistryWarmers(array $args, array $data): bool
    {
        if (isset($data['pool']) || isset($args['pool']) || isset($data['list']) || isset($args['list']) || isset($data['registry'])) {
            return true;
        }
        foreach (['--list', '-l'] as $flag) {
            if (in_array($flag, $args, true)) {
                return true;
            }
        }

        foreach ($args as $key => $value) {
            if (!is_int($key) || $key <= 0 || !is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '' && !str_starts_with($value, '-')) {
                return false;
            }
        }

        return trim((string)(Env::get('domain') ?: '')) === '';
    }

    private function runRegistryWarmers(array $args, array $data): void
    {
        $registry = ObjectManager::getInstance(CacheWarmerRegistry::class);
        if (!$registry->has('framework.cache_pool_health')) {
            $registry->register(new CachePoolHealthWarmer());
        }
        if (class_exists(\Weline\Theme\Service\StorefrontFpcWarmer::class)
            && !$registry->has('theme.storefront_fpc')
        ) {
            $registry->register(ObjectManager::getInstance(\Weline\Theme\Service\StorefrontFpcWarmer::class));
        }

        if (isset($data['list']) || isset($args['list']) || in_array('--list', $args, true) || in_array('-l', $args, true)) {
            $this->renderRegistryList($registry);

            return;
        }

        $pool = trim((string)($data['pool'] ?? $args['pool'] ?? ''));
        $this->printing->note(__('开始执行统一缓存预热...'));
        $result = $registry->warmUp($pool !== '' ? $pool : null);
        $this->printing->note(__('预热汇总：total=%{1} ran=%{2} skipped=%{3} warmed=%{4} duration=%{5}ms', [
            $result['total'],
            $result['ran'],
            $result['skipped'],
            $result['warmed'],
            $result['duration_ms'],
        ]));
        foreach ($result['details'] as $detail) {
            $this->printing->note(sprintf(
                '  [%s] %s -> pool=%s warmed=%d skipped=%d %s',
                $detail['status'],
                $detail['name'],
                $detail['pool'],
                $detail['warmed'],
                $detail['skipped'],
                $detail['message'] !== '' ? '(' . $detail['message'] . ')' : '',
            ));
        }
        if ($result['errors'] !== []) {
            $this->printing->warning(__('预热中出现 %{1} 个错误', [count($result['errors'])]));
        } else {
            $this->printing->success(__('统一缓存预热完成！'));
        }
    }

    private function renderRegistryList(CacheWarmerRegistry $registry): void
    {
        $this->printing->note(__('已注册统一缓存预热器：'));
        foreach ($registry->all() as $warmer) {
            $this->printing->note(sprintf(
                '  - %s | pool=%s | priority=%d | enabled=%s',
                $warmer->getName(),
                $warmer->getTargetPool(),
                $warmer->getPriority(),
                $warmer->canWarm() ? 'true' : 'false',
            ));
        }
    }

    public function tip(): string
    {
        return __('提供缓存预热,加速网页访问');
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '--pool=池名' => '仅预热指定缓存池（如 fpc）',
                '--list' => '列出统一缓存预热器',
                '域名' => '兼容旧模式：按域名预热前端路由',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
