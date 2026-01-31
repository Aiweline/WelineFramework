<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Api\Rest\V1;

use Weline\Framework\App\Env;
use Weline\Framework\App\Controller\BackendRestController;

class Routes extends BackendRestController
{
    /**
     * 获取所有模块的路由信息
     */
    public function getIndex()
    {
        try {
            $type = $this->request->getGet('type', 'frontend'); // frontend 或 backend
            
            // 读取路由文件
            $routerFile = $type === 'backend' 
                ? Env::path_BACKEND_PC_ROUTER_FILE 
                : Env::path_FRONTEND_PC_ROUTER_FILE;
            
            if (!is_file($routerFile)) {
                return $this->error('路由文件不存在', 404);
            }

            $routers = include $routerFile;
            
            // 按模块分组路由
            $modulesRouters = [];
            foreach ($routers as $path => $router) {
                $module = $router['module'];
                
                // 只处理 GET 请求或无方法限制的路由
                if (str_contains($path, '::GET') || !str_contains($path, '::')) {
                    $cleanPath = str_replace('::GET', '', $path);
                    
                    if (!isset($modulesRouters[$module])) {
                        $modulesRouters[$module] = [
                            'name' => $module,
                            'routes' => []
                        ];
                    }
                    
                    $modulesRouters[$module]['routes'][] = [
                        'path' => $cleanPath,
                        'url' => '/' . $cleanPath,
                        'controller' => $router['class']['name'] ?? '',
                        'method' => $router['class']['method'] ?? 'index'
                    ];
                }
            }
            
            // 按模块名称排序
            ksort($modulesRouters);
            
            return $this->success('success', array_values($modulesRouters));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * 搜索路由
     */
    public function getSearch()
    {
        try {
            $keyword = $this->request->getGet('keyword', '');
            $type = $this->request->getGet('type', 'frontend');
            
            if (empty($keyword)) {
                return $this->error('搜索关键词不能为空', 400);
            }
            
            // 读取路由文件
            $routerFile = $type === 'backend' 
                ? Env::path_BACKEND_PC_ROUTER_FILE 
                : Env::path_FRONTEND_PC_ROUTER_FILE;
            
            if (!is_file($routerFile)) {
                return $this->error('路由文件不存在', 404);
            }

            $routers = include $routerFile;
            
            // 搜索匹配的路由
            $results = [];
            foreach ($routers as $path => $router) {
                $module = $router['module'];
                
                // 只处理 GET 请求或无方法限制的路由
                if (str_contains($path, '::GET') || !str_contains($path, '::')) {
                    $cleanPath = str_replace('::GET', '', $path);
                    
                    // 搜索匹配（模块名或路径）
                    if (stripos($module, $keyword) !== false || stripos($cleanPath, $keyword) !== false) {
                        $results[] = [
                            'module' => $module,
                            'path' => $cleanPath,
                            'url' => '/' . $cleanPath,
                            'controller' => $router['class']['name'] ?? '',
                            'method' => $router['class']['method'] ?? 'index'
                        ];
                    }
                }
            }
            
            return $this->success('success', $results);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
