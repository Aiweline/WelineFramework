<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Component\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Component\Service\ComponentScanner;
use Weline\Framework\Manager\ObjectManager;

/**
 * 组件库控制器
 */
class Components extends BaseController
{
    /**
     * 重写 fetch 方法，使用 BaseController 的布局包装
     */
    public function fetch(string $fileName = '', array $data = []): string
    {
        return $this->fetchBase($fileName, $data);
    }
    
    /**
     * 组件库首页
     */
    public function getIndex(): string
    {
        try {
            /** @var ComponentScanner $scanner */
            $scanner = ObjectManager::getInstance(ComponentScanner::class);
            
            // 扫描所有组件
            $allComponents = $scanner->scanAllComponents();
            
            // 按分类组织
            $groupedComponents = $scanner->groupByCategory($allComponents);
            
            // 获取筛选参数
            $filterModule = trim($this->request->getGet('module', ''));
            $filterCategory = trim($this->request->getGet('category', ''));
            $searchKeyword = trim($this->request->getGet('q', ''));
            
            // 应用筛选
            $filteredComponents = $allComponents;
            
            // 模块筛选
            if (!empty($filterModule)) {
                $filteredComponents = array_filter($filteredComponents, function($component) use ($filterModule) {
                    return ($component['module'] ?? '') === $filterModule;
                });
            }
            
            // 分类筛选
            if (!empty($filterCategory)) {
                $filteredComponents = array_filter($filteredComponents, function($component) use ($filterCategory) {
                    return ($component['category'] ?? '') === $filterCategory;
                });
            }
            
            // 关键词搜索
            if (!empty($searchKeyword)) {
                $filteredComponents = array_filter($filteredComponents, function($component) use ($searchKeyword) {
                    $searchFields = [
                        $component['class'] ?? '',
                        $component['module'] ?? '',
                        $component['category'] ?? '',
                        $component['namespace'] ?? '',
                        $component['doc'] ?? ''
                    ];
                    $searchText = strtolower(implode(' ', $searchFields));
                    return str_contains($searchText, strtolower($searchKeyword));
                });
            }
            
            // 获取所有模块列表（用于筛选）
            $allModules = [];
            foreach ($allComponents as $component) {
                $module = $component['module'] ?? '';
                if ($module && !in_array($module, $allModules)) {
                    $allModules[] = $module;
                }
            }
            sort($allModules);
            
            // 获取所有分类列表（用于筛选）
            $allCategories = array_keys($groupedComponents);
            
            // 统计信息
            $stats = [
                'total' => count($allComponents ?? []),
                'filtered' => count($filteredComponents ?? []),
                'modules' => count($allModules ?? []),
                'categories' => count($allCategories ?? [])
            ];
            
            $this->assign('components', $filteredComponents);
            $this->assign('grouped_components', $groupedComponents);
            $this->assign('all_modules', $allModules);
            $this->assign('all_categories', $allCategories);
            $this->assign('stats', $stats);
            $this->assign('filter_module', $filterModule);
            $this->assign('filter_category', $filterCategory);
            $this->assign('search_keyword', $searchKeyword);
            $this->assign('title', __('组件库'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载组件列表失败: %{1}', $e->getMessage()));
            $this->assign('components', []);
            $this->assign('grouped_components', []);
            $this->assign('all_modules', []);
            $this->assign('all_categories', []);
            $this->assign('stats', ['total' => 0, 'filtered' => 0, 'modules' => 0, 'categories' => 0]);
            $this->assign('title', __('组件库'));
            return $this->fetch();
        }
    }
    
    /**
     * 组件详情（AJAX）
     */
    public function getDetail()
    {
        try {
            if (!$this->request->isAjax()) {
                $this->redirect('*/index');
                return;
            }
            
            $className = trim($this->request->getGet('class') ?? '');
            if (empty($className)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('请指定组件类名')], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            /** @var ComponentScanner $scanner */
            $scanner = ObjectManager::getInstance(ComponentScanner::class);
            $allComponents = $scanner->scanAllComponents();
            
            if (!isset($allComponents[$className])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('组件不存在')], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            $component = $allComponents[$className];
            
            // 确保返回完整的数据结构
            $componentData = [
                'class' => $component['class'] ?? '',
                'module' => $component['module'] ?? '',
                'category' => $component['category'] ?? '',
                'namespace' => $component['namespace'] ?? '',
                'full_class' => $className,
                'doc' => $component['doc'] ?? ''
            ];
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'component' => $componentData,
                'title' => __('组件详情') . ': ' . ($componentData['class'] ?? $className)
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => __('加载组件详情失败: %{1}', $e->getMessage() ?? ''),
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }
    
    /**
     * AJAX获取组件列表
     */
    public function getList()
    {
        try {
            // 只支持 AJAX 请求
            if (!$this->request->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('仅支持AJAX请求')], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            /** @var ComponentScanner $scanner */
            $scanner = ObjectManager::getInstance(ComponentScanner::class);
            
            // 扫描所有组件
            $allComponents = $scanner->scanAllComponents();
            
            // 按分类组织
            $groupedComponents = $scanner->groupByCategory($allComponents);
            
            // 获取筛选参数 - 修复参数获取方式
            $filterModule = trim($this->request->getGet('module') ?? '');
            $filterCategory = trim($this->request->getGet('category') ?? '');
            $searchKeyword = trim($this->request->getGet('q') ?? '');
            
            // 应用筛选
            $filteredComponents = $allComponents;
            
            // 模块筛选
            if (!empty($filterModule)) {
                $filteredComponents = array_filter($filteredComponents, function($component) use ($filterModule) {
                    return ($component['module'] ?? '') === $filterModule;
                });
            }
            
            // 分类筛选
            if (!empty($filterCategory)) {
                $filteredComponents = array_filter($filteredComponents, function($component) use ($filterCategory) {
                    return ($component['category'] ?? '') === $filterCategory;
                });
            }
            
            // 关键词搜索
            if (!empty($searchKeyword)) {
                $filteredComponents = array_filter($filteredComponents, function($component) use ($searchKeyword) {
                    $searchFields = [
                        $component['class'] ?? '',
                        $component['module'] ?? '',
                        $component['category'] ?? '',
                        $component['namespace'] ?? '',
                        $component['doc'] ?? ''
                    ];
                    $searchText = strtolower(implode(' ', $searchFields));
                    return str_contains($searchText, strtolower($searchKeyword));
                });
            }
            
            // 获取所有模块列表（用于筛选）
            $allModules = [];
            foreach ($allComponents as $component) {
                $module = $component['module'] ?? '';
                if ($module && !in_array($module, $allModules)) {
                    $allModules[] = $module;
                }
            }
            sort($allModules);
            
            // 获取所有分类列表（用于筛选）
            $allCategories = array_keys($groupedComponents);
            
            // 统计信息
            $stats = [
                'total' => count($allComponents ?? []),
                'filtered' => count($filteredComponents ?? []),
                'modules' => count($allModules ?? []),
                'categories' => count($allCategories ?? [])
            ];
            
            // 格式化组件数据用于JSON返回
            $componentsData = [];
            foreach ($filteredComponents as $fullClass => $component) {
                $componentsData[$fullClass] = [
                    'class' => $component['class'] ?? '',
                    'module' => $component['module'] ?? '',
                    'category' => $component['category'] ?? '',
                    'namespace' => $component['namespace'] ?? '',
                    'full_class' => $fullClass,
                    'doc' => $component['doc'] ?? ''
                ];
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'components' => $componentsData,
                'all_modules' => $allModules,
                'all_categories' => $allCategories,
                'filters' => [
                    'module' => $filterModule,
                    'category' => $filterCategory,
                    'q' => $searchKeyword
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => __('加载组件列表失败: %{1}', $e->getMessage() ?? ''),
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}