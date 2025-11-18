<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Event\Service;

use Weline\Framework\Event\EventRegistry;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 事件数据服务
 * 提供事件信息的读取和统计功能
 */
class EventDataService
{
    private EventRegistry $eventRegistry;
    private EventsManager $eventsManager;

    public function __construct(
        EventRegistry $eventRegistry,
        EventsManager $eventsManager
    ) {
        $this->eventRegistry = $eventRegistry;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 获取所有事件信息（包含观察者信息）
     *
     * @return array
     */
    public function getAllEvents(): array
    {
        $events = $this->eventRegistry->getEvents();
        $result = [];

        foreach ($events as $eventName => $eventInfo) {
            // 获取观察者信息
            $observers = $this->eventsManager->getEventObservers($eventName);
            
            // 组织观察者信息，按模块分组
            $observersByModule = [];
            foreach ($observers as $observer) {
                $moduleName = $this->extractModuleFromInstance($observer['instance'] ?? '');
                if (!isset($observersByModule[$moduleName])) {
                    $observersByModule[$moduleName] = [];
                }
                $observersByModule[$moduleName][] = $observer;
            }

            $result[$eventName] = [
                'name' => $eventInfo['name'] ?? $eventName,
                'description' => $eventInfo['description'] ?? '',
                'doc' => $eventInfo['doc'] ?? '',
                'doc_path' => $eventInfo['doc_path'] ?? '',
                'has_spec' => $eventInfo['has_spec'] ?? false,
                'has_doc' => $eventInfo['has_doc'] ?? false,
                'module' => $eventInfo['module'] ?? '',
                'modules' => $eventInfo['modules'] ?? [],
                'observers' => $observers,
                'observers_by_module' => $observersByModule,
                'observer_count' => count($observers),
                'observer_modules' => array_keys($observersByModule)
            ];
        }

        return $result;
    }

    /**
     * 获取单个事件的详细信息
     *
     * @param string $eventName
     * @return array|null
     */
    public function getEventDetail(string $eventName): ?array
    {
        $eventInfo = $this->eventRegistry->getEventInfo($eventName);
        if (!$eventInfo) {
            return null;
        }

        // 获取观察者信息
        $observers = $this->eventsManager->getEventObservers($eventName);
        
        // 组织观察者信息，按模块分组
        $observersByModule = [];
        foreach ($observers as $observer) {
            $moduleName = $this->extractModuleFromInstance($observer['instance'] ?? '');
            if (!isset($observersByModule[$moduleName])) {
                $observersByModule[$moduleName] = [];
            }
            $observersByModule[$moduleName][] = $observer;
        }

        return [
            'name' => $eventInfo['name'] ?? $eventName,
            'description' => $eventInfo['description'] ?? '',
            'doc' => $eventInfo['doc'] ?? '',
            'doc_path' => $eventInfo['doc_path'] ?? '',
            'has_spec' => $eventInfo['has_spec'] ?? false,
            'has_doc' => $eventInfo['has_doc'] ?? false,
            'module' => $eventInfo['module'] ?? '',
            'modules' => $eventInfo['modules'] ?? [],
            'observers' => $observers,
            'observers_by_module' => $observersByModule,
            'observer_count' => count($observers),
            'observer_modules' => array_keys($observersByModule)
        ];
    }

    /**
     * 获取事件统计信息
     *
     * @return array
     */
    public function getEventStats(): array
    {
        $events = $this->getAllEvents();
        
        $stats = [
            'total_events' => count($events),
            'events_with_spec' => 0,
            'events_with_doc' => 0,
            'events_with_observers' => 0,
            'events_without_observers' => 0,
            'total_observers' => 0,
            'modules_with_events' => [],
            'modules_with_observers' => []
        ];

        foreach ($events as $eventName => $eventInfo) {
            // 统计有规约的事件
            if ($eventInfo['has_spec']) {
                $stats['events_with_spec']++;
            }
            
            // 统计有文档的事件
            if ($eventInfo['has_doc']) {
                $stats['events_with_doc']++;
            }
            
            // 统计观察者
            $observerCount = $eventInfo['observer_count'];
            $stats['total_observers'] += $observerCount;
            
            if ($observerCount > 0) {
                $stats['events_with_observers']++;
            } else {
                $stats['events_without_observers']++;
            }
            
            // 统计定义事件的模块
            $module = $eventInfo['module'] ?? '';
            if ($module) {
                if (!isset($stats['modules_with_events'][$module])) {
                    $stats['modules_with_events'][$module] = 0;
                }
                $stats['modules_with_events'][$module]++;
            }
            
            // 统计有观察者的模块
            foreach ($eventInfo['observer_modules'] as $observerModule) {
                if (!isset($stats['modules_with_observers'][$observerModule])) {
                    $stats['modules_with_observers'][$observerModule] = 0;
                }
                $stats['modules_with_observers'][$observerModule]++;
            }
        }

        return $stats;
    }

    /**
     * 搜索事件
     *
     * @param string $searchTerm
     * @param string $searchType all|name|description|module|observer
     * @return array
     */
    public function searchEvents(string $searchTerm, string $searchType = 'all'): array
    {
        $events = $this->getAllEvents();
        $results = [];

        foreach ($events as $eventName => $eventInfo) {
            $matched = false;
            $matchReasons = [];

            if (empty($searchTerm)) {
                $results[$eventName] = $eventInfo;
                continue;
            }

            // 按类型搜索
            if ($searchType === 'all' || $searchType === 'name') {
                if (stripos($eventName, $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '事件名';
                }
                if (stripos($eventInfo['name'] ?? '', $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '显示名';
                }
            }

            if ($searchType === 'all' || $searchType === 'description') {
                if (stripos($eventInfo['description'] ?? '', $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '描述';
                }
            }

            if ($searchType === 'all' || $searchType === 'module') {
                if (stripos($eventInfo['module'] ?? '', $searchTerm) !== false) {
                    $matched = true;
                    $matchReasons[] = '定义模块';
                }
                foreach ($eventInfo['observer_modules'] as $observerModule) {
                    if (stripos($observerModule, $searchTerm) !== false) {
                        $matched = true;
                        $matchReasons[] = '观察者模块';
                    }
                }
            }

            if ($searchType === 'all' || $searchType === 'observer') {
                foreach ($eventInfo['observers'] as $observer) {
                    $instance = $observer['instance'] ?? '';
                    $name = $observer['name'] ?? '';
                    if (stripos($instance, $searchTerm) !== false || 
                        stripos($name, $searchTerm) !== false) {
                        $matched = true;
                        $matchReasons[] = '观察者';
                    }
                }
            }

            if ($matched) {
                $eventInfo['match_reasons'] = array_unique($matchReasons);
                $results[$eventName] = $eventInfo;
            }
        }

        return $results;
    }

    /**
     * 按模块筛选事件
     *
     * @param string $moduleName 模块名
     * @return array
     */
    public function getEventsByModule(string $moduleName): array
    {
        $events = $this->getAllEvents();
        $results = [];

        foreach ($events as $eventName => $eventInfo) {
            // 检查是否是定义该事件的模块
            if (($eventInfo['module'] ?? '') === $moduleName) {
                $results[$eventName] = $eventInfo;
                continue;
            }

            // 检查是否有该模块的观察者
            if (in_array($moduleName, $eventInfo['observer_modules'] ?? [])) {
                $results[$eventName] = $eventInfo;
            }
        }

        return $results;
    }

    /**
     * 获取模块统计信息
     *
     * @param string $moduleName 模块名
     * @return array
     */
    public function getModuleStats(string $moduleName): array
    {
        $events = $this->getAllEvents();
        
        $stats = [
            'module' => $moduleName,
            'events_defined' => 0,
            'events_observed' => 0,
            'total_observers' => 0,
            'events_list' => []
        ];

        foreach ($events as $eventName => $eventInfo) {
            // 统计定义的事件
            if (($eventInfo['module'] ?? '') === $moduleName) {
                $stats['events_defined']++;
                $stats['events_list'][] = [
                    'event_name' => $eventName,
                    'type' => 'defined',
                    'observer_count' => $eventInfo['observer_count'] ?? 0
                ];
            }

            // 统计观察的事件
            if (in_array($moduleName, $eventInfo['observer_modules'] ?? [])) {
                $stats['events_observed']++;
                $observerCount = 0;
                foreach ($eventInfo['observers_by_module'][$moduleName] ?? [] as $observer) {
                    $observerCount++;
                }
                $stats['total_observers'] += $observerCount;
                $stats['events_list'][] = [
                    'event_name' => $eventName,
                    'type' => 'observed',
                    'observer_count' => $observerCount
                ];
            }
        }

        return $stats;
    }

    /**
     * 从实例类名中提取模块名
     *
     * @param string $instance
     * @return string
     */
    private function extractModuleFromInstance(string $instance): string
    {
        if (empty($instance)) {
            return '未知模块';
        }

        // 类名格式：Weline\ModuleName\Path\To\Class
        // 例如：Weline\Admin\Observer\SomeObserver -> Weline_Admin
        if (preg_match('/^Weline\\\\([^\\\\]+)/', $instance, $matches)) {
            $moduleName = $matches[1] ?? '';
            // 如果模块名已经是 Weline_ 格式，直接返回；否则添加前缀
            if (str_starts_with($moduleName, 'Weline_')) {
                return $moduleName;
            }
            return 'Weline_' . $moduleName;
        }

        return '未知模块';
    }
}

