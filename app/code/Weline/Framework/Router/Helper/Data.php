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
     * @var array 批量写入模式下的路由存储 [文件路径 => 路由数组]
     */
    private array $batchRouters = [];
    
    /**
     * @var bool 是否启用批量写入模式
     */
    private bool $batchMode = false;
    
    /**
     * 启用批量写入模式
     */
    public function enableBatchMode(): void
    {
        $this->batchMode = true;
        $this->batchRouters = [];
    }
    
    /**
     * 禁用批量写入模式并一次性写入所有路由
     */
    public function flushBatchRouters(): void
    {
        if (!$this->batchMode) {
            return;
        }
        
        foreach ($this->batchRouters as $path => $routers) {
            $this->writeRoutersToFile($path, $routers);
        }
        
        $this->batchRouters = [];
        $this->batchMode = false;
    }
    
    /**
     * 检查是否处于批量模式
     * 
     * @return bool
     */
    public function isBatchMode(): bool
    {
        return $this->batchMode;
    }
    
    /**
     * 获取批量模式下的路由（用于读取）
     * 
     * @param string $path 路由文件路径
     * @return array 路由数组
     */
    public function getBatchRouters(string $path): array
    {
        if (isset($this->batchRouters[$path])) {
            return $this->batchRouters[$path];
        }
        
        // 如果批量路由中没有，从文件读取
        if (is_file($path)) {
            return require $path;
        }
        
        return [];
    }
    
    /**
     * 写入路由到文件（内部方法）
     * 
     * @param string $path 文件路径
     * @param array $routers 路由数组
     * @throws \Weline\Framework\App\Exception
     */
    private function writeRoutersToFile(string $path, array &$routers): void
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
        if ($this->batchMode) {
            // 批量模式：合并路由到属性，不立即写入
            if (isset($this->batchRouters[$path])) {
                // 合并路由，新路由覆盖旧路由
                $this->batchRouters[$path] = array_merge($this->batchRouters[$path], $routers);
            } else {
                $this->batchRouters[$path] = $routers;
            }
        } else {
            // 立即写入模式
            $this->writeRoutersToFile($path, $routers);
        }
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
        if ($this->batchMode) {
            // 批量模式：从属性或文件读取现有路由，合并后存储到属性
            $routers = $this->getBatchRouters($path);
            $routers[$api['router']] = $api['rule'];
            $this->batchRouters[$path] = $routers;
        } else {
            // 立即写入模式
            $routers[$api['router']] = $api['rule'];
            if (is_file($path)) {
                $file_routers = require $path;
                $routers      = array_merge($file_routers, $routers);
            }
            $this->writeRoutersToFile($path, $routers);
        }
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
