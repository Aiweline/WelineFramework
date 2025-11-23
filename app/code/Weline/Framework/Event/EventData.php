<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

use Weline\Framework\App\Debug;
use Weline\Framework\Manager\ObjectManager;

/**
 * 事件数据静态查询类
 * 提供各种事件相关的查询功能，基于 generated/events.php 快速查询
 */
class EventData
{
    /**
     * 缓存的注册表数据
     */
    private static ?array $cachedRegistry = null;

    /**
     * 获取注册表数据（带缓存）
     *
     * @return array
     */
    private static function getRegistry(): array
    {
        if (self::$cachedRegistry === null) {
            /** @var EventRegistry $registry */
            $registry = ObjectManager::getInstance(EventRegistry::class);
            self::$cachedRegistry = $registry->getRegistry();
        }
        return self::$cachedRegistry;
    }

    /**
     * 获取事件列表
     *
     * @return array
     */
    public static function getEvents(): array
    {
        $registry = self::getRegistry();
        return $registry['events'] ?? [];
    }

    /**
     * 获取事件名到模块名的映射（快速查询）
     *
     * @return array 格式：['Weline_Admin::msg' => 'Weline_Admin', ...]
     */
    public static function getEventToModuleMap(): array
    {
        $registry = self::getRegistry();
        return $registry['event_to_module'] ?? [];
    }

    /**
     * 获取事件所属的模块名
     *
     * @param string $eventName 事件名
     * @return string|null 模块名，如果不存在返回 null
     */
    public static function getEventModule(string $eventName): ?string
    {
        /** @var EventRegistry $registry */
        $registry = ObjectManager::getInstance(EventRegistry::class);
        return $registry->getEventModule($eventName);
    }

    /**
     * 检查事件是否存在
     *
     * @param string $eventName 事件名
     * @return bool
     */
    public static function eventExists(string $eventName): bool
    {
        $events = self::getEvents();
        return isset($events[$eventName]);
    }

    /**
     * 检查事件是否有规约
     *
     * @param string $eventName 事件名
     * @return bool
     */
    public static function hasSpec(string $eventName): bool
    {
        /** @var EventRegistry $registry */
        $registry = ObjectManager::getInstance(EventRegistry::class);
        return $registry->hasSpec($eventName);
    }

    /**
     * 检查事件是否有文档
     *
     * @param string $eventName 事件名
     * @return bool
     */
    public static function hasDoc(string $eventName): bool
    {
        /** @var EventRegistry $registry */
        $registry = ObjectManager::getInstance(EventRegistry::class);
        return $registry->hasDoc($eventName);
    }

    /**
     * 获取事件信息
     *
     * @param string $eventName 事件名
     * @return array|null 事件信息，如果不存在返回 null
     */
    public static function getEventInfo(string $eventName): ?array
    {
        /** @var EventRegistry $registry */
        $registry = ObjectManager::getInstance(EventRegistry::class);
        return $registry->getEventInfo($eventName);
    }

    /**
     * 获取事件名称（显示名称）
     *
     * @param string $eventName 事件名
     * @return string
     */
    public static function getEventDisplayName(string $eventName): string
    {
        $eventInfo = self::getEventInfo($eventName);
        return $eventInfo['name'] ?? $eventName;
    }

    /**
     * 获取事件描述
     *
     * @param string $eventName 事件名
     * @return string
     */
    public static function getEventDescription(string $eventName): string
    {
        $eventInfo = self::getEventInfo($eventName);
        return $eventInfo['description'] ?? '';
    }

    /**
     * 获取事件文档路径
     *
     * @param string $eventName 事件名
     * @return string|null
     */
    public static function getEventDocPath(string $eventName): ?string
    {
        $eventInfo = self::getEventInfo($eventName);
        return $eventInfo['doc_path'] ?? null;
    }

    /**
     * 获取模块定义的所有事件
     *
     * @param string $moduleName 模块名
     * @return array 事件名列表
     */
    public static function getModuleEvents(string $moduleName): array
    {
        $eventToModule = self::getEventToModuleMap();
        $events = [];
        foreach ($eventToModule as $eventName => $module) {
            if ($module === $moduleName) {
                $events[] = $eventName;
            }
        }
        return $events;
    }

    /**
     * 获取所有模块列表（定义了事件的模块）
     *
     * @return array 模块名列表
     */
    public static function getModules(): array
    {
        $eventToModule = self::getEventToModuleMap();
        return array_values(array_unique($eventToModule));
    }

    /**
     * 获取事件的观察者（监听器）列表
     * 优先从 generated/events.php 文件中提取观察者信息
     * 如果是动态事件或未找到，则从 EventsManager 实时扫描
     *
     * @param string $eventName 事件名
     * @return array 观察者配置数组
     */
    public static function getEventObservers(string $eventName): array
    {
        $registry = self::getRegistry();
        $events = $registry['events'] ?? [];
        
        // 先检查精确匹配
        if (isset($events[$eventName]) && isset($events[$eventName]['observers'])) {
            return $events[$eventName]['observers'];
        }
        
        // 检查动态事件模式匹配
        $dynamicPatterns = $registry['dynamic_patterns'] ?? [];
        $isDynamicEvent = false;
        foreach ($dynamicPatterns as $pattern => $patternInfo) {
            if (self::matchDynamicEventPattern($pattern, $eventName)) {
                $isDynamicEvent = true;
                break;
            }
        }
        
        // 如果是动态事件或未在注册表中找到，从 EventsManager 实时扫描
        if ($isDynamicEvent || !isset($events[$eventName])) {
            /** @var EventsManager $eventsManager */
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            $allObservers = $eventsManager->scanEvents();
            return $allObservers[$eventName] ?? [];
        }
        
        // 事件存在但没有观察者
        return [];
    }

    /**
     * 匹配动态事件模式
     *
     * @param string $pattern 模式，如 "Framework_View::{position}"
     * @param string $eventName 实际事件名，如 "Framework_View::head"
     * @return bool
     */
    private static function matchDynamicEventPattern(string $pattern, string $eventName): bool
    {
        // 将模式转换为正则表达式
        $regex = preg_quote($pattern, '/');
        // 将转义后的 {变量名} 替换为正则表达式的 (.+)
        $regex = preg_replace('/\\\\\\{[^}]+\\\\\\}/', '(.+)', $regex);
        // 匹配整个字符串
        $regex = '/^' . $regex . '$/';
        
        return (bool) preg_match($regex, $eventName);
    }

    /**
     * 清除缓存（当注册表更新后调用）
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cachedRegistry = null;
    }
}

