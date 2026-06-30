<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Hook\Console\Hook;

use Weline\Framework\Console\CommandAbstract;
use Weline\Hook\HookRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryProgress;

class Rebuild extends CommandAbstract
{
    /**
     * 重新扫描并生成 generated/hooks.php
     */
    public function execute(array $args = [], array $data = [])
    {
        // 确保 printer 已初始化
        if (!isset($this->printer)) {
            $this->__init();
        }

        $moduleNames = $this->parseModuleArgs($args);
        
        try {
            $this->printer->setup(
                !empty($moduleNames)
                    ? __('开始增量重建模块 %{1} 的钩子注册表...', [implode(', ', $moduleNames)])
                    : __('开始重建钩子注册表...')
            );

            RegistryProgress::enable(true);
            RegistryProgress::section('Hook rebuild command');

            /** @var HookRegistry $registry */
            $registry = ObjectManager::getInstance(HookRegistry::class);

            // 允许 solo 冲突，以便能够完成重建（冲突会在开发环境下显示警告）
            $ok = !empty($moduleNames)
                ? $registry->refreshForModules($moduleNames, true)
                : $registry->refresh(true);
            if ($ok) {
                $this->printer->success(__('✓ 钩子注册表已重建完成。'));
                $this->printer->note(__('位置：generated/hooks.php'));
                
                // 显示统计信息
                $hooks = $registry->getHooks();
                
                // 统计实现文件信息
                $totalImplementations = 0;
                $hooksWithImplementations = 0;
                foreach ($hooks as $hookName => $hookInfo) {
                    $implCount = count($hookInfo['implementations'] ?? []);
                    if ($implCount > 0) {
                        $hooksWithImplementations++;
                        $totalImplementations += $implCount;
                    }
                }
                
                $totalHooks = count($hooks);
                $hooksWithSpec = 0;
                $hooksWithDoc = 0;
                
                foreach ($hooks as $hookName => $hookInfo) {
                    if ($hookInfo['has_spec'] ?? false) {
                        $hooksWithSpec++;
                    }
                    if ($hookInfo['has_doc'] ?? false) {
                        $hooksWithDoc++;
                    }
                }
                
                $this->printer->note(__('统计信息：'));
                $this->printer->note(__('  总钩子数：%{1}', [$totalHooks]));
                $this->printer->note(__('  有规约的钩子：%{1}', [$hooksWithSpec]));
                $this->printer->note(__('  有文档的钩子：%{1}', [$hooksWithDoc]));
                $this->printer->note(__('  有实现文件的钩子：%{1}', [$hooksWithImplementations]));
                $this->printer->note(__('  总实现文件数：%{1}', [$totalImplementations]));
                
                // 检查是否有钩子缺少文档
                $hooksWithoutDoc = [];
                foreach ($hooks as $hookName => $hookInfo) {
                    if (empty($hookInfo['has_doc']) || !$hookInfo['has_doc']) {
                        $hooksWithoutDoc[] = [
                            'name' => $hookName,
                            'module' => $hookInfo['module'] ?? '',
                            'display_name' => $hookInfo['name'] ?? $hookName,
                            'doc' => $hookInfo['doc'] ?? '',
                        ];
                    }
                }
                
                if (!empty($hooksWithoutDoc)) {
                    $this->printer->error(__('✖ 发现 %{1} 个钩子缺少文档，重建中断！', [count($hooksWithoutDoc)]));
                    $this->printer->error('');
                    $this->printer->error(__('缺少文档的钩子列表：'));
                    $this->printer->error('');
                    
                    foreach ($hooksWithoutDoc as $hook) {
                        $this->printer->error(__('  ❌ 钩子名称：%{1}', [$hook['name']]));
                        $this->printer->error(__('     显示名称：%{1}', [$hook['display_name']]));
                        $this->printer->error(__('     所属模块：%{1}', [$hook['module']]));
                        if (!empty($hook['doc'])) {
                            $this->printer->error(__('     期望文档路径：%{1}/doc/hook/%{2}', [$hook['module'], $hook['doc']]));
                        } else {
                            $this->printer->error(__('     问题：hook.php 中未配置 doc 字段'));
                        }
                        $this->printer->error('');
                    }
                    
                    $this->printer->error(__('所有钩子都必须有文档才能通过验证。'));
                    $this->printer->error(__('请在对应模块的 doc/hook/ 目录下创建相应的文档文件。'));
                    
                    // 直接退出，不抛出异常（避免被catch捕获）
                    exit(1);
                }
            } else {
                $this->printer->error(__('✖ 写入钩子注册表失败。'));
                exit(1);
            }
        } catch (\Throwable $e) {
            $this->printer->error(__('重建失败：%{1}', [$e->getMessage()]));
            if (DEV) {
                $this->printer->error($e->getTraceAsString());
            }
            exit(1);
        }
    }

    public function tip(): string
    {
        return '扫描所有模块钩子规约并重建 generated/hooks.php';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'hook:rebuild',
            '扫描所有模块的 hook.php 规约文件并重建钩子注册表',
            [
                '-m, --module=<模块名>' => '仅重建指定模块的钩子（增量更新）',
                '--debug' => '显示调试信息（可选）',
            ],
            [
                '执行后会在项目根目录的 generated/hooks.php 写入钩子规约信息。',
                '指定 -m 时为增量更新，仅刷新指定模块相关的 Hook 规约和实现。',
                '所有钩子必须同时具备规约文件 (hook.php) 和 HookInterface 常量定义才能正常使用。',
            ],
            [
                '全量重建' => 'php bin/w hook:rebuild',
                '增量重建指定模块' => 'php bin/w hook:rebuild -m Weline_Backend',
            ],
            'php bin/w hook:rebuild [-m|--module=<模块名>]'
        );
    }
}
