<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleRouter\Observer;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;
use Weline\Framework\Router\RouterInterface;
use Weline\ModuleRouter\Cache\ModuleRouterCache;
use Weline\ModuleRouter\Config\ModuleRouterReader;

class ProcessUrlBefore implements \Weline\Framework\Event\ObserverInterface
{
    private CacheInterface $moduleRouterCache;
    
    /**
     * 静态缓存模块路由列表，避免每次事件分发都重新读取
     * 
     * @var array|null
     */
    private static ?array $cachedModuleRouters = null;
    
    /**
     * 静态缓存ModuleRouterCache实例，避免重复创建
     * 
     * @var CacheInterface|null
     */
    private static ?CacheInterface $staticCacheInstance = null;

    public function __construct(
        ModuleRouterCache $moduleRouterCache,
        private Request   $request
    )
    {
        $this->moduleRouterCache = $moduleRouterCache->create();
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        /**@var DataObject $data */
        $data = $event->getData('data');
        $path = $data->getData('path');
        $rule = $data->getData('rule');
        if ($rule instanceof DataObject) {
            $rule = $rule->getData();
        }
        $type = $data->getData('type');
        
        // 使用静态缓存，避免每次事件分发都重新读取模块路由
        if (self::$cachedModuleRouters === null) {
            
            // 优化：直接从缓存读取，避免实例化ModuleRouterReader
            if (self::$staticCacheInstance === null) {
                self::$staticCacheInstance = $this->moduleRouterCache;
            }
            $cache_key = 'routers_rules_cache';
            // #region agent log - 强制清除缓存以重新扫描
            // 临时清除缓存以确保重新扫描路由器
            self::$staticCacheInstance->delete($cache_key);
            // #endregion
            $router_rules = self::$staticCacheInstance->get($cache_key);
            if ($router_rules !== false && is_array($router_rules) && !empty($router_rules)) {
                // 缓存命中，直接使用
                self::$cachedModuleRouters = $router_rules;
            } else {
                // 缓存未命中，回退到使用ModuleRouterReader
                /**@var ModuleRouterReader $moduleRoutersReader */
                $moduleRoutersReader = ObjectManager::getInstance(ModuleRouterReader::class);
                self::$cachedModuleRouters = $moduleRoutersReader->read();
            }
        } else {
            
        }
        $moduleRouters = self::$cachedModuleRouters;
        $moduleCount = 0;
        
        // #region agent log
        file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'A','location'=>'ProcessUrlBefore.php:85','message'=>'ProcessUrlBefore execute','data'=>['path'=>$path,'moduleRoutersCount'=>count($moduleRouters),'moduleRouters'=>array_keys($moduleRouters)],'timestamp'=>time()*1000])."\n", FILE_APPEND);
        // #endregion
        
        foreach ($moduleRouters as $module => $moduleRouter) {
            $routerClass = $moduleRouter['class'];
            
            // #region agent log
            file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'A','location'=>'ProcessUrlBefore.php:92','message'=>'checking router class','data'=>['module'=>$module,'routerClass'=>$routerClass,'classExists'=>class_exists($routerClass),'isSubclass'=>class_exists($routerClass)?is_subclass_of($routerClass, RouterInterface::class):false],'timestamp'=>time()*1000])."\n", FILE_APPEND);
            // #endregion
            
            // RouterInterface::process() 是静态方法，直接使用类名调用，无需实例化
            // 这样可以支持静态类（如 Weline\BackendThemeUpzet\Controller\Router）
            if (class_exists($routerClass) && is_subclass_of($routerClass, RouterInterface::class)) {
                // #region agent log
                file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'A','location'=>'ProcessUrlBefore.php:98','message'=>'calling router process','data'=>['module'=>$module,'routerClass'=>$routerClass,'path'=>$path],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
                $routerClass::process($path, $rule);
                $moduleCount++;
                
                // #region agent log
                file_put_contents('e:\WelineFramework\DEV-workspace\.cursor\debug.log', json_encode(['sessionId'=>'debug-session','runId'=>'run1','hypothesisId'=>'A','location'=>'ProcessUrlBefore.php:103','message'=>'after router process','data'=>['module'=>$module,'path'=>$path,'rule_module'=>($rule['module']??'none')],'timestamp'=>time()*1000])."\n", FILE_APPEND);
                // #endregion
            }
            
            // 优化：如果路由已匹配，提前退出循环
            if (!empty($rule['module'])) {
                break;
            }
        }
        $data->setData('path', $path);
        $data->setData('rule', $rule);
    }
    
    /**
     * 清除静态缓存（用于开发模式或缓存清理时）
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cachedModuleRouters = null;
        self::$staticCacheInstance = null;
    }
}
