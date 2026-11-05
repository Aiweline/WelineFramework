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
use Weline\Websites\Model\Website;

/**
 * FPC html-disk 整页缓存清理。
 *
 * 这层缓存由 Nginx `try_files` 直接命中，PHP 不会执行，
 * 因此 `cache:clear` / `server:reload` 都不会让它失效。
 *
 * 清理范围：
 *   -w <站点>            只清该站点（其所有主题共用同一 host 树）
 *   -t <主题>            只清该主题（html-disk 无主题维度 → 等价全站 html-disk 清理，但先校验主题存在）
 *   -w <站点> -t <主题>  两个条件都要满足才清（站点存在 + 主题存在）
 *   -f                   清全部站点
 * 不给任何范围时拒绝执行（防误清）。
 */
class Clear extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $options = $this->parseOptions($args);
        $websiteArg = \trim((string)($options['website'] ?? $options['w'] ?? ''));
        $themeArg = \trim((string)($options['theme'] ?? $options['t'] ?? ''));
        $force = isset($options['f']) || isset($options['force']) || \in_array('-f', $args, true) || \in_array('--force', $args, true);

        if (!$force && $websiteArg === '' && $themeArg === '') {
            $this->printer->error(__('必须指定清理范围：-w <站点> / -t <主题> / -f（全部）'));
            $this->printer->note(__('例：php bin/w fpc:clear -w changanhanfu.test.weline.com -t hanfu'));

            return 1;
        }

        if ($themeArg !== '' && !\is_dir(BP . 'app' . DS . 'design' . DS . 'Weline' . DS . $themeArg)) {
            $this->printer->error(__('未找到主题：%{1}（目录 app/design/Weline/%{1} 不存在）', [$themeArg]));

            return 1;
        }

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
                    'FPC 已清理：站点 %{1}（host=%{2}）%{3}',
                    [$websiteArg, $host, $themeArg !== '' ? '· 主题 ' . $themeArg : '· 该站点全部主题']
                ));
            } else {
                FpcHtmlDiskStore::purgeAll();
                $this->printer->success(__(
                    'FPC 已清理：%{1}',
                    [$force ? '全部站点' : '全部站点（按主题 ' . $themeArg . ' 校验后清理）']
                ));
            }
        } catch (\Throwable $throwable) {
            $this->printer->error(__('FPC 清理失败：%{1}', [$throwable->getMessage()]));

            return 1;
        }

        $this->printer->note(__('提示：清理后首个请求会重新渲染并写回 FPC。'));

        return 0;
    }

    /**
     * 站点解析：支持站点 ID / 站点 code / 域名，返回 host（不含 scheme）。
     */
    private function resolveHost(string $websiteArg): string
    {
        if (\str_contains($websiteArg, '.')) {
            return \strtolower(\trim($websiteArg));
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
                return '';
            }
            $host = (string)\parse_url((string)$website->getUrl(), PHP_URL_HOST);

            return \strtolower(\trim($host));
        } catch (\Throwable) {
            return '';
        }
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
        return '清理 FPC html-disk 整页缓存（Nginx try_files 命中的那一层）';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'fpc:clear',
            $this->tip(),
            [
                '-w, --website <ID|code|域名>' => '站点范围（清该站点所有主题）',
                '-t, --theme <name>' => '主题范围（校验存在；单独给则清全部站点）',
                '-f, --force' => '清全部站点（不再校验站点/主题）',
            ],
            [],
            [
                '按站点清' => 'php bin/w fpc:clear -w changanhanfu.test.weline.com',
                '站点 + 主题' => 'php bin/w fpc:clear -w changanhanfu.test.weline.com -t hanfu',
                '按主题清' => 'php bin/w fpc:clear -t hanfu',
                '清全部' => 'php bin/w fpc:clear -f',
            ]
        );
    }
}
