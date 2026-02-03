<?php
declare(strict_types=1);

/**
 * Weline Server - Select 事件驱动
 * 
 * 使用 stream_select 实现事件循环，纯 PHP 实现，无需扩展
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Event;

/**
 * Select - stream_select 事件驱动
 * 
 * 优点：
 * - 纯 PHP 实现，无需任何扩展
 * - 跨平台兼容（Windows/Linux/macOS）
 * 
 * 缺点：
 * - 性能相对较低（8,000-12,000 QPS）
 * - 文件描述符数量有限制（通常 1024）
 */
class Select implements EventInterface
{
    /**
     * 读事件回调
     * @var array
     */
    protected array $readEvents = [];
    
    /**
     * 写事件回调
     * @var array
     */
    protected array $writeEvents = [];
    
    /**
     * 信号事件回调
     * @var array
     */
    protected array $signalEvents = [];
    
    /**
     * 定时器事件
     * @var array
     */
    protected array $timers = [];
    
    /**
     * 定时器 ID 计数器
     */
    protected int $timerId = 0;
    
    /**
     * 是否运行中
     */
    protected bool $running = true;
    
    /**
     * select 超时时间（微秒）
     */
    protected int $selectTimeout = 100000000; // 100ms
    
    /**
     * 是否为 Windows 系统
     */
    protected bool $isWindows = false;
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
    
    /**
     * @inheritDoc
     */
    public function add($fd, int $flag, callable $callback, array $args = []): int|bool
    {
        switch ($flag) {
            case self::EV_READ:
                $key = (int) $fd;
                $this->readEvents[$key] = [
                    'fd' => $fd,
                    'callback' => $callback,
                    'args' => $args,
                ];
                return $key;
                
            case self::EV_WRITE:
                $key = (int) $fd;
                $this->writeEvents[$key] = [
                    'fd' => $fd,
                    'callback' => $callback,
                    'args' => $args,
                ];
                return $key;
                
            case self::EV_SIGNAL:
                if ($this->isWindows) {
                    return false;
                }
                $this->signalEvents[$fd] = [
                    'signal' => $fd,
                    'callback' => $callback,
                    'args' => $args,
                ];
                pcntl_signal($fd, [$this, 'signalHandler']);
                return $fd;
                
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $timerId = ++$this->timerId;
                $interval = (float) $fd;
                $persistent = $flag === self::EV_TIMER;
                
                $this->timers[$timerId] = [
                    'interval' => $interval,
                    'callback' => $callback,
                    'args' => $args,
                    'persistent' => $persistent,
                    'next_run' => microtime(true) + $interval,
                ];
                
                return $timerId;
                
            default:
                return false;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function del($fd, int $flag): bool
    {
        switch ($flag) {
            case self::EV_READ:
                $key = (int) $fd;
                if (isset($this->readEvents[$key])) {
                    unset($this->readEvents[$key]);
                    return true;
                }
                return false;
                
            case self::EV_WRITE:
                $key = (int) $fd;
                if (isset($this->writeEvents[$key])) {
                    unset($this->writeEvents[$key]);
                    return true;
                }
                return false;
                
            case self::EV_SIGNAL:
                if (isset($this->signalEvents[$fd])) {
                    unset($this->signalEvents[$fd]);
                    if (!$this->isWindows) {
                        pcntl_signal($fd, SIG_IGN);
                    }
                    return true;
                }
                return false;
                
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                if (isset($this->timers[$fd])) {
                    unset($this->timers[$fd]);
                    return true;
                }
                return false;
                
            default:
                return false;
        }
    }
    
    /**
     * 信号处理器
     */
    public function signalHandler(int $signal): void
    {
        if (isset($this->signalEvents[$signal])) {
            $event = $this->signalEvents[$signal];
            $callback = $event['callback'];
            $args = $event['args'];
            
            try {
                $callback(...$args);
            } catch (\Throwable $e) {
                \Weline\Server\Worker::log(\__('信号处理错误：%{1}', [$e->getMessage()]));
            }
        }
    }
    
    /**
     * @inheritDoc
     */
    public function loop(): void
    {
        $this->running = true;
        
        while ($this->running) {
            // 处理信号
            if (!$this->isWindows) {
                pcntl_signal_dispatch();
            }
            
            // 处理定时器
            $this->processTimers();
            
            // 准备 select 参数
            $read = [];
            $write = [];
            $except = null;
            
            foreach ($this->readEvents as $key => $event) {
                $fd = $event['fd'];
                if (is_resource($fd)) {
                    $read[$key] = $fd;
                }
            }
            
            foreach ($this->writeEvents as $key => $event) {
                $fd = $event['fd'];
                if (is_resource($fd)) {
                    $write[$key] = $fd;
                }
            }
            
            // 如果没有任何事件需要监听
            if (empty($read) && empty($write)) {
                // 如果有定时器，等待到下一个定时器
                if (!empty($this->timers)) {
                    $minTime = $this->getMinTimerTime();
                    if ($minTime > 0) {
                        usleep((int)($minTime * 1000000));
                    }
                } else {
                    // 没有任何事件，短暂睡眠避免 CPU 空转
                    usleep(10000); // 10ms
                }
                continue;
            }
            
            // 计算 select 超时
            $timeout = $this->calculateSelectTimeout();
            $tvSec = (int) $timeout;
            $tvUsec = (int) (($timeout - $tvSec) * 1000000);
            
            // 执行 select
            $ret = @stream_select($read, $write, $except, $tvSec, $tvUsec);
            
            if ($ret === false) {
                // 被信号中断
                continue;
            }
            
            // 处理读事件
            foreach ($read as $key => $fd) {
                if (isset($this->readEvents[$key])) {
                    $event = $this->readEvents[$key];
                    $callback = $event['callback'];
                    $args = $event['args'];
                    
                    try {
                        $callback($fd, ...$args);
                    } catch (\Throwable $e) {
                        \Weline\Server\Worker::log(\__('读事件处理错误：%{1}', [$e->getMessage()]));
                    }
                }
            }
            
            // 处理写事件
            foreach ($write as $key => $fd) {
                if (isset($this->writeEvents[$key])) {
                    $event = $this->writeEvents[$key];
                    $callback = $event['callback'];
                    $args = $event['args'];
                    
                    try {
                        $callback($fd, ...$args);
                    } catch (\Throwable $e) {
                        \Weline\Server\Worker::log(\__('写事件处理错误：%{1}', [$e->getMessage()]));
                    }
                }
            }
        }
    }
    
    /**
     * 处理定时器
     */
    protected function processTimers(): void
    {
        if (empty($this->timers)) {
            return;
        }
        
        $now = microtime(true);
        $toDelete = [];
        
        foreach ($this->timers as $id => $timer) {
            if ($timer['next_run'] <= $now) {
                // 执行回调
                try {
                    ($timer['callback'])(...$timer['args']);
                } catch (\Throwable $e) {
                    \Weline\Server\Worker::log(\__('定时器执行错误：%{1}', [$e->getMessage()]));
                }
                
                // 处理周期性定时器
                if ($timer['persistent']) {
                    $this->timers[$id]['next_run'] = $now + $timer['interval'];
                } else {
                    $toDelete[] = $id;
                }
            }
        }
        
        // 删除一次性定时器
        foreach ($toDelete as $id) {
            unset($this->timers[$id]);
        }
    }
    
    /**
     * 获取最小定时器时间
     */
    protected function getMinTimerTime(): float
    {
        if (empty($this->timers)) {
            return 1.0; // 默认 1 秒
        }
        
        $now = microtime(true);
        $min = PHP_FLOAT_MAX;
        
        foreach ($this->timers as $timer) {
            $remaining = $timer['next_run'] - $now;
            if ($remaining < $min) {
                $min = $remaining;
            }
        }
        
        return max(0, $min);
    }
    
    /**
     * 计算 select 超时
     */
    protected function calculateSelectTimeout(): float
    {
        if (empty($this->timers)) {
            return 1.0; // 默认 1 秒
        }
        
        $minTime = $this->getMinTimerTime();
        
        // 至少等待 1ms，避免 CPU 空转
        return max(0.001, min($minTime, 1.0));
    }
    
    /**
     * @inheritDoc
     */
    public function destroy(): void
    {
        $this->running = false;
        $this->readEvents = [];
        $this->writeEvents = [];
        $this->signalEvents = [];
        $this->timers = [];
    }
    
    /**
     * @inheritDoc
     */
    public function clearAllTimer(): void
    {
        $this->timers = [];
    }
    
    /**
     * @inheritDoc
     */
    public function getTimerCount(): int
    {
        return count($this->timers);
    }
}
