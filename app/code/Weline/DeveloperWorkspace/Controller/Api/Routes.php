<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Controller\Api;

use Weline\Framework\App\Env;
use Weline\Framework\App\Controller\FrontendController;

class Routes extends FrontendController
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
                return $this->json(['success' => false, 'message' => '路由文件不存在']);
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
            
            return $this->json([
                'success' => true,
                'data' => array_values($modulesRouters)
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
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
                return $this->json(['success' => false, 'message' => '搜索关键词不能为空']);
            }
            
            // 读取路由文件
            $routerFile = $type === 'backend' 
                ? Env::path_BACKEND_PC_ROUTER_FILE 
                : Env::path_FRONTEND_PC_ROUTER_FILE;
            
            if (!is_file($routerFile)) {
                return $this->json(['success' => false, 'message' => '路由文件不存在']);
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
            
            return $this->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 返回 JSON 响应
     */
    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

