<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\System\File\Io\File;

class Data
{
    /**
     * @DESC         |更新模块数据
     *
     * 参数区：
     *
     * @param array $modules
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function updateModules(array &$modules)
    {
        $file = new File();
        $file->open(Env::path_MODULES_FILE, $file::mode_w_add);
        $text = '<?php return ' . var_export($modules, true) . ';';
        $file->write($text);
        $file->close();
    }

    /**
     * @DESC         |更新模块数据
     *
     * 参数区：
     *
     * @param array  $routers
     * @param string $path
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function updatePcRouters(string $path, array &$routers)
    {
        $file = new File();
        $file->open($path, $file::mode_w_add);
        $text = '<?php return ' . var_export($routers, true) . ';';
        $file->write($text);
        $file->close();
    }

    /**
     * @DESC         |更新模块数据
     *
     * 参数区：
     *
     * @param string $path
     * @param array  $api
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function updateApiRouters(string $path, array &$api)
    {
        $routers[$api['router']] = $api['rule'];
        if (is_file($path)) {
            $file_routers = require $path;
            $routers      = array_merge($file_routers, $routers);
        }
        $file = new File();
        $file->open($path, $file::mode_w_add);
        $text = '<?php return ' . var_export($routers, true) . ';';
        $file->write($text);
        $file->close();
    }

    /**
     * @DESC         |清除指定模块的路由
     *
     * 参数区：
     *
     * @param string $path 路由文件路径
     * @param string|array $moduleNames 模块名称或模块名称数组
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function clearModuleRouters(string $path, string|array $moduleNames)
    {
        if (!is_file($path)) {
            return;
        }
        
        // 确保模块名称为数组
        if (is_string($moduleNames)) {
            $moduleNames = [$moduleNames];
        }
        
        // 读取现有路由
        $routers = require $path;
        if (!is_array($routers)) {
            $routers = [];
        }
        
        // 清除指定模块的路由
        $cleared = false;
        foreach ($routers as $routerKey => $router) {
            // 检查路由是否属于指定模块
            // 路由结构可能是：['module' => '...'] 或 ['rule' => ['module' => '...']]
            $routerModule = '';
            if (is_array($router)) {
                // 直接包含 module 键
                if (isset($router['module'])) {
                    $routerModule = $router['module'];
                }
                // 或者包含在 rule 键中
                elseif (isset($router['rule']) && is_array($router['rule']) && isset($router['rule']['module'])) {
                    $routerModule = $router['rule']['module'];
                }
            }
            
            if ($routerModule && in_array($routerModule, $moduleNames, true)) {
                unset($routers[$routerKey]);
                $cleared = true;
            }
        }
        
        // 如果有清除操作，重新写入文件
        if ($cleared) {
            $file = new File();
            $file->open($path, $file::mode_w_add);
            $text = '<?php return ' . var_export($routers, true) . ';';
            $file->write($text);
            $file->close();
        }
    }
}
