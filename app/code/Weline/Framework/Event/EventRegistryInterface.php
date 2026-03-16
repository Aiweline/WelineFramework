<?php

declare(strict_types=1);

namespace Weline\Framework\Event;

/**
 * 事件注册表抽象（SOLID - 依赖倒置）
 *
 * 框架层仅依赖本接口获取「事件是否有观察者」与「注册表/模式匹配」，
 * 不依赖具体 EventRegistry，便于测试、扩展与替换实现。
 *
 * 动态事件（如 {model_class}_model_save_before）的观察者判定：
 * - 精确事件：registry['events'][$eventName]['observers'] 非空即视为有观察者；
 * - 动态事件：某条 dynamic_patterns 匹配该事件名且该模式的 observers 非空即视为有观察者。
 */
interface EventRegistryInterface
{
    /**
     * 快速判断某事件是否至少有一个观察者（仅基于注册表，无副作用）。
     *
     * 用于性能敏感路径（如 dispatch 前）避免为无监听事件触发昂贵查找。
     */
    public function hasObservers(string $eventName): bool;

    /**
     * 获取注册表内容（events + dynamic_patterns 等）。
     *
     * @param bool $forceReload 是否强制重新加载
     * @return array{events: array, dynamic_patterns?: array, ...}
     */
    public function getRegistry(bool $forceReload = false): array;

    /**
     * 判断实际事件名是否匹配给定动态事件模式。
     *
     * @param string $pattern 模式，如 '{model_class}_model_save_before'
     * @param string $eventName 实际事件名，如 'Weline_Backend_Model_User_model_save_before'
     */
    public function matchPattern(string $pattern, string $eventName): bool;
}
