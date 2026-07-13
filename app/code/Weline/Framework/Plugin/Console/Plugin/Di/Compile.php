<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Console\Plugin\Di;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Plugin\Console\Plugin\Cache\Clear;
use Weline\Framework\Plugin\PluginRegistry;
use Weline\Framework\Plugin\PluginsManager;
use Weline\Framework\Console\ParseModuleArgsTrait;

class Compile implements \Weline\Framework\Console\CommandInterface
{
    use ParseModuleArgsTrait;
    /**
     * @var PluginsManager
     */
    private PluginsManager $pluginsManager;

    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * @var System
     */
    private System $system;

    public function __construct(
        PluginsManager $pluginsManager,
        System         $system,
        Printing       $printing
    )
    {
        $this->pluginsManager = $pluginsManager;
        $this->printing       = $printing;
        $this->system         = $system;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $moduleNames = $this->parseModuleArgs($args);

        if (!empty($moduleNames)) {
            // 增量编译：不删除 generated/code，只刷新指定模块
            $this->printing->printing(__('增量编译模块：%{1}...', [implode(', ', $moduleNames)]));
            /** @var PluginRegistry $pluginRegistry */
            $pluginRegistry = ObjectManager::getInstance(PluginRegistry::class);
            $pluginRegistry->refreshForModules($moduleNames);
            $this->pluginsManager->compileForModules($moduleNames);
            $this->printing->printing(__('增量编译结束...'));
            return;
        }

        // 全量编译
        $this->printing->printing(__('编译开始...'));
        $this->printing->printing(__('清除旧编译内容...'));
        if (!is_dir(Env::path_framework_generated_code)) {
            mkdir(Env::path_framework_generated_code, 755, true);
        } else {
            $this->printing->printing('编译目录扫描...', '系统');
            $files = scandir(Env::path_framework_generated_code);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    $real_file = Env::path_framework_generated_code . DS . $file;
                    if (is_dir($real_file)) {
                        $this->system->exec('rm -rf ' . $real_file);
                    }
                }
            }
        }
        $this->printing->printing(__('清除编译缓存...'));
        /** @var Clear $clear */
        $clear = ObjectManager::getInstance(Clear::class);
        $clear->execute();
        $this->pluginsManager->scanPlugins(false);
        $this->pluginsManager->generatorInterceptor('', false);
        $printer_list = [];
        foreach (\Weline\Framework\Plugin\Proxy\Generator::getClassProxyMap() as $key => $item) {
            unset($item['body']);
            $printer_list[$key] = $item;
        }
        $this->printing->printList($printer_list);
        $this->printing->printing(__('编译结束...'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '【插件】系统依赖编译';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'plugin:di:compile',
            $this->tip(),
            [
                '-m, --module=<模块名>' => '仅编译指定模块的插件（增量编译，不清除 generated/code）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '指定 -m 时为增量编译，仅刷新并编译指定模块的插件拦截器。',
            ],
            [
                '全量编译' => 'php bin/w plugin:di:compile',
                '增量编译指定模块' => 'php bin/w plugin:di:compile -m Vendor_Module',
            ],
            'php bin/w plugin:di:compile [-m|--module=<模块名>]'
        );
    }
}
