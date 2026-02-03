<?php
declare(strict_types=1);

/**
 * Weline Server - Timer 定时器类
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server;

use Weline\Server\Event\EventInterface;

/**
 * Timer - 定时器管理
 * 
 * 用法示例：
 * ```php
 * // 添加一次性定时器（3秒后执行）
 * Timer::add(3, function() {
 *     echo "3秒到了\n";
 * }, [], false);
 * 
 * // 添加周期性定时器（每5秒执行一次）
 * $timerId = Timer::add(5, function() {
 *     echo "每5秒执行一次\n";
 * });
 * 
 * // 删除定时器
 * Timer::del($timerId);
 * ```
 */
class Timer
{
    /**
     * 定时器 ID 计数器
     */
    protected static int $idRecorder = 0;
    
    /**
     * 所有定时器
     * @var array
     */
    protected static array $timers = [];
    
    /**
     * 事件循环实例
     */
    protected static ?EventInterface $event = null;
    
    /**
     * 定时器状态
     */
    protected static array $status = [];
    
    /**
     * 初始化定时器
     */
    public static function init(?EventInterface $event = null): void
    {
        static::$event = $event;
    }
    
    /**
     * 添加定时器
     * 
     * @param float $interval 时间间隔（秒）
     * @param callable $callback 回调函数
     * @param array $args 回调参数
     * @param bool $persistent 是否为周期性定时器（默认为 true）
     * @return int|bool 定时器 ID 或 false
     */
    public static function add(float $interval, callable $callback, array $args = [], bool $persistent = true): int|bool
    {
        if ($interval <= 0) {
            Worker::log(\__('定时器间隔必须大于0'));
            return false;
        }
        
        // 如果事件循环支持定时器
        if (static::$event) {
            $flag = $persistent ? EventInterface::EV_TIMER : EventInterface::EV_TIMER_ONCE;
            $timerId = static::$event->add($interval, $flag, $callback, $args);
            
            if ($timerId !== false) {
                static::$timers[$timerId] = true;
            }
            
            return $timerId;
        }
        
        // 降级到 pcntl_alarm（仅支持整秒）
        if (!extension_loaded('pcntl')) {
            Worker::log(\__('定时器需要 pcntl 扩展或事件循环支持'));
            return false;
        }
        
        $timerId = ++static::$idRecorder;
        
        static::$timers[$timerId] = [
            'interval' => $interval,
            'callback' => $callback,
            'args' => $args,
            'persistent' => $persistent,
            'next_run' => microtime(true) + $interval,
        ];
        
        return $timerId;
    }
    
    /**
     * 删除定时器
     * 
     * @param int $timerId 定时器 ID
     * @return bool
     */
    public static function del(int $timerId): bool
    {
        if (!isset(static::$timers[$timerId])) {
            return false;
        }
        
        if (static::$event) {
            static::$event->del($timerId, EventInterface::EV_TIMER);
        }
        
        unset(static::$timers[$timerId]);
        
        return true;
    }
    
    /**
     * 删除所有定时器
     */
    public static function delAll(): void
    {
        if (static::$event) {
            static::$event->clearAllTimer();
        }
        
        static::$timers = [];
        static::$idRecorder = 0;
    }
    
    /**
     * 获取所有定时器
     */
    public static function getAll(): array
    {
        return static::$timers;
    }
    
    /**
     * 延迟执行（一次性定时器的便捷方法）
     * 
     * @param float $delay 延迟时间（秒）
     * @param callable $callback 回调函数
     * @param array $args 回调参数
     * @return int|bool
     */
    public static function after(float $delay, callable $callback, array $args = []): int|bool
    {
        return static::add($delay, $callback, $args, false);
    }
    
    /**
     * 睡眠（异步睡眠，不阻塞事件循环）
     * 
     * @param float $seconds 睡眠时间（秒）
     * @return void
     */
    public static function sleep(float $seconds): void
    {
        // 如果使用了协程，可以在这里实现真正的异步睡眠
        // 目前降级为同步睡眠
        usleep((int)($seconds * 1000000));
    }
}
