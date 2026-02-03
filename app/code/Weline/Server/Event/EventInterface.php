<?php
declare(strict_types=1);

/**
 * Weline Server - Event 接口
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Event;

/**
 * EventInterface - 事件循环接口
 * 
 * 定义事件循环的基本操作，支持多种底层实现：
 * - Event 扩展（libevent）
 * - Ev 扩展（libev）
 * - Select（纯 PHP，无依赖）
 */
interface EventInterface
{
    /**
     * 读事件
     */
    public const EV_READ = 1;
    
    /**
     * 写事件
     */
    public const EV_WRITE = 2;
    
    /**
     * 信号事件
     */
    public const EV_SIGNAL = 4;
    
    /**
     * 定时器事件（周期性）
     */
    public const EV_TIMER = 8;
    
    /**
     * 定时器事件（一次性）
     */
    public const EV_TIMER_ONCE = 16;
    
    /**
     * 添加事件
     * 
     * @param mixed $fd 文件描述符/信号/时间间隔
     * @param int $flag 事件类型（EV_READ, EV_WRITE, EV_SIGNAL, EV_TIMER, EV_TIMER_ONCE）
     * @param callable $callback 回调函数
     * @param array $args 回调参数
     * @return int|bool 成功返回事件 ID，失败返回 false
     */
    public function add($fd, int $flag, callable $callback, array $args = []): int|bool;
    
    /**
     * 删除事件
     * 
     * @param mixed $fd 文件描述符/事件 ID
     * @param int $flag 事件类型
     * @return bool
     */
    public function del($fd, int $flag): bool;
    
    /**
     * 运行事件循环
     */
    public function loop(): void;
    
    /**
     * 销毁事件循环
     */
    public function destroy(): void;
    
    /**
     * 清除所有定时器
     */
    public function clearAllTimer(): void;
    
    /**
     * 获取定时器数量
     */
    public function getTimerCount(): int;
}
