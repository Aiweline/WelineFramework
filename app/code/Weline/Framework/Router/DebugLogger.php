<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router;

/**
 * DebugLogger - 路由调试日志管理类
 * 
 * 统一管理路由相关的调试日志，遵循单一职责原则。
 * 消除 Core.php 中重复的 agent_log 调用代码。
 * 
 * @since PHP 8.4
 */
class DebugLogger
{
    /**
     * 默认日志类别
     */
    private const DEFAULT_CATEGORY = 'router_debug';
    
    /**
     * 默认调用方键
     */
    private const DEFAULT_CALLER_KEY = 'H_route_exec';
    
    /**
     * 默认环境标签
     */
    private const DEFAULT_ENV_TAG = 'wls-debug';
    
    /**
     * 日志前缀
     */
    private const LOG_PREFIX = 'Router/Core.php';
    
    /**
     * 记录路由调试日志
     * 
     * @param string $location 日志位置（如 'route:got_controller'）
     * @param string $message 日志消息
     * @param array $context 上下文数据
     * @param string $category 日志类别（默认 'router_debug'）
     * @param string $callerKey 调用方键（默认 'H_route_exec'）
     * @param string $envTag 环境标签（默认 'wls-debug'）
     * @return void
     */
    public static function log(
        string $location,
        string $message,
        array $context = [],
        string $category = self::DEFAULT_CATEGORY,
        string $callerKey = self::DEFAULT_CALLER_KEY,
        string $envTag = self::DEFAULT_ENV_TAG
    ): void {
        if (function_exists('agent_log')) {
            agent_log(
                self::LOG_PREFIX . ':' . $location,
                $message,
                $context,
                $category,
                $callerKey,
                $envTag
            );
        }
    }
    
    /**
     * 记录控制器获取日志
     * 
     * @param mixed $dispatch 控制器实例
     * @param string $method 方法名
     * @return void
     */
    public static function logControllerFound(mixed $dispatch, string $method): void
    {
        self::log('route:got_controller', 'got controller class and method', [
            'dispatch' => $dispatch,
            'method' => $method,
        ]);
    }
    
    /**
     * 记录事件触发前日志
     * 
     * @param string $eventName 事件名称（'route_before' 或 'route_after'）
     * @param mixed $dispatch 控制器实例
     * @param string $method 方法名
     * @return void
     */
    public static function logBeforeEvent(string $eventName, mixed $dispatch = null, string $method = ''): void
    {
        $context = [];
        if ($dispatch !== null) {
            $context['dispatch'] = $dispatch;
        }
        if ($method !== '') {
            $context['method'] = $method;
        }
        self::log("route:before_{$eventName}", "before {$eventName} event", $context);
    }
    
    /**
     * 记录事件触发后日志
     * 
     * @param string $eventName 事件名称
     * @param mixed $dispatch 控制器实例
     * @param string $method 方法名
     * @return void
     */
    public static function logAfterEvent(string $eventName, mixed $dispatch = null, string $method = ''): void
    {
        $context = [];
        if ($dispatch !== null) {
            $context['dispatch'] = $dispatch;
        }
        if ($method !== '') {
            $context['method'] = $method;
        }
        self::log("route:after_{$eventName}", "after {$eventName} event", $context);
    }
    
    /**
     * 记录控制器实例创建日志
     * 
     * @param object $dispatch 控制器实例
     * @param string $method 方法名
     * @return void
     */
    public static function logControllerInstance(object $dispatch, string $method): void
    {
        self::log('route:controller_instance', 'controller instance created', [
            'dispatch_class' => \get_class($dispatch),
            'method' => $method,
        ]);
    }
    
    /**
     * 记录方法不存在日志
     * 
     * @param string $dispatchClass 控制器类名
     * @param string $method 方法名
     * @return void
     */
    public static function logMethodNotExists(string $dispatchClass, string $method): void
    {
        self::log('route:method_not_exists', 'method not exists', [
            'dispatch_class' => $dispatchClass,
            'method' => $method,
        ]);
    }
    
    /**
     * 记录方法调用前日志
     * 
     * @param object $dispatch 控制器实例
     * @param string $method 方法名
     * @return void
     */
    public static function logBeforeCall(object $dispatch, string $method): void
    {
        self::log('route:before_call', 'before call controller method', [
            'dispatch_class' => \get_class($dispatch),
            'method' => $method,
        ]);
    }
    
    /**
     * 记录函数调用前日志
     * 
     * @param object $dispatch 控制器实例
     * @param string $method 方法名
     * @return void
     */
    public static function logBeforeCallFunc(object $dispatch, string $method): void
    {
        self::log('route:before_call_func', 'before call_user_func', [
            'dispatch_class' => \get_class($dispatch),
            'method' => $method,
        ]);
    }
    
    /**
     * 记录函数调用后日志
     * 
     * @param object $dispatch 控制器实例
     * @param string $method 方法名
     * @param mixed $result 调用结果
     * @return void
     */
    public static function logAfterCallFunc(object $dispatch, string $method, mixed $result): void
    {
        self::log('route:after_call_func', 'after call_user_func', [
            'dispatch_class' => \get_class($dispatch),
            'method' => $method,
            'result_type' => \gettype($result),
        ]);
    }
    
    /**
     * 记录路由成功日志
     * 
     * @param string $fpcHtml FPC HTML 内容
     * @param mixed $result 路由结果
     * @return void
     */
    public static function logRouteSuccess(string $fpcHtml, mixed $result): void
    {
        self::log('route:success', 'route execution success', [
            'output_length' => \strlen($fpcHtml),
            'has_result' => !empty($result),
        ]);
    }
    
    /**
     * 记录异常日志
     * 
     * @param \Exception $e 异常对象
     * @return void
     */
    public static function logException(\Exception $e): void
    {
        self::log('route:catch', 'route execution exception', [
            'class' => \get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
    
    /**
     * 记录 Throwable 日志
     * 
     * @param \Throwable $e Throwable 对象
     * @return void
     */
    public static function logThrowable(\Throwable $e): void
    {
        self::log('route:catch_throwable', 'route execution throwable', [
            'class' => \get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
