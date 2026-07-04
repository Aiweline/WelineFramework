<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Console\Widget;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\ParamSchemaRegistry;
use Weline\Widget\Service\WidgetRegistry;

/**
 * 部件注册表刷新命令
 * 重新扫描所有部件并更新 generated/widgets.php
 */
class Refresh extends CommandAbstract
{
    /**
     * 执行命令
     *
     * @param array $args 参数数组
     * @param array $data 数据数组
     * @return void
     */
    public function execute(array $args = [], array $data = []): void
    {
        try {
            $this->printer->setup(__('开始刷新部件注册表...'));

            /** @var WidgetRegistry $registry */
            $registry = ObjectManager::getInstance(WidgetRegistry::class);

            $ok = $registry->refresh();
            if ($ok) {
                $this->printer->success(__('✓ 部件注册表已刷新完成。'));
                $this->printer->note(__('位置：generated/widgets.php'));
            } else {
                $this->printer->error(__('✖ 写入部件注册表失败。'));
            }

            /** @var ParamSchemaRegistry $paramSchemaRegistry */
            $paramSchemaRegistry = ObjectManager::getInstance(ParamSchemaRegistry::class);
            $schemaOk = $paramSchemaRegistry->refresh();
            if ($schemaOk) {
                $this->printer->success(__('✓ ParamSchema 注册表已刷新完成。'));
                $this->printer->note(__('位置：generated/param_schemas.php'));
            } else {
                $this->printer->error(__('✖ 写入 ParamSchema 注册表失败。'));
            }

            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = [
                'source' => 'widget_refresh_command',
            ];
            $eventsManager->dispatch('Weline_Widget::registry_refresh_after', $eventData);
        } catch (\Throwable $e) {
            $this->printer->error(__('刷新失败：%{1}', [$e->getMessage()]));
            if (DEV) {
                $this->printer->error($e->getTraceAsString());
            }
        }
    }

    /**
     * 命令提示
     *
     * @return string
     */
    public function tip(): string
    {
        return '扫描所有模块的部件并刷新 generated/widgets.php';
    }

    /**
     * 帮助信息
     *
     * @return array|string
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'widget:refresh',
            '扫描所有模块的部件并刷新注册表',
            [
                '--debug' => '显示调试信息（可选）',
            ],
            [
                '执行后会在项目根目录的 generated/widgets.php 写入全量部件信息。',
                '建议在添加新部件后运行此命令。',
            ],
            [
                '直接刷新' => 'php bin/w widget:refresh',
            ],
            'php bin/w widget:refresh'
        );
    }
}
