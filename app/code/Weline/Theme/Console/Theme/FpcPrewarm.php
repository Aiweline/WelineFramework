<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\Warmup\StorefrontFpcPrewarmer;

/**
 * 发版/发布后预热店面 FPC：把热门页先渲染一遍写进 FPC，
 * 让第一批真实访客直接吃 HIT（实测冷渲染 230–330ms，HIT 后 38–106ms）。
 *
 * 只在「本机回环 + Host 头」下发请求：不依赖 DNS/证书链，也不经过 CDN。
 */
class FpcPrewarm implements CommandInterface
{
    public const ALIASES = ['theme:fpc-prewarm'];

    /** @var list<string> */
    private const DEFAULT_PATHS = StorefrontFpcPrewarmer::DEFAULT_PATHS;

    public function __construct(
        private readonly Printing $printing,
    ) {
    }

    public function command(): string
    {
        return self::ALIASES[0];
    }

    public function tip(): string
    {
        return __('预热店面 FPC：按站点 Host 依次请求热门页，写入整页缓存');
    }

    public function help(): array
    {
        return [
            'usage' => [
                'php bin/w theme:fpc-prewarm [--site=host] [--paths=/,/products] [--concurrency=2]',
            ],
            'note' => __('不带 --site 时使用当前站点绑定域名；请求打到 127.0.0.1 并带 Host 头'),
        ];
    }

    public function execute(array $args = [], array $options = []): void
    {
        $merged = \array_merge($args, $options);
        $host = \trim((string)($merged['site'] ?? $merged['--site'] ?? ''));
        if ($host === '') {
            foreach ((array)($_SERVER['argv'] ?? []) as $token) {
                if (\is_string($token) && \preg_match('/^--site=(.+)$/', $token, $m) === 1) {
                    $host = \trim($m[1]);
                    break;
                }
            }
        }
        if ($host === '') {
            $host = $this->defaultHost();
        }
        if ($host === '') {
            $this->printing->warning(__('没有可用的站点 Host：请用 --site=<域名> 指定'));

            return;
        }

        $paths = $this->paths($merged);
        if ($paths === []) {
            // 没显式给路径 → 自动发现：首页 + 列表 + Top N 分类 × 站点已启用语种。
            $locales = [];
            $rawLocales = \trim((string)($merged['locales'] ?? ''));
            if ($rawLocales !== '') {
                $locales = \array_map('trim', \explode(',', $rawLocales));
            }
            $paths = ObjectManager::getInstance(StorefrontFpcPrewarmer::class)->discoverPaths(
                (int)($merged['website'] ?? 0),
                (int)($merged['limit'] ?? 8),
                $locales,
                !empty($merged['all-locales']),
            );
            // 上限护栏：铺满语种会到上百条，实测连续冷渲染会把 Worker 打到 503。
            $max = (int)($merged['max'] ?? 20);
            if ($max > 0 && \count($paths) > $max) {
                $paths = \array_slice($paths, 0, $max);
            }

            // 冷渲染里最贵的一段是商品目录构建（实测 333ms/次，按语言+货币分键）。
            // 只补「每个语种的列表页一条」就能把这段在部署后先建好，不铺满分类。
            $extraLocales = (int)($merged['extra-locales'] ?? 0);
            if ($extraLocales > 0) {
                foreach ($this->extraProductListingPaths((int)($merged['website'] ?? 0), $extraLocales) as $extraPath) {
                    if (!\in_array($extraPath, $paths, true)) {
                        $paths[] = $extraPath;
                    }
                }
            }
        }
        if ($paths === []) {
            $this->printing->warning(__('没有要预热的路径：请用 --paths=/a,/b'));

            return;
        }

        // 部署链里命令结束后会触发 WLS 代码重载并清缓存；--delay 让预热排在重载之后。
        $delay = (int)($merged['delay'] ?? 0);
        if ($delay > 0) {
            $this->printing->note(__('等待 %{1}s（避开代码重载）后开始预热…', [(string)$delay]));
            \sleep($delay);
        }

        // 固化未完成时页面走回退且禁止进共享缓存，预热等于白跑：先 drain。
        if (empty($merged['skip-solidify'])) {
            try {
                /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyQueue $queue */
                $queue = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyQueue::class);
                if ($queue->hasPendingJobs()) {
                    $drained = $queue->drainPendingJobs(16);
                    $this->printing->note(__('布局固化：') . \json_encode($drained, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            } catch (\Throwable $e) {
                $this->printing->warning(__('布局固化未完成：') . $e->getMessage());
            }
        }

        $prewarmer = ObjectManager::getInstance(StorefrontFpcPrewarmer::class);
        $startedAt = \microtime(true);
        $result = $prewarmer->run(
            $host,
            $paths,
            (int)($merged['port'] ?? 443),
            (string)($merged['scheme'] ?? 'https'),
            (int)($merged['timeout'] ?? 60),
            (int)($merged['wait-ready'] ?? 0),
            (int)($merged['concurrency'] ?? 2),
        );
        $wallMs = (\microtime(true) - $startedAt) * 1000;

        if (!empty($result['maintenance'])) {
            $this->printing->warning(__('站点处于维护模式（x-weline-maintenance: 1），预热已跳过；先执行 php bin/w maintenance:disable'));

            return;
        }

        $this->printing->note(__('预热站点：') . $host . __('  路径数：') . $result['total']);
        foreach ($result['details'] as $row) {
            $flag = $row['status'] === 200 ? 'OK' : 'FAIL';
            $this->printing->printing(\sprintf('  %-4s %-6d %7.1fms %s', $flag, $row['status'], $row['ms'], $row['path']));
        }
        $this->printing->note(__('完成：') . $result['ok'] . '/' . $result['total']
            . \sprintf('  总耗时 %.0fms（并发 %d）', $wallMs, (int)($merged['concurrency'] ?? 2)));
        if ($result['failed'] !== []) {
            $this->printing->warning(__('失败：') . \implode(', ', $result['failed']));
        }
    }

    /**
     * 追加「每个语种的列表页」预热路径（不含分类），用于覆盖按语言分键的商品目录构建。
     *
     * @return list<string>
     */
    private function extraProductListingPaths(int $websiteId, int $limit): array
    {
        $limit = \max(0, $limit);
        if ($limit === 0) {
            return [];
        }
        $default = '';
        try {
            $default = \strtolower(\trim((string)\Weline\Framework\App\Env::get('locale', '')));
        } catch (\Throwable) {
            $default = '';
        }
        $paths = [];
        try {
            $codes = \Weline\Websites\Data\WebsiteData::languageCodesForWebsite(\max(0, $websiteId));
            foreach ((array)$codes as $code) {
                $code = \trim((string)$code);
                if ($code === '' || \strtolower($code) === $default) {
                    continue;
                }
                $paths[] = '/' . $code . '/products';
                if (\count($paths) >= $limit) {
                    break;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $paths;
    }

    /** @return list<string> */
    private function paths(array $options): array
    {
        $raw = \trim((string)($options['paths'] ?? ''));
        $paths = $raw === ''
            ? []
            : \array_map('trim', \explode(',', $raw));

        $out = [];
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }
            $out[] = \str_starts_with($path, '/') ? $path : '/' . $path;
        }

        return \array_values(\array_unique($out));
    }

    private function defaultHost(): string
    {
        try {
            $host = \trim((string)Env::get('website.url', ''));
            if ($host !== '') {
                return (string)(\parse_url($host, PHP_URL_HOST) ?: $host);
            }
        } catch (\Throwable) {
            // fall through
        }
        try {
            $host = \trim((string)(\w_env('server.HTTP_HOST', '') ?: ''));
            if ($host !== '') {
                return $host;
            }
        } catch (\Throwable) {
            // fall through
        }

        return '';
    }


}
