<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Fpc;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

/**
 * FPC html-disk 整页缓存清理。
 *
 * 这层缓存由 Nginx `try_files` 直接命中，PHP 不会执行，
 * 因此 `cache:clear` / `server:reload` 都不会让它失效。
 *
 * 清理规则（2026-11-23 用户指定，HARD）：
 *   --url <URL,…>        只清这些地址（html-disk + 路径索引 + router/fpc 池）
 *   --path <路径,…>      只清该路径（需 -w 定位域名；站点全部域名一起清）
 *   -w <站点> --all -f   清该站点全部页面（缺 --all 或 -f 一律不执行）
 *   --all -f             清全部站点（缺一个不执行）
 *   不给任何范围          不清理，改为交互式选择（非 TTY 直接拒绝并退出 1）
 *
 * 默认禁止整站/全量清理：避免一次误清触发全站重建风暴。
 */
class Clear extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $options = $this->parseOptions($args);
        $websiteArg = \trim((string)($options['website'] ?? $options['w'] ?? ''));
        $themeArg = \trim((string)($options['theme'] ?? $options['t'] ?? ''));
        $force = $this->hasFlag($options, $args, 'f', 'force');
        $all = $this->hasFlag($options, $args, 'all');

        $urls = $this->collectValues($args, ['url']);
        $paths = $this->collectValues($args, ['path']);
        // `--url /guide/returns` 这种裸路径按 path 处理，避免把相对路径当完整地址。
        foreach ($urls as $index => $url) {
            if (\preg_match('#^https?://#i', $url) !== 1) {
                $paths[] = $url;
                unset($urls[$index]);
            }
        }
        $urls = \array_values($urls);

        if ($themeArg !== '' && !\is_dir(BP . 'app' . DS . 'design' . DS . 'Weline' . DS . $themeArg)) {
            $this->printer->error(__('未找到主题：%{1}（目录 app/design/Weline/%{1} 不存在）', [$themeArg]));

            return 1;
        }

        if ($urls !== [] || $paths !== []) {
            return $this->purgeTargets($urls, $paths, $websiteArg);
        }

        if (!$all || !$force) {
            if ($all || $force) {
                $this->printer->error(__('整站/全量清理必须同时指定 --all 与 -f；只给一个不执行。'));
                $this->printScopeHint();

                return 1;
            }

            return $this->chooseScope($websiteArg, $themeArg);
        }

        return $this->purgeWholeSite($websiteArg, $themeArg);
    }

    /**
     * 精确清理：URL 列表 +（可选的）站点路径。
     *
     * @param list<string> $urls
     * @param list<string> $paths
     */
    private function purgeTargets(array $urls, array $paths, string $websiteArg): int
    {
        $targets = $urls;
        $hosts = [];
        $slugs = [];

        foreach ($urls as $url) {
            $parts = \parse_url($url);
            $host = \strtolower(\trim((string)($parts['host'] ?? '')));
            $path = (string)($parts['path'] ?? '');
            if ($host !== '') {
                $hosts[] = $host;
            }
            if ($path !== '' && $path !== '/') {
                $slugs[] = \trim($path, '/');
            }
        }

        if ($paths !== []) {
            $siteHosts = $this->resolveHosts($websiteArg);
            if ($siteHosts === []) {
                $this->printer->error(__('--path 需要 -w <站点> 才能定位域名；也可改用 --url 给完整地址。'));
                $this->printScopeHint();

                return 1;
            }
            foreach ($paths as $path) {
                $normalized = $this->normalizePath($path);
                if ($normalized === '') {
                    $this->printer->error(__('路径不合法：%{1}（需以 / 开头，且不含 ? 与 ..）', [$path]));

                    return 1;
                }
                $slugs[] = \trim($normalized, '/');
                foreach ($siteHosts as $host) {
                    $hosts[] = $host;
                    $targets[] = 'https://' . $host . $normalized;
                }
            }
        }

        $targets = \array_values(\array_unique($targets));
        $hosts = \array_values(\array_unique($hosts));
        $slugs = \array_values(\array_unique(\array_filter($slugs, static fn(string $s): bool => $s !== '')));
        if ($targets === [] || $hosts === [] || $slugs === []) {
            $this->printer->error(__('没有可清理的地址。'));

            return 1;
        }

        try {
            // 页桶按请求期 token 分代，CLI 算不到同一 token：按 slug 扫全部页桶。
            FpcHtmlDiskStore::purgeSlugsAnySlot($hosts, $slugs);
            // 共享池 / 路径索引仍按 URL 精确失效。
            FullPageCacheCoordinator::purgeUrls($targets);
        } catch (\Throwable $throwable) {
            $this->printer->error(__('FPC 清理失败：%{1}', [$throwable->getMessage()]));

            return 1;
        }

        foreach ($targets as $target) {
            $this->printer->note(__('已清理：%{1}', [$target]));
        }
        $this->printer->success(__('FPC 已按范围清理：%{1} 个地址（html-disk 全页桶 + 路径索引 + router/fpc 池）', [\count($targets)]));
        $this->printer->note(__('提示：只影响上述地址；清理后首个请求会重新渲染并写回 FPC。'));

        return 0;
    }

    /**
     * 整站/全量清理（调用前已确认 --all 与 -f 同时存在）。
     */
    private function purgeWholeSite(string $websiteArg, string $themeArg): int
    {
        $host = '';
        if ($websiteArg !== '') {
            $host = $this->resolveHost($websiteArg);
            if ($host === '') {
                $this->printer->error(__('未找到站点：%{1}', [$websiteArg]));

                return 1;
            }
        }

        try {
            if ($host !== '') {
                FpcHtmlDiskStore::purgeHosts([$host]);
                $this->printer->success(__(
                    'FPC 已清理（--all -f）：站点 %{1}（host=%{2}）%{3}',
                    [$websiteArg, $host, $themeArg !== '' ? '· 主题 ' . $themeArg : '· 该站点全部主题']
                ));
            } else {
                FpcHtmlDiskStore::purgeAll();
                $this->printer->success(__(
                    'FPC 已清理（--all -f）：%{1}',
                    ['全部站点']
                ));
            }
        } catch (\Throwable $throwable) {
            $this->printer->error(__('FPC 清理失败：%{1}', [$throwable->getMessage()]));

            return 1;
        }

        $this->printer->note(__('提示：该范围清理后首个请求会重新渲染并写回 FPC。'));

        return 0;
    }

    /**
     * 未给范围：交互式选择，默认什么都不清。
     */
    private function chooseScope(string $websiteArg, string $themeArg): int
    {
        if (!$this->isInteractive()) {
            $this->printer->error(__('未指定清理范围：默认不清理（禁止无范围直接全量清理）。'));
            $this->printScopeHint();

            return 1;
        }

        $this->printer->note(__('当前未指定清理范围，请选择要清理的对象：'));
        $this->printer->note('  1) 单个 URL（精确清）');
        $this->printer->note('  2) 单个路径（精确清，需站点）');
        $this->printer->note('  3) 某站点全部页面（危险，需输入确认）');
        $this->printer->note('  4) 全部站点（最危险，需输入确认）');
        $this->printer->note('  0) 取消');

        $choice = $this->ask(__('输入序号（0 取消）：'));
        switch ($choice) {
            case '1':
                $url = $this->ask(__('URL（含 https://）：'));

                return $this->purgeTargets($url !== '' ? [$url] : [], [], $websiteArg);
            case '2':
                $path = $this->ask(__('路径（以 / 开头）：'));
                $site = $websiteArg !== '' ? $websiteArg : $this->ask(__('站点（ID/code/域名）：'));

                return $this->purgeTargets([], $path !== '' ? [$path] : [], $site);
            case '3':
                $site = $websiteArg !== '' ? $websiteArg : $this->ask(__('站点（ID/code/域名）：'));
                if ($site === '' || \strtolower($this->ask(__('确认清理站点 %{1} 的全部页面？输入 yes：', [$site]))) !== 'yes') {
                    $this->printer->note(__('已取消。'));

                    return 1;
                }

                return $this->purgeWholeSite($site, $themeArg);
            case '4':
                if (\strtoupper($this->ask(__('确认清理全部站点的 FPC？输入 YES：'))) !== 'YES') {
                    $this->printer->note(__('已取消。'));

                    return 1;
                }

                return $this->purgeWholeSite('', $themeArg);
            default:
                $this->printer->note(__('已取消，未清理任何缓存。'));

                return 1;
        }
    }

    private function printScopeHint(): void
    {
        $this->printer->note(__('精确清理：php bin/w fpc:clear --url https://example.com/path'));
        $this->printer->note(__('按路径清：php bin/w fpc:clear -w <站点> --path /path'));
        $this->printer->note(__('整站清理：php bin/w fpc:clear -w <站点> --all -f'));
        $this->printer->note(__('全部站点：php bin/w fpc:clear --all -f'));
    }

    private function isInteractive(): bool
    {
        return \defined('STDIN')
            && \is_resource(STDIN)
            && \function_exists('stream_isatty')
            && @\stream_isatty(STDIN);
    }

    private function ask(string $prompt): string
    {
        $this->printer->note($prompt);
        $line = \fgets(STDIN);

        return \is_string($line) ? \trim($line) : '';
    }

    /**
     * 站点解析：支持站点 ID / 站点 code / 域名，返回 host（不含 scheme）。
     */
    private function resolveHost(string $websiteArg): string
    {
        $hosts = $this->resolveHosts($websiteArg);

        return $hosts[0] ?? '';
    }

    /**
     * 站点全部域名（主域名 + WebsiteDomain 行），用于按路径清理。
     *
     * @return list<string>
     */
    private function resolveHosts(string $websiteArg): array
    {
        if ($websiteArg === '') {
            return [];
        }
        if (\str_contains($websiteArg, '.')) {
            return [\strtolower(\trim($websiteArg))];
        }

        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class, [], false);
            if (\ctype_digit($websiteArg)) {
                $website->load((int)$websiteArg);
            } else {
                $website->load('code', $websiteArg);
            }
            if (!$website->getId()) {
                return [];
            }
            $hosts = [];
            $primary = \strtolower(\trim((string)\parse_url((string)$website->getUrl(), PHP_URL_HOST)));
            if ($primary !== '') {
                $hosts[] = $primary;
            }
            try {
                /** @var WebsiteDomain $domain */
                $domain = ObjectManager::getInstance(WebsiteDomain::class, [], false);
                $rows = $domain->reset()
                    ->where(WebsiteDomain::schema_fields_WEBSITE_ID, (int)$website->getId())
                    ->select()
                    ->fetchArray();
                foreach ($rows as $row) {
                    $value = \strtolower(\trim((string)($row[WebsiteDomain::schema_fields_DOMAIN] ?? '')));
                    if ($value !== '') {
                        $hosts[] = $value;
                    }
                }
            } catch (\Throwable) {
                // 域名表不可用时只用主域名。
            }

            return \array_values(\array_unique($hosts));
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizePath(string $path): string
    {
        $path = \trim($path);
        if ($path === '' || \str_contains($path, '?') || \str_contains($path, '..')) {
            return '';
        }
        if (!\str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $path;
    }

    private function hasFlag(array $options, array $args, string ...$names): bool
    {
        foreach ($names as $name) {
            if (isset($options[$name])) {
                return true;
            }
            if (\in_array('-' . $name, $args, true) || \in_array('--' . $name, $args, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 收集可重复、可逗号分隔的选项值（--url a --url b / --url=a,b）。
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function collectValues(array $args, array $names): array
    {
        $values = [];
        foreach ($args as $index => $arg) {
            $arg = (string)$arg;
            if (!\str_starts_with($arg, '-')) {
                continue;
            }
            $arg = \ltrim($arg, '-');
            $name = $arg;
            $value = null;
            if (\str_contains($arg, '=')) {
                [$name, $value] = \explode('=', $arg, 2);
            }
            if (!\in_array($name, $names, true)) {
                continue;
            }
            if ($value === null) {
                $next = $args[$index + 1] ?? null;
                $value = \is_string($next) && !\str_starts_with($next, '-') ? $next : '';
            }
            foreach (\explode(',', (string)$value) as $item) {
                $item = \trim($item);
                if ($item !== '') {
                    $values[] = $item;
                }
            }
        }

        return \array_values(\array_unique($values));
    }

    /**
     * @return array<string, string>
     */
    private function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $index => $arg) {
            $arg = (string)$arg;
            if (!\str_starts_with($arg, '-')) {
                continue;
            }
            $arg = \ltrim($arg, '-');
            if (\str_contains($arg, '=')) {
                [$key, $value] = \explode('=', $arg, 2);
                $options[$key] = $value;
                continue;
            }
            $next = $args[$index + 1] ?? null;
            if (\is_string($next) && !\str_starts_with($next, '-')) {
                $options[$arg] = $next;
            } else {
                $options[$arg] = '1';
            }
        }

        return $options;
    }

    public function tip(): string
    {
        return '清理 FPC 整页缓存（默认只允许精确范围；整站/全量必须 --all -f 同时给）';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'fpc:clear',
            $this->tip(),
            [
                '--url <URL[,URL]>' => '只清指定地址（html-disk + 路径索引 + router/fpc 池）',
                '--path <路径[,路径]>' => '只清指定路径（需 -w 定位域名；站点全部域名一起清）',
                '-w, --website <ID|code|域名>' => '站点范围（配 --all -f 才清该站全部页面）',
                '-t, --theme <name>' => '主题校验（仍需 --all -f 才执行）',
                '--all' => '整站/全量清理开关之一：必须与 -f 同时出现',
                '-f, --force' => '整站/全量清理开关之一：必须与 --all 同时出现',
            ],
            [],
            [
                '清单个 URL' => 'php bin/w fpc:clear --url https://www.example.com/guide/returns',
                '清单个路径' => 'php bin/w fpc:clear -w www.example.com --path /guide/returns',
                '清某站点全部页面' => 'php bin/w fpc:clear -w www.example.com --all -f',
                '清全部站点' => 'php bin/w fpc:clear --all -f',
                '不给范围（交互选择）' => 'php bin/w fpc:clear',
            ]
        );
    }
}
