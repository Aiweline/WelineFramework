<?php

namespace Weline\Backend\Console\Menu;

use Weline\Backend\Service\MenuCollector;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Collect implements CommandInterface
{
    function __construct(
        private MenuCollector $menuCollector,
        private Printing $printing
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $moduleNames = $this->parseModuleArgs($args);

        // 1. 刷新模块列表（不调用 reload，避免 CLI 下配置重载影响 Scanner 路径解析）
        $this->printing->note(__('刷新模块列表...'));
        try {
            $env = \Weline\Framework\App\Env::getInstance();
            $env->getModuleList(true);
            $env->getActiveModules(true);
            $activeCount = count($env->getActiveModules());
            $this->printing->success(__('已刷新：%{1} 个激活模块', [$activeCount]));
        } catch (\Throwable $e) {
            $this->printing->warning(__('模块刷新失败：%{1}', [$e->getMessage()]));
        }

        // 2. 收集菜单（指定 -m 时仅收集指定模块）
        $this->printing->note(
            !empty($moduleNames)
                ? __('开始收集模块 %{1} 的菜单...', [implode(', ', $moduleNames)])
                : __('开始收集菜单...')
        );
        $diagnostics = $this->menuCollector->collectWithDiagnostics($moduleNames);
        $fileMenuCount = $diagnostics['file_menu_count'];
        $rawConfigCount = $diagnostics['raw_config_count'];

        if ($fileMenuCount === 0) {
            $msg = __('未从 menu.xml 解析到任何菜单。');
            if ($rawConfigCount === 0) {
                $msg .= __(' Scanner 未发现任何 menu.xml 文件，请执行：php bin/w setup:upgrade --route');
            } else {
                $msg .= __(' 发现 %{1} 个配置文件但解析失败，请检查 XML 格式。', [$rawConfigCount]);
            }
            $this->printing->error($msg);
        } else {
            $this->printing->success(__('菜单收集完成！共解析 %{1} 条菜单', [$fileMenuCount]));
        }
        
        // 清理事件缓存（菜单更新可能触发事件，需要清理事件缓存）
        $this->printing->note(__('清理事件缓存...'));
        try {
            /** @var \Weline\Framework\Event\Console\Event\Cache\Clear $eventCacheClear */
            $eventCacheClear = ObjectManager::getInstance(\Weline\Framework\Event\Console\Event\Cache\Clear::class);
            $eventCacheClear->execute();
        } catch (\Throwable $e) {
            $this->printing->warning(__('事件缓存清理失败：%{1}', [$e->getMessage()]));
        }
        
        // 编译插件/DI（确保依赖注入容器更新）
        $this->printing->note(__('编译插件/DI...'));
        try {
            /** @var \Weline\Framework\Plugin\Console\Plugin\Di\Compile $diCompile */
            $diCompile = ObjectManager::getInstance(\Weline\Framework\Plugin\Console\Plugin\Di\Compile::class);
            $diCompile->execute();
        } catch (\Throwable $e) {
            $this->printing->warning(__('DI编译失败：%{1}', [$e->getMessage()]));
        }
        
        // 再次清理缓存（菜单变更后立即生效）
        $this->printing->note(__('清理全部缓存...'));
        try {
            $cacheClear = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Cache\Clear::class);
            $cacheClear->execute(['-f']);
            $this->printing->success(__('全部缓存清理完成！'));
        } catch (\Throwable $e) {
            $this->printing->warning(__('缓存清理失败：%{1}', [$e->getMessage()]));
        }
        
        // 清理模板缓存（菜单变更会影响模板渲染）
        $this->printing->note(__('清理模板缓存...'));
        try {
            /** @var \Weline\Framework\Cache\Console\Template\Clear $templateCacheClear */
            $templateCacheClear = ObjectManager::getInstance(\Weline\Framework\Cache\Console\Template\Clear::class);
            $templateCacheClear->execute();
            $this->printing->success(__('模板缓存清理完成！'));
        } catch (\Throwable $e) {
            $this->printing->warning(__('模板缓存清理失败：%{1}', [$e->getMessage()]));
        }
        
        // 清理菜单相关缓存
        $this->printing->note(__('清理菜单相关缓存...'));
        try {
            // 清理菜单URL验证器缓存
            if (class_exists(\Weline\Admin\Helper\MenuUrlValidator::class)) {
                \Weline\Admin\Helper\MenuUrlValidator::clearCache();
            }
            
            $this->printing->success(__('菜单相关缓存已清理！'));
        } catch (\Throwable $e) {
            $this->printing->warning(__('菜单缓存清理失败：%{1}', [$e->getMessage()]));
        }
        
        $this->printing->success(__('菜单收集和系统更新完成！菜单已生效。'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '收集菜单';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'menu:collect',
            $this->tip(),
            [
                '-m, --module=<模块名>' => '仅收集指定模块的菜单（增量更新）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '指定 -m 时仅处理指定模块的 menu.xml，其他模块的菜单保持不变。',
            ],
            [
                '全量收集' => 'php bin/w menu:collect',
                '增量收集指定模块' => 'php bin/w menu:collect -m Weline_Admin',
            ],
            'php bin/w menu:collect [-m|--module=<模块名>]'
        );
    }

    /**
     * 解析模块参数（支持 -m、--module 及位置参数）
     */
    private function parseModuleArgs(array $args): array
    {
        $argsModule = $args['module'] ?? $args['m'] ?? [];
        if (is_string($argsModule)) {
            $argsModule = array_filter(array_map('trim', explode(' ', $argsModule)));
        }
        if (empty($argsModule)) {
            $positionalArgs = [];
            foreach ($args as $key => $value) {
                if (is_numeric($key) && is_string($value) && !str_starts_with($value, '-') && $key > 0) {
                    $positionalArgs[] = $value;
                }
            }
            if (!empty($positionalArgs)) {
                $argsModule = $positionalArgs;
            }
        }
        return array_values(array_filter($argsModule));
    }
}