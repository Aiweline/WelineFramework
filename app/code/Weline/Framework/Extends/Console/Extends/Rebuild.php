<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Console\Extends;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Extends\ExtendsRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryProgress;

class Rebuild extends CommandAbstract
{
    /**
     * 重新扫描并生成 generated/extends.php
     */
    public function execute(array $args = [], array $data = [])
    {
        $moduleNames = $this->parseModuleArgs($args);

        try {
            $this->printer->setup(
                !empty($moduleNames)
                    ? __('开始增量重建模块 %{1} 的扩展注册表...', [implode(', ', $moduleNames)])
                    : __('开始重建扩展注册表...')
            );

            RegistryProgress::enable(true);
            RegistryProgress::section('Extends rebuild command');

            /** @var ExtendsRegistry $registry */
            $registry = ObjectManager::getInstance(ExtendsRegistry::class);

            $ok = !empty($moduleNames)
                ? $registry->refreshForModules($moduleNames)
                : $registry->refresh();
            if ($ok) {
                $this->printer->success(__('✓ 扩展注册表已重建完成。'));
                $this->printer->note(__('位置：generated/extends.php'));
            } else {
                $this->printer->error(__('✖ 写入扩展注册表失败。'));
            }
        } catch (\Throwable $e) {
            $this->printer->error(__('重建失败：%{1}', [$e->getMessage()]));
            if (DEV) {
                $this->printer->error($e->getTraceAsString());
            }
        }
    }

    public function tip(): string
    {
        return '扫描所有模块扩展并重建 generated/extends.php';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'extends:rebuild',
            '扫描所有模块的 extends 并重建注册表',
            [
                '-m, --module=<模块名>' => '仅重建指定模块的扩展（增量更新）',
                '--debug' => '显示调试信息（可选）',
            ],
            [
                '执行后会在项目根目录的 generated/extends.php 写入扩展信息。',
                '指定 -m 时为增量更新，仅刷新指定模块的 extends 规约和扩展实现。',
            ],
            [
                '全量重建' => 'php bin/w extends:rebuild',
                '增量重建指定模块' => 'php bin/w extends:rebuild -m Weline_Admin',
            ],
            'php bin/w extends:rebuild [-m|--module=<模块名>]'
        );
    }
}


