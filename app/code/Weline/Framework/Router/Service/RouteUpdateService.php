<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Stage\RouteUpdateStage;

/**
 * 路由更新服务
 * 
 * 职责：专门负责路由的分析和注册，遵循单一职责原则
 * - 不依赖 register.php 中的路由注册逻辑
 * - 通过扫描控制器自动分析路由
 * - 支持指定模块更新
 * 
 * @package Weline\Framework\Router\Service
 */
class RouteUpdateService
{
    private Printing $printing;
    private Handle $moduleHandle;
    private RouteUpdateStage $routeStage;
    
    public function __construct(
        Printing $printing,
        Handle $moduleHandle
    ) {
        $this->printing = $printing;
        $this->moduleHandle = $moduleHandle;
    }
    
    /**
     * 更新指定模块的路由
     * 
     * @param array $moduleNames 模块名称数组，为空则更新所有模块
     * @return void
     * @throws Exception
     */
    public function updateRoutes(array $moduleNames = []): void
    {
        // 初始化路由更新阶段
        $this->initializeRouteStage($moduleNames);
        
        // 获取所有模块
        $modules = $this->moduleHandle->getModules();
        
        // 如果没有指定模块，更新所有模块
        if (empty($moduleNames)) {
            $this->printing->note(__('开始更新所有模块的路由...'));
        } else {
            $this->printing->note(__('开始更新指定模块的路由：%{1}', [implode(', ', $moduleNames)]));
        }
        
        // 遍历模块，分析并注册路由
        foreach ($modules as $moduleName => $module) {
            // 如果指定了模块列表，只处理指定的模块
            if (!empty($moduleNames) && !in_array($moduleName, $moduleNames, true)) {
                continue;
            }
            
            try {
                $this->analyzeAndRegisterModuleRoutes($moduleName, $module);
            } catch (Exception $exception) {
                $this->printing->error(__('模块 %{1} 路由注册失败：%{2}', [$moduleName, $exception->getMessage()]));
                $this->routeStage->rollback();
                throw new Exception(__('模块 %{1} 路由注册失败：%{2}', [$moduleName, $exception->getMessage()]), 0, $exception instanceof \Exception ? $exception : null);
            }
        }
        
        // 验证并提交路由更新
        $this->validateAndCommitRoutes();
    }
    
    /**
     * 初始化路由更新阶段
     * 
     * @param array $moduleNames 需要清除的模块列表
     * @return void
     */
    private function initializeRouteStage(array $moduleNames): void
    {
        /**@var \Weline\Framework\Router\Helper\Data $routerHelper */
        $routerHelper = ObjectManager::getInstance(\Weline\Framework\Router\Helper\Data::class);
        $this->routeStage = ObjectManager::make(RouteUpdateStage::class, ['routerHelper' => $routerHelper]);
        
        // 准备路由更新阶段
        $this->routeStage->prepare([
            'modules_to_clear' => $moduleNames
        ]);
    }
    
    /**
     * 分析并注册模块路由
     * 
     * 注意：这里只负责路由分析，不执行 register.php
     * register.php 应该只负责模块注册，路由分析应该独立进行
     * 
     * @param string $moduleName 模块名称
     * @param array $module 模块数据
     * @return void
     * @throws Exception
     */
    private function analyzeAndRegisterModuleRoutes(string $moduleName, array $module): void
    {
        if (DEV) {
            $this->printing->setup(__('%{1}：分析路由...', [$moduleName]), '开发');
        }
        
        // 直接调用 registerRoute，它会扫描控制器并分析路由
        // registerRoute 内部会调用 registerModuleRouter，后者会扫描控制器目录
        // 注意：这里不 require register.php，因为路由分析不依赖 register.php
        $moduleObj = new Module($module);
        $this->moduleHandle->registerRoute($moduleObj);
        
        if (DEV) {
            $this->printing->setup(__('%{1}：路由分析完成...', [$moduleName]), '开发');
        }
    }
    
    /**
     * 验证并提交路由更新
     * 
     * @return void
     * @throws Exception
     */
    private function validateAndCommitRoutes(): void
    {
        $this->printing->note(__('验证并提交路由更新...'));
        
        try {
            if (!$this->routeStage->validate()) {
                $status = $this->routeStage->getStatus();
                $errors = $status['errors'] ?? [];
                $errorMsg = !empty($errors) ? implode('; ', $errors) : __('验证失败');
                throw new Exception(__('路由更新验证失败：%{1}', [$errorMsg]));
            }
            
            $this->printing->note(__('   - 正在写入路由文件...'));
            $this->routeStage->commit();
            $this->printing->success(__('✓ 路由文件写入完成！'));
        } catch (Exception $exception) {
            $this->printing->error(__('路由文件写入失败：%{1}', [$exception->getMessage()]));
            $this->routeStage->rollback();
            throw new Exception(__('路由文件写入失败：%{1}', [$exception->getMessage()]), 0, $exception instanceof \Exception ? $exception : null);
        }
    }
}
