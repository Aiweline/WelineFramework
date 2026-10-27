<?php

namespace Weline\Backend\Console\Menu;

use Weline\Backend\Config\MenuXmlReader;
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
        $force = isset($args['force']) || isset($args['f']);
        if ($force) {
            MenuXmlReader::requestForceFull('menu:collect -f');
        }

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
        $diff = $diagnostics['diff'] ?? [];

        if ($fileMenuCount === 0) {
            $msg = __('未从 menu.xml 解析到任何菜单。');
            if ($rawConfigCount === 0) {
                $msg .= __(' Scanner 未发现任何 menu.xml 文件，请执行：php bin/w setup:upgrade --route');
            } elseif (!$force) {
                $msg .= __(' 若源指纹全命中会跳过解析（DB 已有菜单时）。请执行：php bin/w menu:collect -f');
            } else {
                $msg .= __(' 发现 %{1} 个配置文件但解析结果为空，请检查 XML 格式。', [$rawConfigCount]);
            }
            $this->printing->error($msg);
        } else {
            $this->printing->success(__('菜单收集完成！共解析 %{1} 条菜单（来自 %{2} 个 menu.xml 文件）', [$fileMenuCount, $rawConfigCount]));
        }
        
        // 输出 diff 结果
        if (!empty($diff)) {
            $added = $diff['to_add'] ?? [];
            $updated = $diff['to_update'] ?? [];
            $deleted = $diff['to_delete'] ?? [];
            $disabled = $diff['to_disable'] ?? [];
            
            if (!empty($added)) {
                $this->printing->success(__('  新增 %{1} 条菜单：', [count($added)]));
                foreach ($added as $source) {
                    $this->printing->note('    + ' . $source);
                }
            }
            if (!empty($updated)) {
                $this->printing->note(__('  更新 %{1} 条菜单：', [count($updated)]));
                foreach (array_keys($updated) as $source) {
                    $this->printing->note('    ~ ' . $source);
                }
            }
            if (!empty($deleted)) {
                $this->printing->warning(__('  删除 %{1} 条菜单（模块已移除）：', [count($deleted)]));
                foreach ($deleted as $source) {
                    $this->printing->warning('    - ' . $source);
                }
            }
            if (!empty($disabled)) {
                $this->printing->note(__('  禁用 %{1} 条菜单（模块已禁用）：', [count($disabled)]));
                foreach ($disabled as $source) {
                    $this->printing->note('    ○ ' . $source);
                }
            }
            if (empty($added) && empty($updated) && empty($deleted) && empty($disabled)) {
                $this->printing->success(__('  菜单无变化。'));
            }
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
        
        // 菜单变更的失效边界只到菜单 / ACL / 后台菜单树 / 运行时页面缓存：
        // MenuCollector 已按 diff 定点失效（AclCacheInvalidator、菜单树与后台全页缓存），
        // 这里不再做全量 cache:clear、也不再清模板缓存与 DI 重编译——
        // 那会把整个店面缓存池和店面预热（清缓存后同步 warm）卷进一次菜单收集，
        // 单次平白多出数分钟，且与「菜单」无关；店面预热由定时任务按构建世代触发。
        $this->printing->note(__('菜单相关缓存已按 diff 定点失效（不做全量清缓存、不做预热）。'));
        
        $this->printing->success(__('菜单收集和系统更新完成！菜单已生效。'));

        // 重建后台菜单交叉搜索索引（离线全语 keywords，避免渲染热路径烘焙）
        if (class_exists(\Weline\Admin\Service\BackendMenuSearchIndexRebuilder::class)) {
            $this->printing->note(__('重建后台菜单搜索索引...'));
            try {
                \Weline\Admin\Service\BackendMenuSearchIndexRebuilder::rebuild(0);
                $this->printing->success(__('后台菜单搜索索引已交给变更队列追平'));
            } catch (\Throwable $e) {
                $this->printing->warning(__('后台菜单搜索索引重建失败：%{1}', [$e->getMessage()]));
            }
        }
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
                '-f, --force' => '强制全量重解析 menu.xml（清除 menu:* 源指纹，忽略跳过）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '指定 -m 时仅处理指定模块的 menu.xml，其他模块的菜单保持不变。',
                '源指纹命中且 ACL 菜单非空时默认跳过解析；改 menu.xml 后指纹会变；若仍跳过请用 -f。',
            ],
            [
                '全量收集' => 'php bin/w menu:collect',
                '强制全量收集' => 'php bin/w menu:collect -f',
                '增量收集指定模块' => 'php bin/w menu:collect -m Weline_Backend',
            ],
            'php bin/w menu:collect [-m|--module=<模块名>] [-f|--force]'
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
