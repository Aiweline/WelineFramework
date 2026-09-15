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
     * 原始路由文件备份路径（磁盘临时副本，避免把整表路由再拷进内存）。
     * @var array<string, string> livePath => backupTempPath
     */
    private array $originalRouteBackupFiles = [];
    
    /**
     * @var array 需要清除的模块列表
     */
    private array $modulesToClear = [];

    /** 指纹全员命中：不写路由文件、不 flush batch */
    private bool $skipWrite = false;
    
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
            $this->committed = true;
            $this->clearErrors();
            return;
        }
        // 如果指定了需要清除的模块，记录它们
        if (isset($context['modules_to_clear']) && is_array($context['modules_to_clear'])) {
            $this->modulesToClear = $context['modules_to_clear'];
        }

        $seedPartial = !empty($context['seed_partial']) && !empty($this->modulesToClear);
        $isPartial = !empty($this->modulesToClear) && !$seedPartial;

        // 路由文件批量缓冲 + ACL 事件 defer：扫描阶段只做内存收集，commit 时一次落盘/落库
        /** @var \Weline\Framework\Module\Helper\Data $moduleHelper */
        $moduleHelper = \Weline\Framework\Manager\ObjectManager::getInstance(
            \Weline\Framework\Module\Helper\Data::class
        );
        $moduleHelper->enableDeferControllerAttributes();
        \Weline\Framework\Module\Handle::resetBatchRouteProgress();

        if ($seedPartial) {
            // 指纹部分命中：batch + 磁盘种子 + 内存清除变更模块，禁止空 batch 跳扫（P0）
            $this->backupOriginalRoutes();
            $this->routerHelper->enableBatchMode();
            $this->routerHelper->seedBatchFromDisk();
            $this->clearModuleRoutersInMemory();
            // commit 走全量 flush；modulesToClear 清空以免误判增量
            $this->modulesToClear = [];
        } elseif ($isPartial) {
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
                    throw new Exception(__('清理模块 %{1} 路由失败：%{2}', [
                        implode(', ', $this->modulesToClear),
                        $e->getMessage(),
                    ]), 0, $e);
                }
            }
        } else {
            // 全量模式：磁盘备份 + seed 现有路由，再由收集覆盖变更模块。
            $this->backupOriginalRoutes();
            $this->routerHelper->enableBatchMode();
            $this->routerHelper->seedBatchFromDisk();
        }
        
        $this->prepared = true;
        $this->clearErrors();
    }
    
    /**
     * 将现有路由文件拷到临时目录（不 require 进内存）。
     */
    private function backupOriginalRoutes(): void
    {
        $this->discardOriginalRouteBackups();
        $dir = \rtrim(\sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'weline_route_bak_'
            . \getmypid();
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new Exception(__('无法创建路由回滚临时目录：%{1}', [$dir]));
        }

        foreach ($this->routerFilePaths as $path) {
            if (!\is_file($path)) {
                continue;
            }
            $bak = $dir . DIRECTORY_SEPARATOR . \md5($path) . '.php';
            if (!@\copy($path, $bak)) {
                throw new Exception(__('备份路由文件失败：%{1}', [$path]));
            }
            $this->originalRouteBackupFiles[$path] = $bak;
        }
    }

    private function discardOriginalRouteBackups(): void
    {
        $dirs = [];
        foreach ($this->originalRouteBackupFiles as $bak) {
            if (\is_string($bak) && $bak !== '' && \is_file($bak)) {
                $dirs[\dirname($bak)] = true;
                @\unlink($bak);
            }
        }
        $this->originalRouteBackupFiles = [];
        foreach (\array_keys($dirs) as $dir) {
            if (\is_dir($dir)) {
                @\rmdir($dir);
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

        if ($this->skipWrite) {
            $this->committed = true;
            $this->clearErrors();
            return;
        }

        $printing = null;
        if (\PHP_SAPI === 'cli') {
            try {
                $printing = \Weline\Framework\Manager\ObjectManager::getInstance(
                    \Weline\Framework\Output\Cli\Printing::class
                );
            } catch (\Throwable) {
                $printing = null;
            }
        }
        $note = static function (string $message) use ($printing): void {
            if ($printing === null) {
                return;
            }
            $printing->note($message);
            if (\defined('STDOUT') && \is_resource(\STDOUT)) {
                \fflush(\STDOUT);
            }
        };
        
        try {
            $isPartial = !empty($this->modulesToClear);

            /** @var \Weline\Framework\Module\Helper\Data $moduleHelper */
            $moduleHelper = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Module\Helper\Data::class
            );
            // 先落 ACL（扫描期已 defer），再写路由文件
            $note(__('   - route_update：正在批量写入控制器 ACL（可能较慢）…'));
            $moduleHelper->flushDeferredControllerAttributes();
            // ACL 事件与观察者工作集已卸；diff 若已跑过，registry 也可卸。
            if (\class_exists(\Weline\Acl\Service\CollectedAclSourceIdsRegistry::class)) {
                \Weline\Acl\Service\CollectedAclSourceIdsRegistry::clear();
            }
            if (\class_exists(\Weline\Acl\Service\Resource\LiveSourceSet::class)) {
                \Weline\Acl\Service\Resource\LiveSourceSet::clear();
            }
            $note(__('   - route_update：ACL 写入完成'));

            // 增量模式：路由在注册过程中已经按文件即时写入，这里不再做全量 flush
            if ($isPartial) {
                $note(__('   - route_update：增量模式，刷新路由快照…'));
                \Weline\Framework\Router\Core::snapshotGeneratedRouterFiles();
                $this->committed = true;
                $this->clearErrors();
                $this->discardOriginalRouteBackups();
                $this->modulesToClear = [];
                $this->routeData = [];
                if (\function_exists('gc_collect_cycles')) {
                    \gc_collect_cycles();
                }
                $note(__('   - route_update：提交完成（增量）'));
                return;
            }

            // 全量模式：使用批量模式一次性写入所有路由文件
            // 检查批量模式是否启用
            if (!$this->routerHelper->isBatchMode()) {
                throw new Exception(__('批量模式未启用，无法提交路由更新'));
            }
            
            // 一次性写入所有路由文件
            $note(__('   - route_update：正在落盘路由文件…'));
            $this->routerHelper->flushBatchRouters();
            $note(__('   - route_update：路由文件已写入，正在生成运行时快照…'));
            \Weline\Framework\Router\Core::snapshotGeneratedRouterFiles();

            $this->committed = true;
            $this->clearErrors();
            // 提交成功后备份不再需要，立刻卸掉临时文件。
            $this->discardOriginalRouteBackups();
            $this->modulesToClear = [];
            $this->routeData = [];
            if (\function_exists('gc_collect_cycles')) {
                \gc_collect_cycles();
            }
            $note(__('   - route_update：提交完成'));
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
        if ($this->originalRouteBackupFiles === []) {
            $this->prepared = false;
            $this->committed = false;
            return;
        }
        // 从临时文件拷回，避免再把整表路由 load 进 PHP 数组。
        foreach ($this->originalRouteBackupFiles as $path => $bak) {
            try {
                if (!\is_string($bak) || $bak === '' || !\is_file($bak)) {
                    continue;
                }
                $dir = \dirname($path);
                if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                    throw new \RuntimeException('mkdir failed: ' . $dir);
                }
                if (!@\copy($bak, $path)) {
                    throw new \RuntimeException('copy failed');
                }
            } catch (\Exception $e) {
                $this->addError(__('回滚路由文件 %{1} 失败：%{2}', [$path, $e->getMessage()]));
            }
        }
        $this->discardOriginalRouteBackups();
        
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

        try {
            /** @var \Weline\Framework\Module\Helper\Data $moduleHelper */
            $moduleHelper = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Module\Helper\Data::class
            );
            $moduleHelper->clearDeferredControllerAttributes();
        } catch (\Throwable) {
        }
        \Weline\Framework\Module\Handle::resetBatchRouteProgress();
        
        $this->prepared = false;
        $this->committed = false;
    }
}
