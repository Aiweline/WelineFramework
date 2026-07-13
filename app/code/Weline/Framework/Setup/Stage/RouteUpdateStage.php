<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Setup\Stage;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Router\Helper\Data as RouterHelper;
use Weline\Framework\System\File\Io\File;

/**
 * 路由更新阶段
 * 
 * 职责：管理路由的批量更新，确保所有路由在内存中收集完成后一次性写入文件
 * 
 * @package Weline\Framework\Setup\Stage
 */
class RouteUpdateStage extends AbstractStage
{
    /**
     * @var RouterHelper 路由助手
     */
    private RouterHelper $routerHelper;
    
    /**
     * @var array 路由文件路径列表
     */
    private array $routerFilePaths = [];
    
    /**
     * @var array 路由数据 [文件路径 => 路由数组]
     */
    private array $routeData = [];
    
    /**
     * @var array 原始路由数据备份 [文件路径 => 路由数组]
     */
    private array $originalRouteData = [];
    
    /**
     * @var array 需要清除的模块列表
     */
    private array $modulesToClear = [];
    
    /**
     * @param RouterHelper $routerHelper
     */
    public function __construct(RouterHelper $routerHelper)
    {
        $this->routerHelper = $routerHelper;
        $this->routerFilePaths = Env::router_files_PATH;
    }
    
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'route_update';
    }
    
    /**
     * @inheritDoc
     */
    public function prepare(array $context = []): void
    {
        // 如果已经准备过，跳过（避免重复准备导致路由数据被清空）
        if ($this->prepared) {
            return;
        }
        // 当 setup:upgrade --stage=xxx 指定了不含 route_update 的阶段时，不触碰路由，避免 enableBatchMode 清空缓冲后不 flush 导致路由丢失
        if (!empty($context['skip_route_stage'])) {
            $this->prepared = true;
            $this->clearErrors();
            return;
        }
        // 如果指定了需要清除的模块，记录它们
        if (isset($context['modules_to_clear']) && is_array($context['modules_to_clear'])) {
            $this->modulesToClear = $context['modules_to_clear'];
        }

        $isPartial = !empty($this->modulesToClear);

        if ($isPartial) {
            // 增量模式：确保不处于批量模式，避免仅写入内存不落盘
            if ($this->routerHelper->isBatchMode()) {
                // 将当前批量缓存（如果有）先落盘并退出批量模式
                $this->routerHelper->flushBatchRouters();
            }

            // 增量模式：不启用批量模式，避免覆盖整个路由文件
            // 先备份原始路由数据，便于出现异常时回滚
            $this->backupOriginalRoutes();

            // 直接在文件层面清理指定模块的旧路由
            foreach ($this->routerFilePaths as $path) {
                try {
                    $this->routerHelper->clearModuleRouters($path, $this->modulesToClear);
                } catch (\Exception $e) {
                    // 清理失败时记录错误，但不中断整个升级流程，由上层统一处理
                    $this->addError(__('清理模块 %{1} 路由失败：%{2}', [implode(', ', $this->modulesToClear), $e->getMessage()]));
                }
            }
        } else {
            // 备份原始路由数据（从文件读取）
            $this->backupOriginalRoutes();

            // 全量模式必须从空快照重建。即使同一进程已留有批量缓存，
            // 也不能继承它，否则已删除、改名或 area 变更的路由会继续残留。
            $this->routerHelper->enableBatchMode();

            // 预置所有路由文件为空快照，确保某个 area 已无路由时，
            // commit() 仍会用空数组覆盖旧文件，而不是保留陈旧内容。
            foreach ($this->routerFilePaths as $path) {
                $this->routerHelper->getBatchRouters($path);
            }
        }
        
        $this->prepared = true;
        $this->clearErrors();
    }
    
    /**
     * 备份原始路由数据
     * 
     * @return void
     */
    private function backupOriginalRoutes(): void
    {
        foreach ($this->routerFilePaths as $path) {
            if (is_file($path)) {
                $routers = require $path;
                if (is_array($routers)) {
                    $this->originalRouteData[$path] = $routers;
                } else {
                    $this->originalRouteData[$path] = [];
                }
            } else {
                $this->originalRouteData[$path] = [];
            }
        }
    }
    
    /**
     * 在内存中清除指定模块的路由
     * 
     * @return void
     */
    private function clearModuleRoutersInMemory(): void
    {
        foreach ($this->routerFilePaths as $path) {
            // 使用路由助手的清除方法（如果支持批量模式）
            // 否则直接操作批量路由缓存
            try {
                $this->routerHelper->clearModuleRouters($path, $this->modulesToClear);
            } catch (\Exception $e) {
                // 如果清除方法不支持批量模式，手动清除
                $routers = $this->routerHelper->getBatchRouters($path);
                
                // 清除指定模块的路由
                $cleared = false;
                foreach ($routers as $routerKey => $router) {
                    $routerModule = $this->extractModuleFromRouter($router);
                    if ($routerModule && in_array($routerModule, $this->modulesToClear, true)) {
                        unset($routers[$routerKey]);
                        $cleared = true;
                    }
                }
                
                if ($cleared) {
                    // 更新批量路由缓存
                    $reflection = new \ReflectionClass($this->routerHelper);
                    $property = $reflection->getProperty('batchRouters');
                    $property->setAccessible(true);
                    $batchRouters = $property->getValue($this->routerHelper);
                    $batchRouters[$path] = $routers;
                    $property->setValue($this->routerHelper, $batchRouters);
                }
            }
        }
    }
    
    /**
     * 从路由数据中提取模块名
     * 
     * @param mixed $router 路由数据
     * @return string|null
     */
    private function extractModuleFromRouter($router): ?string
    {
        if (!is_array($router)) {
            return null;
        }
        
        // 直接包含 module 键
        if (isset($router['module'])) {
            return $router['module'];
        }
        
        // 或者包含在 rule 键中
        if (isset($router['rule']) && is_array($router['rule']) && isset($router['rule']['module'])) {
            return $router['rule']['module'];
        }
        
        return null;
    }
    
    /**
     * @inheritDoc
     */
    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }
        
        // 验证路由数据格式
        // 这里可以添加更详细的验证逻辑
        
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        if (!$this->prepared) {
            throw new Exception(__('阶段 %{1} 尚未准备，无法提交', [$this->getName()]));
        }
        
        if ($this->committed) {
            // 已经提交过，跳过
            return;
        }
        
        try {
            $isPartial = !empty($this->modulesToClear);

            // 增量模式：路由在注册过程中已经按文件即时写入，这里不再做全量 flush
            if ($isPartial) {
                \Weline\Framework\Router\Core::snapshotGeneratedRouterFiles();
                $this->committed = true;
                $this->clearErrors();
                return;
            }

            // 全量模式：使用批量模式一次性写入所有路由文件
            // 检查批量模式是否启用
            if (!$this->routerHelper->isBatchMode()) {
                throw new Exception(__('批量模式未启用，无法提交路由更新'));
            }
            
            // 一次性写入所有路由文件
            $this->routerHelper->flushBatchRouters();
            \Weline\Framework\Router\Core::snapshotGeneratedRouterFiles();

            $this->committed = true;
            $this->clearErrors();
        } catch (\Exception $e) {
            $this->addError(__('路由文件写入失败：%{1}', [$e->getMessage()]));
            throw new Exception(__('路由文件写入失败：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * @inheritDoc
     */
    public function rollback(): void
    {
        if (!$this->prepared) {
            return;
        }
        // 若从未做过真实准备（如 skip_route_stage），无备份可恢复，直接重置状态避免误写空数据
        if ($this->originalRouteData === []) {
            $this->prepared = false;
            $this->committed = false;
            return;
        }
        // 恢复原始路由数据
        foreach ($this->originalRouteData as $path => $routers) {
            try {
                $file = new File();
                $file->open($path, $file::mode_w_add);
                $text = '<?php return ' . var_export($routers, true) . ';';
                $file->write($text);
                $file->close();
            } catch (\Exception $e) {
                // 回滚失败，记录错误但不抛出异常（避免回滚过程中的异常覆盖原始异常）
                $this->addError(__('回滚路由文件 %{1} 失败：%{2}', [$path, $e->getMessage()]));
            }
        }
        
        // 禁用批量模式
        if ($this->routerHelper->isBatchMode()) {
            // 通过反射清除批量路由数据
            try {
                $reflection = new \ReflectionClass($this->routerHelper);
                $property = $reflection->getProperty('batchRouters');
                $property->setAccessible(true);
                $property->setValue($this->routerHelper, []);
                
                $batchModeProperty = $reflection->getProperty('batchMode');
                $batchModeProperty->setAccessible(true);
                $batchModeProperty->setValue($this->routerHelper, false);
            } catch (\Exception $e) {
                // 忽略反射错误
            }
        }
        
        $this->prepared = false;
        $this->committed = false;
    }
}
