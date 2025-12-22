<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Hook\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Hook\Service\HookDataService;

/**
 * Hook 管理控制器
 */
class Hook extends BaseController
{
    /**
     * Hook 列表页面
     */
    public function index()
    {
        try {
            /** @var HookDataService $service */
            $service = ObjectManager::getInstance(HookDataService::class);
            
            // 获取筛选参数
            $filterModule = trim($this->request->getGet('module', ''));
            $filterRegistered = $this->request->getGet('registered', '');
            $filterFiles = $this->request->getGet('files', '');
            $filterArea = $this->request->getGet('area', '');
            $filterType = $this->request->getGet('type', '');
            $sortBy = $this->request->getGet('sort', 'name'); // name, module, files
            $sortOrder = $this->request->getGet('order', 'asc'); // asc, desc
            $quickSearch = trim($this->request->getGet('q', ''));
            
            // 获取所有 Hook 数据
            $allHooks = $service->getAllHooks();
            
            // 应用筛选
            $filteredHooks = $allHooks;
            
            // 快速搜索
            if (!empty($quickSearch)) {
                $filteredHooks = $service->searchHooks($quickSearch, 'all');
            }
            
            // 模块筛选
            if (!empty($filterModule)) {
                $moduleHooks = $service->getHooksByModule($filterModule);
                $filteredHooks = array_intersect_key($filteredHooks, $moduleHooks);
            }
            
            // 注册状态筛选
            if ($filterRegistered !== '') {
                $filteredHooks = array_filter($filteredHooks, function($hook) use ($filterRegistered) {
                    $isRegistered = $hook['is_registered'] ?? false;
                    return ($filterRegistered === '1' && $isRegistered) || ($filterRegistered === '0' && !$isRegistered);
                });
            }
            
            // 文件筛选
            if ($filterFiles !== '') {
                $filteredHooks = array_filter($filteredHooks, function($hook) use ($filterFiles) {
                    $hasFiles = $hook['has_files'] ?? false;
                    return ($filterFiles === '1' && $hasFiles) || ($filterFiles === '0' && !$hasFiles);
                });
            }
            
            // 区域筛选
            if (!empty($filterArea)) {
                $filteredHooks = array_filter($filteredHooks, function($hook) use ($filterArea) {
                    return ($hook['area'] ?? '') === $filterArea;
                });
            }
            
            // 类型筛选
            if (!empty($filterType)) {
                $filteredHooks = array_filter($filteredHooks, function($hook) use ($filterType) {
                    return ($hook['type'] ?? '') === $filterType;
                });
            }
            
            // 排序
            if ($sortBy === 'name') {
                uksort($filteredHooks, function($a, $b) use ($sortOrder) {
                    $result = strcasecmp($a, $b);
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            } elseif ($sortBy === 'module') {
                uasort($filteredHooks, function($a, $b) use ($sortOrder) {
                    $result = strcasecmp($a['module'] ?? '', $b['module'] ?? '');
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            } elseif ($sortBy === 'files') {
                uasort($filteredHooks, function($a, $b) use ($sortOrder) {
                    $countA = $a['file_count'] ?? 0;
                    $countB = $b['file_count'] ?? 0;
                    $result = $countA <=> $countB;
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            }
            
            // 获取所有模块列表（用于筛选下拉框）
            $allModules = [];
            foreach ($allHooks as $hook) {
                $module = $hook['module'] ?? '';
                if ($module && !in_array($module, $allModules)) {
                    $allModules[] = $module;
                }
                foreach ($hook['using_modules'] ?? [] as $usingModule) {
                    if ($usingModule && !in_array($usingModule, $allModules)) {
                        $allModules[] = $usingModule;
                    }
                }
            }
            sort($allModules);
            
            // 获取统计信息
            $stats = $service->getHookStats();
            
            $this->assign('hooks', $filteredHooks);
            $this->assign('stats', $stats);
            $this->assign('all_modules', $allModules);
            $this->assign('filter_module', $filterModule);
            $this->assign('filter_registered', $filterRegistered);
            $this->assign('filter_files', $filterFiles);
            $this->assign('filter_area', $filterArea);
            $this->assign('filter_type', $filterType);
            $this->assign('sort_by', $sortBy);
            $this->assign('sort_order', $sortOrder);
            $this->assign('quick_search', $quickSearch);
            $this->assign('title', __('Hook 管理'));
            return $this->fetchBase();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载 Hook 列表失败: %{1}', $e->getMessage()));
            $this->assign('hooks', []);
            $this->assign('stats', []);
            $this->assign('all_modules', []);
            $this->assign('title', __('Hook 管理'));
            return $this->fetchBase();
        }
    }

    /**
     * Hook 详情 API（用于 offcanvas AJAX 加载）
     */
    public function detail()
    {
        try {
            // 只支持 AJAX 请求
            if (!$this->request->isAjax()) {
                $this->redirect('*/index');
                return;
            }
            
            $hookName = $this->request->getParam('hook');
            if (empty($hookName)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('请指定 Hook 名')], JSON_UNESCAPED_UNICODE);
                return;
            }

            /** @var HookDataService $service */
            $service = ObjectManager::getInstance(HookDataService::class);
            
            // 获取 Hook 详情
            $hookDetail = $service->getHookDetail($hookName);
            
            if (!$hookDetail) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('Hook 不存在: %{1}', $hookName)], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            // 返回 Block 渲染的内容
            /** @var \Weline\Hook\Block\Backend\Hook\Detail $detailBlock */
            $detailBlock = ObjectManager::make(\Weline\Hook\Block\Backend\Hook\Detail::class, [
                'data' => [
                    'hook' => $hookDetail,
                    'hook_name' => $hookName
                ]
            ]);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'html' => $detailBlock->render(),
                'title' => __('Hook 详情') . ': ' . $hookName
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            $errorMessage = $e->getMessage() ?: $e->getTraceAsString();
            echo json_encode([
                'success' => false, 
                'message' => __('加载 Hook 详情失败: %{1}', $errorMessage),
                'error' => $errorMessage,
                'trace' => DEV ? $e->getTraceAsString() : ''
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * AJAX 搜索接口
     */
    public function getSearchAjax()
    {
        try {
            // 只支持 AJAX 请求
            if (!$this->request->isAjax()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('仅支持 AJAX 请求')], JSON_UNESCAPED_UNICODE);
                return;
            }

            /** @var HookDataService $service */
            $service = ObjectManager::getInstance(HookDataService::class);
            
            // 获取筛选参数
            $filterModule = trim($this->request->getGet('module', ''));
            $filterRegistered = $this->request->getGet('registered', '');
            $filterFiles = $this->request->getGet('files', '');
            $filterArea = $this->request->getGet('area', '');
            $filterType = $this->request->getGet('type', '');
            $sortBy = $this->request->getGet('sort', 'name');
            $sortOrder = $this->request->getGet('order', 'asc');
            $quickSearch = trim($this->request->getGet('q', ''));
            
            // 获取所有 Hook 数据
            $allHooks = $service->getAllHooks();
            
            // 应用筛选
            $filteredHooks = $allHooks;
            
            // 快速搜索
            if (!empty($quickSearch)) {
                $filteredHooks = $service->searchHooks($quickSearch, 'all');
            }
            
            // 模块筛选
            if (!empty($filterModule)) {
                $moduleHooks = $service->getHooksByModule($filterModule);
                $filteredHooks = array_intersect_key($filteredHooks, $moduleHooks);
            }
            
            // 注册状态筛选
            if ($filterRegistered !== '') {
                $filteredHooks = array_filter($filteredHooks, function($hook) use ($filterRegistered) {
                    $isRegistered = $hook['is_registered'] ?? false;
                    return ($filterRegistered === '1' && $isRegistered) || ($filterRegistered === '0' && !$isRegistered);
                });
            }
            
            // 文件筛选
            if ($filterFiles !== '') {
                $filteredHooks = array_filter($filteredHooks, function($hook) use ($filterFiles) {
                    $hasFiles = $hook['has_files'] ?? false;
                    return ($filterFiles === '1' && $hasFiles) || ($filterFiles === '0' && !$hasFiles);
                });
            }
            
            // 区域筛选
            if (!empty($filterArea)) {
                $filteredHooks = array_filter($filteredHooks, function($hook) use ($filterArea) {
                    return ($hook['area'] ?? '') === $filterArea;
                });
            }
            
            // 类型筛选
            if (!empty($filterType)) {
                $filteredHooks = array_filter($filteredHooks, function($hook) use ($filterType) {
                    return ($hook['type'] ?? '') === $filterType;
                });
            }
            
            // 排序
            if ($sortBy === 'name') {
                uksort($filteredHooks, function($a, $b) use ($sortOrder) {
                    $result = strcasecmp($a, $b);
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            } elseif ($sortBy === 'module') {
                uasort($filteredHooks, function($a, $b) use ($sortOrder) {
                    $result = strcasecmp($a['module'] ?? '', $b['module'] ?? '');
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            } elseif ($sortBy === 'files') {
                uasort($filteredHooks, function($a, $b) use ($sortOrder) {
                    $countA = $a['file_count'] ?? 0;
                    $countB = $b['file_count'] ?? 0;
                    $result = $countA <=> $countB;
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            }
            
            // 获取统计信息
            $stats = $service->getHookStats();
            
            // 渲染列表 HTML
            $this->assign('hooks', $filteredHooks);
            $this->assign('stats', $stats);
            $this->assign('filter_module', $filterModule);
            $this->assign('filter_registered', $filterRegistered);
            $this->assign('filter_files', $filterFiles);
            $this->assign('filter_area', $filterArea);
            $this->assign('filter_type', $filterType);
            $this->assign('sort_by', $sortBy);
            $this->assign('sort_order', $sortOrder);
            $this->assign('quick_search', $quickSearch);
            
            // 返回 JSON 数据
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'hooks' => $filteredHooks,
                'stats' => $stats,
                'total_count' => count($filteredHooks),
                'html' => $this->fetch('Backend/Hook/list_table')
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => __('搜索失败: %{1}', $e->getMessage())
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}
