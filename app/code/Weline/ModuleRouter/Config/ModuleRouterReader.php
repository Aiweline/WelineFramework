<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleRouter\Config;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Register\Register;
use Weline\Framework\System\File\Scanner;
use Weline\ModuleRouter\Cache\ModuleRouterCache;

use function p;

class ModuleRouterReader extends \Weline\Framework\System\ModuleFileReader
{
    private CacheInterface $moduleRouterCache;

    public function __construct(Scanner $scanner, ModuleRouterCache $moduleRouterCache, string $path = 'Controller' . DS . 'Router.php')
    {
        parent::__construct($scanner, $path);
        $this->moduleRouterCache = $moduleRouterCache->create();
    }

    public function read(): array
    {
        $cache_key = 'routers_rules_cache';
        if ($router_rules = $this->moduleRouterCache->get($cache_key)) {
            return $router_rules;
        }
        $callback = function ($vendors_modules) {
            $modules = Env::getInstance()->getActiveModules();
            foreach ($vendors_modules as $module => $router_file) {
                # 跳过不存在的模块
                if (!isset($modules[$module])) {
                    unset($vendors_modules[$module]);
                    continue;
                }
                # 兼容composer的路由文件
                $base_namespace           = $modules[$module]['namespace_path'] . '\\';
                $namespace                = str_replace($modules[$module]['base_path'], $base_namespace, $router_file);
                $namespace                = str_replace(['.php', DS], ['', '\\'], $namespace);
                $router_file              = [
                    'origin' => $router_file,
                    'class'  => $namespace,
                ];
                $vendors_modules[$module] = $router_file;
            }
            return $vendors_modules;
        };
        $data     = $this->getFileList($callback);
        $this->moduleRouterCache->set($cache_key, $data);
        return $data;
    }
}
