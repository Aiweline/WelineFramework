<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl as AclAttribute;
use Weline\Framework\Manager\ObjectManager;
use Weline\Sticker\Service\StickerDataService;

/**
 * Sticker 管理后台控制器
 * 
 * @package Weline_Sticker
 */
#[AclAttribute('Weline_Sticker::sticker_manager', 'Sticker管理', 'mdi-sticker', 'Sticker管理', '')]
class Sticker extends BackendController
{
    /**
     * 获取Sticker数据服务
     */
    private function getStickerDataService(): StickerDataService
    {
        return ObjectManager::getInstance(StickerDataService::class);
    }

    /**
     * Sticker 列表页面
     * 
     * @return string
     */
    #[AclAttribute('Weline_Sticker::sticker_list', '查看Sticker列表', 'mdi-view-list', '查看Sticker列表')]
    public function index(): string
    {
        try {
            $search = trim($this->request->getGet('search', ''));
            $targetModule = trim($this->request->getGet('target_module', ''));
            $sourceModule = trim($this->request->getGet('source_module', ''));
            $searchType = trim($this->request->getGet('search_type', 'all'));
            $sortBy = $this->request->getGet('sort', 'target_module');
            $sortOrder = $this->request->getGet('order', 'asc');
            
            /** @var StickerDataService $service */
            $service = $this->getStickerDataService();
            
            // 获取所有Sticker数据
            if (!empty($search)) {
                $stickers = $service->searchStickers($search, $searchType);
            } else {
                $stickers = $service->getAllStickers();
            }
            
            // 应用模块筛选
            if (!empty($targetModule)) {
                $stickers = array_filter($stickers, function($sticker) use ($targetModule) {
                    return $sticker['target_module'] === $targetModule;
                });
            }
            
            if (!empty($sourceModule)) {
                $stickers = array_filter($stickers, function($sticker) use ($sourceModule) {
                    return $sticker['source_module'] === $sourceModule;
                });
            }
            
            // 排序
            usort($stickers, function($a, $b) use ($sortBy, $sortOrder) {
                $valueA = $a[$sortBy] ?? '';
                $valueB = $b[$sortBy] ?? '';
                
                if ($sortBy === 'is_active' || $sortBy === 'has_conflict') {
                    $valueA = $valueA ? 1 : 0;
                    $valueB = $valueB ? 1 : 0;
                }
                
                if ($sortOrder === 'asc') {
                    return $valueA <=> $valueB;
                } else {
                    return $valueB <=> $valueA;
                }
            });
            
            // 获取统计信息
            $stats = $service->getStickerStats();
            
            // 获取所有模块列表（用于过滤）
            $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
            $moduleList = array_keys($modules);

            $this->assign('stickers', $stickers);
            $this->assign('total', count($stickers));
            $this->assign('stats', $stats);
            $this->assign('search', $search);
            $this->assign('search_type', $searchType);
            $this->assign('target_module', $targetModule);
            $this->assign('source_module', $sourceModule);
            $this->assign('sort_by', $sortBy);
            $this->assign('sort_order', $sortOrder);
            $this->assign('modules', $moduleList);

            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载Sticker列表失败：%{1}', [$e->getMessage()]));
            return $this->fetch();
        }
    }

    /**
     * Sticker 详情页面
     * 
     * @return string
     */
    #[AclAttribute('Weline_Sticker::sticker_detail', '查看Sticker详情', 'mdi-eye', '查看Sticker详情')]
    public function detail(): string
    {
        try {
            $targetModule = trim($this->request->getGet('target_module', ''));
            $targetFile = trim($this->request->getGet('target_file', ''));
            $sourceModule = trim($this->request->getGet('source_module', ''));
            $isAjax = $this->request->getGet('isAjax', false);

            if (empty($targetModule) || empty($targetFile) || empty($sourceModule)) {
                $message = __('参数不完整');
                if ($isAjax) {
                    return json_encode(['success' => false, 'message' => $message]);
                }
                $this->getMessageManager()->addError($message);
                return $this->redirect('*/backend/sticker/index');
            }

            /** @var StickerDataService $service */
            $service = $this->getStickerDataService();
            
            // 获取所有Sticker数据
            $stickers = $service->getAllStickers();
            
            // 查找对应的 Sticker 信息
            $stickerInfo = null;
            foreach ($stickers as $sticker) {
                if ($sticker['target_module'] === $targetModule && 
                    $sticker['target_file'] === $targetFile && 
                    $sticker['source_module'] === $sourceModule) {
                    $stickerInfo = $sticker;
                    break;
                }
            }

            if (!$stickerInfo) {
                $message = __('Sticker不存在');
                if ($isAjax) {
                    return json_encode(['success' => false, 'message' => $message]);
                }
                $this->getMessageManager()->addError($message);
                return $this->redirect('*/backend/sticker/index');
            }

            $stickerFile = $stickerInfo['sticker_file'] ?? '';
            $actions = $stickerInfo['actions'] ?? [];
            $status = $stickerInfo['status'] ?? [];

            // 读取 Sticker 文件内容
            $stickerContent = '';
            if (file_exists($stickerFile)) {
                $stickerContent = file_get_contents($stickerFile);
            }

            // 读取源文件内容
            $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
            $sourceContent = '';
            $sourceFilePath = '';
            if (isset($modules[$targetModule])) {
                $basePath = $modules[$targetModule]['base_path'] ?? '';
                $sourceFilePath = $basePath . str_replace('/', DIRECTORY_SEPARATOR, $targetFile);
                if (file_exists($sourceFilePath)) {
                    $sourceContent = file_get_contents($sourceFilePath);
                }
            }

            // 读取编译后的文件内容
            $compiledContent = '';
            $compiledFilePath = '';
            try {
                $compiler = ObjectManager::getInstance(\Weline\Sticker\Service\Compiler::class);
                $compiledFilePath = $compiler->getCompiledFilePath($targetModule, $targetFile);
                if (file_exists($compiledFilePath)) {
                    $compiledContent = file_get_contents($compiledFilePath);
                }
            } catch (\Exception $e) {
                // 忽略编译文件读取错误
            }

            $data = [
                'target_module' => $targetModule,
                'target_file' => $targetFile,
                'source_module' => $sourceModule,
                'sticker_file' => $stickerFile,
                'sticker_relative_path' => $stickerInfo['sticker_relative_path'] ?? '',
                'actions' => $actions,
                'actions_count' => $stickerInfo['actions_count'] ?? 0,
                'status' => $status,
                'is_active' => $stickerInfo['is_active'] ?? false,
                'has_conflict' => $stickerInfo['has_conflict'] ?? false,
                'error_message' => $stickerInfo['error_message'] ?? '',
                'sticker_content' => $stickerContent,
                'source_content' => $sourceContent,
                'source_file_path' => $sourceFilePath,
                'compiled_content' => $compiledContent,
                'compiled_file_path' => $compiledFilePath
            ];

            if ($isAjax) {
                // 渲染详情内容并返回JSON
                $html = $this->fetchView('Weline_Sticker::Backend/Sticker/detail-content.phtml', $data);
                return json_encode([
                    'success' => true,
                    'html' => $html,
                    'title' => __('Sticker详情') . ': ' . $targetModule . ' -> ' . $sourceModule
                ]);
            }

            // 非AJAX请求，分配数据并渲染完整页面
            foreach ($data as $key => $value) {
                $this->assign($key, $value);
            }

            return $this->fetch();
        } catch (\Exception $e) {
            $message = __('加载Sticker详情失败：%{1}', [$e->getMessage()]);
            if ($this->request->getGet('isAjax', false)) {
                return json_encode(['success' => false, 'message' => $message]);
            }
            $this->getMessageManager()->addError($message);
            return $this->redirect('*/backend/sticker/index');
        }
    }

    /**
     * 刷新Sticker注册表
     * 
     * @return string
     */
    #[AclAttribute('Weline_Sticker::sticker_refresh', '刷新Sticker注册表', 'mdi-refresh', '刷新Sticker注册表')]
    public function refresh(): string
    {
        try {
            /** @var StickerDataService $service */
            $service = $this->getStickerDataService();
            
            $result = $service->refreshRegistry();
            
            if ($result['success']) {
                $this->getMessageManager()->addSuccess($result['message']);
            } else {
                $this->getMessageManager()->addError($result['message']);
            }
            
            return $this->redirect('*/backend/sticker/index');
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('刷新Sticker注册表失败：%{1}', [$e->getMessage()]));
            return $this->redirect('*/backend/sticker/index');
        }
    }

    /**
     * AJAX搜索Sticker
     * 
     * @return string
     */
    public function searchAjax(): string
    {
        try {
            $search = trim($this->request->getGet('search', ''));
            $targetModule = trim($this->request->getGet('target_module', ''));
            $sourceModule = trim($this->request->getGet('source_module', ''));
            $searchType = trim($this->request->getGet('search_type', 'all'));
            $sortBy = $this->request->getGet('sort', 'target_module');
            $sortOrder = $this->request->getGet('order', 'asc');
            
            /** @var StickerDataService $service */
            $service = $this->getStickerDataService();
            
            // 获取所有Sticker数据
            if (!empty($search)) {
                $stickers = $service->searchStickers($search, $searchType);
            } else {
                $stickers = $service->getAllStickers();
            }
            
            // 应用模块筛选
            if (!empty($targetModule)) {
                $stickers = array_filter($stickers, function($sticker) use ($targetModule) {
                    return $sticker['target_module'] === $targetModule;
                });
            }
            
            if (!empty($sourceModule)) {
                $stickers = array_filter($stickers, function($sticker) use ($sourceModule) {
                    return $sticker['source_module'] === $sourceModule;
                });
            }
            
            // 排序
            usort($stickers, function($a, $b) use ($sortBy, $sortOrder) {
                $valueA = $a[$sortBy] ?? '';
                $valueB = $b[$sortBy] ?? '';
                
                if ($sortBy === 'is_active' || $sortBy === 'has_conflict') {
                    $valueA = $valueA ? 1 : 0;
                    $valueB = $valueB ? 1 : 0;
                }
                
                if ($sortOrder === 'asc') {
                    return $valueA <=> $valueB;
                } else {
                    return $valueB <=> $valueA;
                }
            });
            
            // 获取统计信息
            $stats = $service->getStickerStats();
            
            // 渲染列表HTML
            $data = [
                'stickers' => $stickers,
                'total' => count($stickers),
                'stats' => $stats,
                'search' => $search,
                'search_type' => $searchType,
                'target_module' => $targetModule,
                'source_module' => $sourceModule,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder
            ];
            
            $html = $this->fetchView('Weline_Sticker::Backend/Sticker/list-table.phtml', $data);
            
            return json_encode([
                'success' => true,
                'html' => $html,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            return json_encode([
                'success' => false,
                'message' => __('搜索失败：%{1}', [$e->getMessage()])
            ]);
        }
    }
}

