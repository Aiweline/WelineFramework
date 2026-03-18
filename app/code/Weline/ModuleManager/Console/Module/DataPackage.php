<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Console\Module;

use Weline\Framework\App\System;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Output\Cli\Printing;
use Weline\ModuleManager\Service\ModuleDataPackageService;

/**
 * 模块数据包 MDP：列出 / 手动生成 / 从包恢复（重装后灌数）。
 */
class DataPackage extends CommandAbstract
{
    public function __construct(
        Printing $printer,
        private readonly System $system,
        private readonly ModuleDataPackageService $dataPackageService
    ) {
        $this->printer = $printer;
    }

    public function execute(array $args = [], array $data = []): void
    {
        array_shift($args);
        $action = strtolower(trim((string) ($args[0] ?? '')));
        array_shift($args);

        match ($action) {
            'list', 'ls' => $this->doList($args),
            'create' => $this->doCreate($args),
            'restore' => $this->doRestore($args),
            default => throw new ConsoleException(
                __('用法：module:data-package list [模块名筛选] | create <模块名> | restore --path=<数据包目录> [--no-truncate] [--dry-run]')
            ),
        };
    }

    private function doList(array $args): void
    {
        $filter = trim((string) ($args[0] ?? ''));
        $items = $this->dataPackageService->listPackages($filter !== '' ? $filter : null);
        if ($items === []) {
            $this->printer->note(__('暂无 MDP，目录：%{1}', [$this->dataPackageService->getPackagesRoot()]));

            return;
        }
        $this->printer->success(__('模块数据包（新→旧）'));
        foreach ($items as $row) {
            $this->printer->note(sprintf(
                '  %s | %s | rows=%d | %s',
                $row['package_id'],
                $row['module_name'],
                $row['row_count'],
                $row['path']
            ));
        }
    }

    private function doCreate(array $args): void
    {
        $module = trim((string) ($args[0] ?? ''));
        if ($module === '') {
            throw new ConsoleException(__('请指定模块名，例如：module:data-package create Weline_Saas'));
        }
        $r = $this->dataPackageService->createPackage($module);
        if (!$r['success']) {
            $this->printer->error($r['message']);

            return;
        }
        $this->printer->success($r['message']);
        $this->printer->note(__('路径：%{1}', [$r['package_path'] ?? '']));
        $this->printer->note(__('表：%{1}，行数：%{2}', [(string) ($r['table_count'] ?? 0), (string) ($r['row_count'] ?? 0)]));
    }

    private function doRestore(array $args): void
    {
        $path = '';
        $truncate = true;
        $dryRun = false;
        foreach ($args as $i => $a) {
            if (str_starts_with($a, '--path=')) {
                $path = substr($a, 7);
            } elseif ($a === '--path' && isset($args[$i + 1])) {
                $path = $args[$i + 1];
            } elseif ($a === '--no-truncate') {
                $truncate = false;
            } elseif ($a === '--dry-run') {
                $dryRun = true;
            }
        }
        $path = trim($path);
        if ($path === '') {
            throw new ConsoleException(__('请指定 --path=数据包目录（含 manifest.json）'));
        }
        if (!is_file($path . DIRECTORY_SEPARATOR . 'manifest.json') && is_file($path)) {
            $path = dirname($path);
        }
        if ($dryRun) {
            $r = $this->dataPackageService->restoreFromPackage($path, $truncate, true, false);
            if ($r['success']) {
                $this->printer->success($r['message']);
            } else {
                $this->printer->error($r['message']);
            }

            return;
        }
        $this->printer->warning(__('恢复将向数据库写入数据；生产环境请先备份整库。'));
        $this->printer->note(__('路径：%{1}，先清空表：%{2}', [$path, $truncate ? __('是') : __('否')]));
        $this->printer->note(__('确认执行请输入 yes'));
        if (strtolower(trim($this->system->input())) !== 'yes') {
            $this->printer->warning(__('已取消'));

            return;
        }
        $r = $this->dataPackageService->restoreFromPackage($path, $truncate, false, true);
        if ($r['success']) {
            $this->printer->success($r['message']);
        } else {
            $this->printer->error($r['message']);
        }
    }

    public function tip(): string
    {
        return __('模块数据包 MDP：list / create / restore');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'module:data-package',
            $this->tip(),
            [
                'list [筛选]' => __('列出 var/module_data_packages 下的包'),
                'create <模块名>' => __('按 weline_module_table 登记为模块生成 MDP'),
                'restore --path=<目录> [--no-truncate] [--dry-run]' => __('从 MDP 导入；--dry-run 仅统计将恢复表/行数'),
            ],
            [],
            [
                'php bin/w module:data-package list',
                'php bin/w module:data-package create Weline_Demo',
                'php bin/w module:data-package restore --path=var/module_data_packages/Weline_Demo/mdp_xxx',
            ],
            'php bin/w module:data-package <list|create|restore> ...'
        );
    }
}
