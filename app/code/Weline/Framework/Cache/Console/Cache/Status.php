<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Output\Cli\Printing;

class Status implements \Weline\Framework\Console\CommandInterface
{
    private Scanner $scanner;
    private Printing $printing;

    public function __construct(
        Scanner  $scanner,
        Printing $printing
    ) {
        $this->scanner = $scanner;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $op = null;
        if (isset($args[1]) && \in_array($args[1], ['enable', 'disable'], true)) {
            $op = $args[1];
        }

        if ($op) {
            $this->applyStatusChange($op, $args);
            return;
        }

        $identify_s = [];
        foreach ($args as $key => $value) {
            if ($key === 0 || $key === 'command') {
                continue;
            }
            if (!empty($value) && !\in_array($value, ['enable', 'disable'], true)) {
                $identify_s[] = (string)$value;
            }
        }

        if ($identify_s) {
            $this->printSpecific($identify_s);
        } else {
            $this->printAll();
        }
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function applyStatusChange(string $op, array $args): void
    {
        $status = $op === 'enable' ? 1 : 0;
        $poolList = $this->scanner->getPoolList();
        $known = [];
        foreach ($poolList as $pool) {
            $identity = (string)($pool['identity'] ?? '');
            if ($identity !== '') {
                $known[$identity] = true;
            }
        }

        $identify_s = [];
        foreach ($args as $key => $value) {
            if ($key === 0 || $key === 1 || $key === 'command') {
                continue;
            }
            if (!empty($value)) {
                $identify_s[] = (string)$value;
            }
        }

        $cache_config = Env::getInstance()->getData('cache') ?? [];
        $set_data = $cache_config['status'] ?? [];
        $no_has_data = [];

        if ($identify_s) {
            foreach ($identify_s as $identify) {
                if (!isset($known[$identify])) {
                    $no_has_data[] = $identify;
                    continue;
                }
                $set_data[$identify] = $status;
            }
        } else {
            foreach (\array_keys($known) as $identify) {
                $set_data[$identify] = $status;
            }
        }

        $cache_config['status'] = $set_data;
        Env::getInstance()->setConfig('cache', $cache_config);
        $this->printAll();

        if ($no_has_data) {
            $this->printing->errorIcon(__('不存在的缓存标识：'));
            $this->printing->list($no_has_data);
        }
    }

    /**
     * 打印所有缓存状态
     */
    public function printAll(): void
    {
        $this->printing->title(__('缓存状态总览'), '-', $this->printing::SUCCESS);

        $pools = $this->scanner->getPoolList();
        $totalStats = ['enabled' => 0, 'disabled' => 0, 'total' => 0, 'size' => 0];

        $this->printing->separator('─', 60, $this->printing::NOTE);
        $this->printing->coloredText(__('📦 缓存池'), $this->printing::WARNING, 'bold');
        $this->printing->separator('─', 60, $this->printing::NOTE);

        $groupStats = $this->printPoolTable($pools);
        $totalStats = $this->mergeStats($totalStats, $groupStats);

        $this->printing->separator('=', 60, $this->printing::SUCCESS);
        $this->printing->coloredText(__('📊 总体统计'), $this->printing::SUCCESS, 'bold');
        $this->printing->coloredText(__('📁 缓存目录位置: %{1}', ['var/cache/']), $this->printing::NOTE);
        $this->printing->keyValue([
            __('总缓存数') => $totalStats['total'],
            __('已启用') => $totalStats['enabled'],
            __('已禁用') => $totalStats['disabled'],
            __('总占用空间') => $this->formatBytes($totalStats['size']),
        ], ':', 15);
    }

    /**
     * 打印指定缓存状态
     *
     * @param string[] $identifies
     */
    public function printSpecific(array $identifies): void
    {
        $this->printing->title(__('指定缓存状态'), '-', $this->printing::SUCCESS);

        $poolsByIdentity = [];
        foreach ($this->scanner->getPoolList() as $pool) {
            $identity = (string)($pool['identity'] ?? '');
            if ($identity !== '') {
                $poolsByIdentity[$identity] = $pool;
            }
        }

        $found = [];
        $notFound = [];
        foreach ($identifies as $identify) {
            if (isset($poolsByIdentity[$identify])) {
                $found[] = $poolsByIdentity[$identify];
            } else {
                $notFound[] = $identify;
            }
        }

        if ($found) {
            $this->printPoolTable($found);
        }

        if ($notFound) {
            $this->printing->separator('─', 50, $this->printing::WARNING);
            $this->printing->warningIcon(__('未找到的缓存标识:'));
            $this->printing->list($notFound, '•', $this->printing::ERROR);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $pools
     * @return array{enabled:int,disabled:int,total:int,size:int}
     */
    private function printPoolTable(array $pools): array
    {
        $stats = ['enabled' => 0, 'disabled' => 0, 'total' => 0, 'size' => 0];
        if ($pools === []) {
            return $stats;
        }

        $headers = [__('标识'), __('状态'), __('持久'), __('占用空间'), __('适配器'), __('描述')];
        $rows = [];

        foreach ($pools as $pool) {
            $identity = (string)($pool['identity'] ?? '');
            $enabled = (bool)($pool['enabled'] ?? true);
            $permanent = (bool)($pool['permanent'] ?? false);
            $size = $this->getPoolDirectorySize($identity);
            $adapter = (string)($pool['adapter'] ?? '');
            if (\str_contains($adapter, '\\')) {
                $adapter = \substr($adapter, (int)\strrpos($adapter, '\\') + 1);
            }

            $rows[] = [
                $identity,
                $enabled
                    ? $this->printing->colorize(__('启用'), $this->printing::SUCCESS)
                    : $this->printing->colorize(__('禁用'), $this->printing::ERROR),
                $permanent ? __('是') : __('否'),
                $this->formatBytes($size),
                $adapter,
                (string)($pool['tip'] ?? ''),
            ];

            $stats['total']++;
            $stats['size'] += $size;
            if ($enabled) {
                $stats['enabled']++;
            } else {
                $stats['disabled']++;
            }
        }

        $this->printing->table($headers, $rows, ['padding' => 1, 'border' => true, 'maxWidth' => 120]);

        return $stats;
    }

    private function getPoolDirectorySize(string $identity): int
    {
        if ($identity === '') {
            return 0;
        }

        $cacheDir = BP . 'var' . DS . 'cache' . DS . $identity;
        if (!\is_dir($cacheDir)) {
            return 0;
        }

        return $this->getDirectorySize($cacheDir);
    }

    private function getDirectorySize(string $directory): int
    {
        $size = 0;
        if (\is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        }

        return $size;
    }

    /**
     * @param array{enabled:int,disabled:int,total:int,size:int} $stats1
     * @param array{enabled:int,disabled:int,total:int,size:int} $stats2
     * @return array{enabled:int,disabled:int,total:int,size:int}
     */
    private function mergeStats(array $stats1, array $stats2): array
    {
        return [
            'enabled' => $stats1['enabled'] + $stats2['enabled'],
            'disabled' => $stats1['disabled'] + $stats2['disabled'],
            'total' => $stats1['total'] + $stats2['total'],
            'size' => $stats1['size'] + $stats2['size'],
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = \max($bytes, 0);
        $pow = (int)\floor(($bytes ? \log($bytes) : 0) / \log(1024));
        $pow = (int)\min($pow, \count($units) - 1);

        $bytes /= (1024 ** $pow);

        return \round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('缓存状态。[enable/disable]:开启/关闭 [identify...]:缓存识别名');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}
