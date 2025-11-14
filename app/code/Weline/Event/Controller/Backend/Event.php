<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Event\Controller\Backend;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Event\Service\EventDataService;

/**
 * 事件管理控制器
 */
class Event extends BaseController
{
    /**
     * 事件列表页面
     */
    public function index()
    {
        try {
            /** @var EventDataService $service */
            $service = ObjectManager::getInstance(EventDataService::class);
            
            // 获取筛选参数
            $filterModule = trim($this->request->getGet('module', ''));
            $filterSpec = $this->request->getGet('spec', '');
            $filterDoc = $this->request->getGet('doc', '');
            $filterObserver = $this->request->getGet('observer', '');
            $sortBy = $this->request->getGet('sort', 'name'); // name, module, observers
            $sortOrder = $this->request->getGet('order', 'asc'); // asc, desc
            $quickSearch = trim($this->request->getGet('q', ''));
            
            // 获取所有事件数据
            $allEvents = $service->getAllEvents();
            
            // 应用筛选
            $filteredEvents = $allEvents;
            
            // 快速搜索
            if (!empty($quickSearch)) {
                $filteredEvents = $service->searchEvents($quickSearch, 'all');
            }
            
            // 模块筛选
            if (!empty($filterModule)) {
                $moduleEvents = $service->getEventsByModule($filterModule);
                $filteredEvents = array_intersect_key($filteredEvents, $moduleEvents);
            }
            
            // 规约筛选
            if ($filterSpec !== '') {
                $filteredEvents = array_filter($filteredEvents, function($event) use ($filterSpec) {
                    $hasSpec = $event['has_spec'] ?? false;
                    return ($filterSpec === '1' && $hasSpec) || ($filterSpec === '0' && !$hasSpec);
                });
            }
            
            // 文档筛选
            if ($filterDoc !== '') {
                $filteredEvents = array_filter($filteredEvents, function($event) use ($filterDoc) {
                    $hasDoc = $event['has_doc'] ?? false;
                    return ($filterDoc === '1' && $hasDoc) || ($filterDoc === '0' && !$hasDoc);
                });
            }
            
            // 观察者筛选
            if ($filterObserver !== '') {
                $filteredEvents = array_filter($filteredEvents, function($event) use ($filterObserver) {
                    $observerCount = $event['observer_count'] ?? 0;
                    if ($filterObserver === '1') {
                        return $observerCount > 0;
                    } elseif ($filterObserver === '0') {
                        return $observerCount === 0;
                    }
                    return true;
                });
            }
            
            // 排序
            if ($sortBy === 'name') {
                uksort($filteredEvents, function($a, $b) use ($sortOrder) {
                    $result = strcasecmp($a, $b);
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            } elseif ($sortBy === 'module') {
                uasort($filteredEvents, function($a, $b) use ($sortOrder) {
                    $result = strcasecmp($a['module'] ?? '', $b['module'] ?? '');
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            } elseif ($sortBy === 'observers') {
                uasort($filteredEvents, function($a, $b) use ($sortOrder) {
                    $countA = $a['observer_count'] ?? 0;
                    $countB = $b['observer_count'] ?? 0;
                    $result = $countA <=> $countB;
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            }
            
            // 获取所有模块列表（用于筛选下拉框）
            $allModules = [];
            foreach ($allEvents as $event) {
                $module = $event['module'] ?? '';
                if ($module && !in_array($module, $allModules)) {
                    $allModules[] = $module;
                }
                foreach ($event['observer_modules'] ?? [] as $observerModule) {
                    if ($observerModule && !in_array($observerModule, $allModules)) {
                        $allModules[] = $observerModule;
                    }
                }
            }
            sort($allModules);
            
            // 获取统计信息
            $stats = $service->getEventStats();
            
            $this->assign('events', $filteredEvents);
            $this->assign('stats', $stats);
            $this->assign('all_modules', $allModules);
            $this->assign('filter_module', $filterModule);
            $this->assign('filter_spec', $filterSpec);
            $this->assign('filter_doc', $filterDoc);
            $this->assign('filter_observer', $filterObserver);
            $this->assign('sort_by', $sortBy);
            $this->assign('sort_order', $sortOrder);
            $this->assign('quick_search', $quickSearch);
            $this->assign('title', __('事件管理'));
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载事件列表失败: %{1}', $e->getMessage()));
            $this->assign('events', []);
            $this->assign('stats', []);
            $this->assign('all_modules', []);
            $this->assign('title', __('事件管理'));
            return $this->fetch();
        }
    }

    /**
     * 事件详情 API（用于 offcanvas AJAX 加载）
     */
    public function detail()
    {
        try {
            // 只支持 AJAX 请求
            if (!$this->request->isAjax()) {
                $this->redirect('*/index');
                return;
            }
            
            $eventName = $this->request->getParam('event');
            if (empty($eventName)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('请指定事件名')], JSON_UNESCAPED_UNICODE);
                return;
            }

            /** @var EventDataService $service */
            $service = ObjectManager::getInstance(EventDataService::class);
            
            // 获取事件详情
            $eventDetail = $service->getEventDetail($eventName);
            
            if (!$eventDetail) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => __('事件不存在: %{1}', $eventName)], JSON_UNESCAPED_UNICODE);
                return;
            }
            
            // 返回 Block 渲染的内容
            /** @var \Weline\Event\Block\Backend\Event\Detail $detailBlock */
            $detailBlock = ObjectManager::make(\Weline\Event\Block\Backend\Event\Detail::class, [
                'data' => [
                    'event' => $eventDetail,
                    'event_name' => $eventName
                ]
            ]);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'html' => $detailBlock->render(),
                'title' => __('事件详情') . ': ' . ($eventDetail['name'] ?? $eventName)
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            $errorMessage = $e->getMessage() ?: $e->getTraceAsString();
            echo json_encode([
                'success' => false, 
                'message' => __('加载事件详情失败: %{1}', $errorMessage),
                'error' => $errorMessage,
                'trace' => DEV ? $e->getTraceAsString() : ''
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * 事件搜索页面
     */
    public function getSearch()
    {
        try {
            $searchTerm = trim($this->request->getGet('q', ''));
            $searchType = $this->request->getGet('type', 'all'); // all, name, description, module, observer
            
            /** @var EventDataService $service */
            $service = ObjectManager::getInstance(EventDataService::class);
            
            $results = [];
            if (!empty($searchTerm)) {
                $results = $service->searchEvents($searchTerm, $searchType);
            }
            
            $this->assign('search_term', $searchTerm);
            $this->assign('search_type', $searchType);
            $this->assign('results', $results);
            $this->assign('title', __('事件搜索'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('搜索失败: %{1}', $e->getMessage()));
            $this->redirect('*/index');
        }
    }

    /**
     * 刷新事件注册表
     */
    public function refresh()
    {
        try {
            /** @var \Weline\Framework\Event\EventRegistry $registry */
            $registry = ObjectManager::getInstance(\Weline\Framework\Event\EventRegistry::class);
            $result = $registry->refresh();

            if ($result) {
                $this->getMessageManager()->addSuccess(__('事件注册表刷新成功'));
            } else {
                $this->getMessageManager()->addError(__('事件注册表刷新失败'));
            }
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('刷新失败: %{1}', $e->getMessage()));
        }

        $this->redirect('*/index');
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

            /** @var EventDataService $service */
            $service = ObjectManager::getInstance(EventDataService::class);
            
            // 获取筛选参数
            $filterModule = trim($this->request->getGet('module', ''));
            $filterSpec = $this->request->getGet('spec', '');
            $filterDoc = $this->request->getGet('doc', '');
            $filterObserver = $this->request->getGet('observer', '');
            $sortBy = $this->request->getGet('sort', 'name');
            $sortOrder = $this->request->getGet('order', 'asc');
            $quickSearch = trim($this->request->getGet('q', ''));
            
            // 获取所有事件数据
            $allEvents = $service->getAllEvents();
            
            // 应用筛选
            $filteredEvents = $allEvents;
            
            // 快速搜索
            if (!empty($quickSearch)) {
                $filteredEvents = $service->searchEvents($quickSearch, 'all');
            }
            
            // 模块筛选
            if (!empty($filterModule)) {
                $moduleEvents = $service->getEventsByModule($filterModule);
                $filteredEvents = array_intersect_key($filteredEvents, $moduleEvents);
            }
            
            // 规约筛选
            if ($filterSpec !== '') {
                $filteredEvents = array_filter($filteredEvents, function($event) use ($filterSpec) {
                    $hasSpec = $event['has_spec'] ?? false;
                    return ($filterSpec === '1' && $hasSpec) || ($filterSpec === '0' && !$hasSpec);
                });
            }
            
            // 文档筛选
            if ($filterDoc !== '') {
                $filteredEvents = array_filter($filteredEvents, function($event) use ($filterDoc) {
                    $hasDoc = $event['has_doc'] ?? false;
                    return ($filterDoc === '1' && $hasDoc) || ($filterDoc === '0' && !$hasDoc);
                });
            }
            
            // 观察者筛选
            if ($filterObserver !== '') {
                $filteredEvents = array_filter($filteredEvents, function($event) use ($filterObserver) {
                    $observerCount = $event['observer_count'] ?? 0;
                    if ($filterObserver === '1') {
                        return $observerCount > 0;
                    } elseif ($filterObserver === '0') {
                        return $observerCount === 0;
                    }
                    return true;
                });
            }
            
            // 排序
            if ($sortBy === 'name') {
                uksort($filteredEvents, function($a, $b) use ($sortOrder) {
                    $result = strcasecmp($a, $b);
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            } elseif ($sortBy === 'module') {
                uasort($filteredEvents, function($a, $b) use ($sortOrder) {
                    $result = strcasecmp($a['module'] ?? '', $b['module'] ?? '');
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            } elseif ($sortBy === 'observers') {
                uasort($filteredEvents, function($a, $b) use ($sortOrder) {
                    $countA = $a['observer_count'] ?? 0;
                    $countB = $b['observer_count'] ?? 0;
                    $result = $countA <=> $countB;
                    return $sortOrder === 'desc' ? -$result : $result;
                });
            }
            
            // 获取统计信息
            $stats = $service->getEventStats();
            
            // 渲染列表 HTML
            $this->assign('events', $filteredEvents);
            $this->assign('stats', $stats);
            $this->assign('filter_module', $filterModule);
            $this->assign('filter_spec', $filterSpec);
            $this->assign('filter_doc', $filterDoc);
            $this->assign('filter_observer', $filterObserver);
            $this->assign('sort_by', $sortBy);
            $this->assign('sort_order', $sortOrder);
            $this->assign('quick_search', $quickSearch);
            
            // 返回 JSON 数据
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'events' => $filteredEvents,
                'stats' => $stats,
                'total_count' => count($filteredEvents),
                'html' => $this->fetch('Backend/Event/list_table')
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

