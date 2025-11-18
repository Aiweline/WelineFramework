<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Console\Plugin\Status;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;

class Set implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 获取操作参数
        $operation = null;
        $status = null;
        
        // 从参数中获取操作
        foreach ($args as $key => $value) {
            if (is_numeric($key)) {
                if (in_array($value, ['enable', 'disable', '0', '1'])) {
                    $operation = $value;
                    break;
                }
            }
        }

        // 验证参数
        if ($operation === null) {
            $this->printing->error(__('请指定操作：enable/disable 或 0/1'));
            $this->printing->note(__('用法示例：'));
            $this->printing->note(__('  php bin/w plugin:status:set enable                    # 启用插件缓存'));
            $this->printing->note(__('  php bin/w plugin:status:set disable                   # 关闭插件缓存'));
            $this->printing->note(__('  php bin/w plugin:status:set 1                         # 启用插件缓存'));
            $this->printing->note(__('  php bin/w plugin:status:set 0                         # 关闭插件缓存'));
            return;
        }

        // 转换操作到状态值
        if (in_array($operation, ['enable', '1'])) {
            $status = 1;
        } elseif (in_array($operation, ['disable', '0'])) {
            $status = 0;
        }

        // 设置插件缓存状态
        $this->setPluginCacheStatus($status);
    }

    /**
     * 设置插件缓存状态
     *
     * @param int $status 状态值 (0 或 1)
     */
    private function setPluginCacheStatus(int $status): void
    {
        $env = Env::getInstance();
        $cacheConfig = $env->getData('cache');
        $cacheStatus = $cacheConfig['status'] ?? [];

        // 设置插件相关缓存状态
        $pluginCaches = ['framework_plugin', 'plugin_cache'];
        $successCount = 0;

        foreach ($pluginCaches as $cacheName) {
            if (isset($cacheStatus[$cacheName])) {
                $cacheStatus[$cacheName] = $status;
                $successCount++;
            }
        }

        // 更新配置
        $cacheConfig['status'] = $cacheStatus;
        $env->setConfig('cache', $cacheConfig);

        // 显示结果
        $statusText = $status ? __('启用') : __('关闭');
        $this->printing->success("✅ 成功 {$statusText} 插件缓存");

        // 显示当前插件缓存状态
        $this->printing->separator('─', 50, $this->printing::NOTE);
        $this->printing->note(__('当前插件缓存状态：'));
        $this->showPluginCacheStatus($cacheStatus);
    }

    /**
     * 显示插件缓存状态
     *
     * @param array $cacheStatus 缓存状态数组
     */
    private function showPluginCacheStatus(array $cacheStatus): void
    {
        $pluginCaches = ['framework_plugin', 'plugin_cache'];
        $headers = [__('插件缓存'), __('状态')];
        $rows = [];

        foreach ($pluginCaches as $cacheName) {
            if (isset($cacheStatus[$cacheName])) {
                $status = $cacheStatus[$cacheName];
                $statusText = $status ? 
                    $this->printing->colorize(__('启用'), $this->printing::SUCCESS) : 
                    $this->printing->colorize(__('关闭'), $this->printing::ERROR);
                
                $rows[] = [$cacheName, $statusText];
            }
        }

        if (!empty($rows)) {
            $this->printing->table($headers, $rows, ['padding' => 1, 'border' => true]);
        } else {
            $this->printing->warning(__('没有找到插件缓存配置'));
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('插件缓存状态设置：enable/disable 或 0/1');
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
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
