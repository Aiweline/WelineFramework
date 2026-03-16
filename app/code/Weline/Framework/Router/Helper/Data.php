<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router\Helper;

use Weline\Framework\App\Env;

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
        
        // 写入已注册的路由
        foreach ($this->batchRouters as $path => $routers) {
            $this->writeRoutersToFile($path, $routers);
        }
        
        // 确保所有路由文件都存在（即使没有路由，也创建空文件）
        $routerFiles = [
            Env::path_BACKEND_PC_ROUTER_FILE,
            Env::path_BACKEND_REST_API_ROUTER_FILE,
            Env::path_FRONTEND_PC_ROUTER_FILE,
            Env::path_FRONTEND_REST_API_ROUTER_FILE,
        ];
        
        foreach ($routerFiles as $path) {
            // 如果文件不存在，创建空数组文件
            if (!isset($this->batchRouters[$path]) && !is_file($path)) {
                $emptyRouters = [];
                $this->writeRoutersToFile($path, $emptyRouters);
            }
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
        
        // 如果批量路由中没有
        if ($this->batchMode) {
            // 批量模式下：只从批量缓存读取，不从文件读取（避免覆盖已收集的路由）
            // 如果批量缓存中没有，返回空数组
            $this->batchRouters[$path] = [];
            return [];
        } else {
            // 非批量模式：从文件读取
            $routers = [];
            if (is_file($path)) {
                $routers = require $path;
                if (!is_array($routers)) {
                    $routers = [];
                }
            }
            return $routers;
        }
    }
    
    /**
     * 写入路由到文件（内部方法）
     * 使用流式写入避免大路由数组时 var_export 一次性占用过多内存导致内存耗尽。
     *
     * @param string $path 文件路径
     * @param array $routers 路由数组
     * @throws \Weline\Framework\App\Exception
     */
    private function writeRoutersToFile(string $path, array &$routers): void
    {
        $this->writeArrayToPhpFile($path, $routers);
    }

    /**
     * 将大数组流式写入为 PHP return 文件，逐项 fwrite，避免整表 var_export 占用过多内存。
     *
     * @param string $path 文件路径
     * @param array $data 数组数据
     * @throws \Weline\Framework\App\Exception
     */
    private function writeArrayToPhpFile(string $path, array &$data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new \Weline\Framework\App\Exception(__('无法打开文件：%{1}', [$path]));
        }
        fwrite($fh, "<?php return [\n");
        $first = true;
        foreach ($data as $key => $value) {
            if (!$first) {
                fwrite($fh, ",\n");
            }
            $first = false;
            fwrite($fh, var_export($key, true) . ' => ' . var_export($value, true));
        }
        fwrite($fh, "\n];\n");
        fclose($fh);
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
    public function updateModules(array &$modules): void
    {
        $this->writeArrayToPhpFile(Env::path_MODULES_FILE, $modules);
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
            // 批量模式：直接将路由数组保存到批量缓存中
            // 注意：$routers 参数已经通过 register() 方法中的 getBatchRouters() 
            // 获取了现有路由，并添加了新路由，所以这里直接保存即可
            $this->batchRouters[$path] = $routers;
        } else {
            // 立即写入模式：需要从文件读取现有路由并合并
            $existingRouters = [];
            if (is_file($path)) {
                $existingRouters = require $path;
                if (!is_array($existingRouters)) {
                    $existingRouters = [];
                }
            }
            // 合并现有路由和新路由
            $routers = array_merge($existingRouters, $routers);
            // 立即写入
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
            // 批量模式：从批量缓存读取现有路由，合并后存储到批量缓存
            $routers = $this->getBatchRouters($path);
            $routers[$api['router']] = $api['rule'];
            $this->batchRouters[$path] = $routers;
        } else {
            // 立即写入模式
            $routers = [];
            $routers[$api['router']] = $api['rule'];
            if (is_file($path)) {
                $file_routers = require $path;
                if (is_array($file_routers)) {
                    $routers = array_merge($file_routers, $routers);
                }
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
        
        if ($cleared) {
            $this->writeArrayToPhpFile($path, $routers);
        }
    }
}
