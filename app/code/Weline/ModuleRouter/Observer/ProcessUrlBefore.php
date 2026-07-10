<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleRouter\Observer;

use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;
use Weline\Framework\Router\RouterInterface;
use Weline\ModuleRouter\Config\ModuleRouterReader;

class ProcessUrlBefore implements \Weline\Framework\Event\ObserverInterface
{
    private CachePoolInterface $moduleRouterCache;
    
    /**
     * 静态缓存模块路由列表，避免每次事件分发都重新读取
     * 
     * @var array|null
     */
    private static ?array $cachedModuleRouters = null;
    
    /**
     * 静态缓存实例，避免重复创建
     * 
     * @var CachePoolInterface|null
     */
    private static ?CachePoolInterface $staticCacheInstance = null;

    public function __construct(
        private Request $request
    )
    {
        $this->moduleRouterCache = w_cache('module_router');
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

        if (!empty($rule['module']) || self::shouldBypassModuleRouters((string)$path, $this->request)) {
            return;
        }
        
        // 使用静态缓存，避免每次事件分发都重新读取模块路由
        if (self::$cachedModuleRouters === null) {
            
            // 优化：直接从缓存读取，避免实例化ModuleRouterReader
            if (self::$staticCacheInstance === null) {
                self::$staticCacheInstance = $this->moduleRouterCache;
            }
            $cache_key = 'routers_rules_cache';
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
        
        foreach ($moduleRouters as $module => $moduleRouter) {
            $routerClass = $moduleRouter['class'];
            
            // RouterInterface::process() 是静态方法，直接使用类名调用，无需实例化
            // 这样可以支持静态类（如 Weline\BackendThemeUpzet\Controller\Router）
            if (class_exists($routerClass) && is_subclass_of($routerClass, RouterInterface::class)) {
                $routerClass::process($path, $rule);
                $moduleCount++;
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

    private static function shouldBypassModuleRouters(string $path, ?Request $request = null): bool
    {
        if ($request !== null && ($request->isApiFrontend() || $request->isApiBackend())) {
            return true;
        }

        $originUri = (string)($request?->getServer('WELINE_ORIGIN_REQUEST_URI') ?? '');
        if ($originUri !== '' && self::shouldBypassNormalizedPath(self::normalizeBypassPath($originUri))) {
            return true;
        }

        $normalized = self::normalizeBypassPath($path);
        if ($normalized === '') {
            return false;
        }

        return self::shouldBypassNormalizedPath($normalized);
    }

    private static function shouldBypassNormalizedPath(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        if (self::isDynamicMediaProcessorPath($normalized)) {
            return false;
        }

        if (preg_match('#^api\d*(?:/|$)#', $normalized)) {
            return true;
        }

        if (
            $normalized === 'static'
            || str_starts_with($normalized, 'static/')
            || $normalized === 'pub/static'
            || str_starts_with($normalized, 'pub/static/')
        ) {
            return true;
        }

        foreach ([
            'customer',
            'customer/account',
            'checkout',
            'cart',
            'wishlist',
            'search',
            'weshop/product',
            'weshop/catalog',
            'weshop/blog',
            'product/view',
            'product/frontend/product',
            'catalog/category/view',
            'catalog/frontend/category',
            'blog/frontend',
        ] as $reservedPrefix) {
            if ($normalized === $reservedPrefix || str_starts_with($normalized, $reservedPrefix . '/')) {
                return true;
            }
        }

        return (bool)preg_match(
            '#\.(?:js|css|mjs|map|jpg|jpeg|png|gif|svg|ico|webp|avif|woff|woff2|ttf|eot)$#i',
            $normalized
        );
    }

    private static function isDynamicMediaProcessorPath(string $normalized): bool
    {
        return str_starts_with($normalized, 'media/image/')
            || str_starts_with($normalized, 'media/file/');
    }

    private static function normalizeBypassPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }

        if (str_contains($path, '://')) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsedPath) ? $parsedPath : '';
        } elseif (str_contains($path, '?')) {
            $path = explode('?', $path, 2)[0];
        }

        return strtolower(trim($path, '/'));
    }
}
