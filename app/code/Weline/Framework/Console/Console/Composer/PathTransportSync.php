<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Composer;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * Sync path-package transport-options from composer.json repositories.options
 * into composer.lock + vendor/composer/installed.json — without updating the dependency graph.
 *
 * Legal path when branch-pinned lock versions make `composer update --lock` fail:
 * transport sync → `composer reinstall 'weline/*'`.
 */
final class PathTransportSync extends CommandAbstract
{
    public const ALIASES = [
        'composer:path-transport-sync',
        'composer:path-transport',
    ];

    public function tip(): string
    {
        return __('同步 path 包 transport-options.symlink（不 update 依赖图）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'composer:path-transport-sync',
            $this->tip(),
            [
                '--dry-run' => '只打印将改写的包，不写盘',
                '--prefix' => '包名前缀过滤（默认 weline/）',
            ],
            [
                'php bin/w composer:path-transport-sync --dry-run' => '预览',
                'php bin/w composer:path-transport-sync' => '写 lock + installed.json，再提示 reinstall',
            ],
            [
                '禁止用本命令改 version / require / content-hash',
                '消钉 path version 标签属独立 plan，不在本工具范围内',
            ]
        );
    }

    public function execute(array $args = [], array $data = []): int
    {
        if (isset($args['h']) || isset($args['help']) || isset($args['-h']) || isset($args['--help'])) {
            $help = $this->help();
            echo \is_string($help) ? $help : (string)\json_encode($help, JSON_UNESCAPED_UNICODE);
            return 0;
        }

        $dryRun = isset($args['dry-run']) || isset($args['--dry-run']);
        $prefix = (string)($args['prefix'] ?? $args['--prefix'] ?? 'weline/');
        if ($prefix === '') {
            $prefix = 'weline/';
        }

        $root = \defined('BP') ? (string)BP : \dirname(__DIR__, 6);
        $composerJsonPath = $root . '/composer.json';
        $lockPath = $root . '/composer.lock';
        $installedPath = $root . '/vendor/composer/installed.json';

        if (!\is_file($composerJsonPath) || !\is_file($lockPath)) {
            $this->printer->error(__('缺少 composer.json 或 composer.lock'));
            return 1;
        }

        $composerJson = \json_decode((string)\file_get_contents($composerJsonPath), true);
        if (!\is_array($composerJson)) {
            $this->printer->error(__('composer.json 解析失败'));
            return 1;
        }

        $desiredSymlink = $this->resolveDesiredSymlink($composerJson);
        if ($desiredSymlink === null) {
            $this->printer->error(__('composer.json 未找到 type=path 的 repositories.options.symlink'));
            return 1;
        }

        $lock = \json_decode((string)\file_get_contents($lockPath), true);
        if (!\is_array($lock)) {
            $this->printer->error(__('composer.lock 解析失败'));
            return 1;
        }

        $lockChanged = [];
        foreach (($lock['packages'] ?? []) as $i => $package) {
            if (!\is_array($package)) {
                continue;
            }
            if (($package['dist']['type'] ?? '') !== 'path') {
                continue;
            }
            $name = (string)($package['name'] ?? '');
            if ($name === '' || !\str_starts_with($name, $prefix)) {
                continue;
            }
            $current = $package['transport-options']['symlink'] ?? null;
            if ($current === $desiredSymlink) {
                continue;
            }
            $lock['packages'][$i]['transport-options'] = \array_merge(
                \is_array($package['transport-options'] ?? null) ? $package['transport-options'] : [],
                ['symlink' => $desiredSymlink]
            );
            $lockChanged[] = $name;
        }

        $installedChanged = [];
        $installed = null;
        if (\is_file($installedPath)) {
            $installed = \json_decode((string)\file_get_contents($installedPath), true);
            if (\is_array($installed)) {
                $packagesKey = isset($installed['packages']) ? 'packages' : null;
                $list = $packagesKey !== null ? ($installed['packages'] ?? []) : $installed;
                if (\is_array($list)) {
                    foreach ($list as $i => $package) {
                        if (!\is_array($package)) {
                            continue;
                        }
                        if (($package['dist']['type'] ?? '') !== 'path') {
                            continue;
                        }
                        $name = (string)($package['name'] ?? '');
                        if ($name === '' || !\str_starts_with($name, $prefix)) {
                            continue;
                        }
                        $current = $package['transport-options']['symlink'] ?? null;
                        if ($current === $desiredSymlink) {
                            continue;
                        }
                        $merged = \array_merge(
                            \is_array($package['transport-options'] ?? null) ? $package['transport-options'] : [],
                            ['symlink' => $desiredSymlink]
                        );
                        if ($packagesKey !== null) {
                            $installed['packages'][$i]['transport-options'] = $merged;
                        } else {
                            $installed[$i]['transport-options'] = $merged;
                        }
                        $installedChanged[] = $name;
                    }
                }
            }
        }

        $this->printer->note(__(
            '目标 symlink=%{1}；lock 待改 %{2} 个；installed 待改 %{3} 个',
            [$desiredSymlink ? 'true' : 'false', \count($lockChanged), \count($installedChanged)]
        ));
        foreach (\array_unique(\array_merge($lockChanged, $installedChanged)) as $name) {
            $this->printer->note('  - ' . $name);
        }

        if ($lockChanged === [] && $installedChanged === []) {
            $this->printer->success(__('transport-options 已对齐，无需写入'));
            $this->printer->note(__('若 vendor 仍是 directory 副本，执行：composer reinstall \'weline/*\' --no-scripts'));
            return 0;
        }

        if ($dryRun) {
            $this->printer->warning(__('dry-run：未写盘'));
            return 0;
        }

        $lockBackup = $lockPath . '.bak-path-transport-' . \date('YmdHis');
        if (!\copy($lockPath, $lockBackup)) {
            $this->printer->error(__('无法备份 composer.lock → %{1}', [$lockBackup]));
            return 1;
        }
        $this->printer->note(__('已备份 lock：%{1}', [$lockBackup]));

        $lockJson = \json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($lockJson === false || \file_put_contents($lockPath, $lockJson . "\n") === false) {
            $this->printer->error(__('写入 composer.lock 失败'));
            return 1;
        }

        if ($installed !== null && $installedChanged !== [] && \is_file($installedPath)) {
            $installedBackup = $installedPath . '.bak-path-transport-' . \date('YmdHis');
            if (!\copy($installedPath, $installedBackup)) {
                $this->printer->error(__('无法备份 installed.json → %{1}', [$installedBackup]));
                return 1;
            }
            $installedJson = \json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($installedJson === false || \file_put_contents($installedPath, $installedJson . "\n") === false) {
                $this->printer->error(__('写入 installed.json 失败'));
                return 1;
            }
            $this->printer->note(__('已备份 installed：%{1}', [$installedBackup]));
        }

        $this->printer->success(__('transport-options 已同步'));
        $this->printer->note(__('下一步（不 update 依赖图）：composer reinstall \'weline/*\' --no-scripts'));
        return 0;
    }

    /**
     * @param array<string, mixed> $composerJson
     */
    private function resolveDesiredSymlink(array $composerJson): ?bool
    {
        foreach (($composerJson['repositories'] ?? []) as $repo) {
            if (!\is_array($repo) || ($repo['type'] ?? '') !== 'path') {
                continue;
            }
            if (!\array_key_exists('symlink', $repo['options'] ?? [])) {
                continue;
            }
            return (bool)$repo['options']['symlink'];
        }
        return null;
    }
}
