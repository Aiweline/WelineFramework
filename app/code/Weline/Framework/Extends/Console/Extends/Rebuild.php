<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Console\Extends;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Extends\ExtendsRegistry;
use Weline\Framework\Manager\ObjectManager;

class Rebuild extends CommandAbstract
{
    /**
     * 重新扫描并生成 generated/extends.php
     */
    public function execute(array $args = [], array $data = [])
    {
        try {
            $this->printer->setup(__('开始重建扩展注册表...'));

            /** @var ExtendsRegistry $registry */
            $registry = ObjectManager::getInstance(ExtendsRegistry::class);

            $ok = $registry->refresh();
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
                '--debug' => '显示调试信息（可选）',
            ],
            [
                '执行后会在项目根目录的 generated/extends.php 写入全量扩展信息。',
            ],
            [
                '直接重建' => 'php bin/w extends:rebuild',
            ],
            'php bin/w extends:rebuild'
        );
    }
}


