<?php
declare(strict_types=1);

/**
 * Weline Server - Event 扩展事件驱动
 * 
 * 使用 PHP Event 扩展实现高性能事件循环
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Event;

/**
 * Event - 基于 Event 扩展的事件驱动（推荐）
 * 
 * 优点：
 * - 高性能（30,000-50,000 QPS）
 * - 支持大量并发连接
 * - 低 CPU 开销
 * 
 * 缺点：
 * - 需要安装 event 扩展（pecl install event）
 * - Windows 支持有限
 */
class Event implements EventInterface
{
    /**
     * Event base
     */
    protected ?\EventBase $eventBase = null;
    
    /**
     * 读事件
     * @var array<int, \Event>
     */
    protected array $readEvents = [];
    
    /**
     * 写事件
     * @var array<int, \Event>
     */
    protected array $writeEvents = [];
    
    /**
     * 信号事件
     * @var array<int, \Event>
     */
    protected array $signalEvents = [];
    
    /**
     * 定时器事件
     * @var array<int, \Event>
     */
    protected array $timerEvents = [];
    
    /**
     * 定时器 ID 计数器
     */
    protected int $timerId = 0;
    
    /**
     * 事件回调存储
     */
    protected array $eventCallbacks = [];
    
    /**
     * 构造函数
     */
    public function __construct()
    {
        if (!\class_exists('EventBase')) {
            throw new \RuntimeException(\__('需要安装 event 扩展'));
        }
        
        $this->eventBase = new \EventBase();
    }
    
    /**
     * @inheritDoc
     */
    public function add($fd, int $flag, callable $callback, array $args = []): int|bool
    {
        switch ($flag) {
            case self::EV_READ:
                return $this->addReadEvent($fd, $callback, $args);
                
            case self::EV_WRITE:
                return $this->addWriteEvent($fd, $callback, $args);
                
            case self::EV_SIGNAL:
                return $this->addSignalEvent((int)$fd, $callback, $args);
                
            case self::EV_TIMER:
                return $this->addTimerEvent((float)$fd, $callback, $args, true);
                
            case self::EV_TIMER_ONCE:
                return $this->addTimerEvent((float)$fd, $callback, $args, false);
                
            default:
                return false;
        }
    }
    
    /**
     * 添加读事件
     */
    protected function addReadEvent($fd, callable $callback, array $args): int|bool
    {
        $key = (int)$fd;
        
        // 如果已存在，先删除
        if (isset($this->readEvents[$key])) {
            $this->readEvents[$key]->del();
        }
        
        // 存储回调
        $this->eventCallbacks['read'][$key] = ['callback' => $callback, 'args' => $args];
        
        // 创建事件
        $event = new \Event($this->eventBase, $fd, \Event::READ | \Event::PERSIST, function ($fd) use ($key) {
            if (isset($this->eventCallbacks['read'][$key])) {
                $cb = $this->eventCallbacks['read'][$key];
                try {
                    ($cb['callback'])($fd, ...$cb['args']);
                } catch (\Throwable $e) {
                    \Weline\Server\Worker::log(\__('读事件处理错误：%{1}', [$e->getMessage()]));
                }
            }
        });
        
        if (!$event->add()) {
            return false;
        }
        
        $this->readEvents[$key] = $event;
        return $key;
    }
    
    /**
     * 添加写事件
     */
    protected function addWriteEvent($fd, callable $callback, array $args): int|bool
    {
        $key = (int)$fd;
        
        if (isset($this->writeEvents[$key])) {
            $this->writeEvents[$key]->del();
        }
        
        $this->eventCallbacks['write'][$key] = ['callback' => $callback, 'args' => $args];
        
        $event = new \Event($this->eventBase, $fd, \Event::WRITE | \Event::PERSIST, function ($fd) use ($key) {
            if (isset($this->eventCallbacks['write'][$key])) {
                $cb = $this->eventCallbacks['write'][$key];
                try {
                    ($cb['callback'])($fd, ...$cb['args']);
                } catch (\Throwable $e) {
                    \Weline\Server\Worker::log(\__('写事件处理错误：%{1}', [$e->getMessage()]));
                }
            }
        });
        
        if (!$event->add()) {
            return false;
        }
        
        $this->writeEvents[$key] = $event;
        return $key;
    }
    
    /**
     * 添加信号事件
     */
    protected function addSignalEvent(int $signal, callable $callback, array $args): int|bool
    {
        if (isset($this->signalEvents[$signal])) {
            $this->signalEvents[$signal]->del();
        }
        
        $this->eventCallbacks['signal'][$signal] = ['callback' => $callback, 'args' => $args];
        
        $event = new \Event($this->eventBase, $signal, \Event::SIGNAL | \Event::PERSIST, function () use ($signal) {
            if (isset($this->eventCallbacks['signal'][$signal])) {
                $cb = $this->eventCallbacks['signal'][$signal];
                try {
                    ($cb['callback'])(...$cb['args']);
                } catch (\Throwable $e) {
                    \Weline\Server\Worker::log(\__('信号处理错误：%{1}', [$e->getMessage()]));
                }
            }
        });
        
        if (!$event->add()) {
            return false;
        }
        
        $this->signalEvents[$signal] = $event;
        return $signal;
    }
    
    /**
     * 添加定时器事件
     */
    protected function addTimerEvent(float $interval, callable $callback, array $args, bool $persistent): int|bool
    {
        $timerId = ++$this->timerId;
        
        $this->eventCallbacks['timer'][$timerId] = [
            'callback' => $callback, 
            'args' => $args,
            'interval' => $interval,
            'persistent' => $persistent,
        ];
        
        $flags = $persistent ? \Event::TIMEOUT | \Event::PERSIST : \Event::TIMEOUT;
        
        $event = new \Event($this->eventBase, -1, $flags, function () use ($timerId) {
            if (isset($this->eventCallbacks['timer'][$timerId])) {
                $cb = $this->eventCallbacks['timer'][$timerId];
                try {
                    ($cb['callback'])(...$cb['args']);
                } catch (\Throwable $e) {
                    \Weline\Server\Worker::log(\__('定时器执行错误：%{1}', [$e->getMessage()]));
                }
                
                // 一次性定时器，执行后删除
                if (!$cb['persistent']) {
                    $this->del($timerId, self::EV_TIMER_ONCE);
                }
            }
        });
        
        if (!$event->add($interval)) {
            return false;
        }
        
        $this->timerEvents[$timerId] = $event;
        return $timerId;
    }
    
    /**
     * @inheritDoc
     */
    public function del($fd, int $flag): bool
    {
        switch ($flag) {
            case self::EV_READ:
                $key = (int)$fd;
                if (isset($this->readEvents[$key])) {
                    $this->readEvents[$key]->del();
                    unset($this->readEvents[$key]);
                    unset($this->eventCallbacks['read'][$key]);
                    return true;
                }
                return false;
                
            case self::EV_WRITE:
                $key = (int)$fd;
                if (isset($this->writeEvents[$key])) {
                    $this->writeEvents[$key]->del();
                    unset($this->writeEvents[$key]);
                    unset($this->eventCallbacks['write'][$key]);
                    return true;
                }
                return false;
                
            case self::EV_SIGNAL:
                $signal = (int)$fd;
                if (isset($this->signalEvents[$signal])) {
                    $this->signalEvents[$signal]->del();
                    unset($this->signalEvents[$signal]);
                    unset($this->eventCallbacks['signal'][$signal]);
                    return true;
                }
                return false;
                
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $timerId = (int)$fd;
                if (isset($this->timerEvents[$timerId])) {
                    $this->timerEvents[$timerId]->del();
                    unset($this->timerEvents[$timerId]);
                    unset($this->eventCallbacks['timer'][$timerId]);
                    return true;
                }
                return false;
                
            default:
                return false;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function loop(): void
    {
        $this->eventBase->loop();
    }
    
    /**
     * @inheritDoc
     */
    public function destroy(): void
    {
        // 删除所有事件
        foreach ($this->readEvents as $event) {
            $event->del();
        }
        foreach ($this->writeEvents as $event) {
            $event->del();
        }
        foreach ($this->signalEvents as $event) {
            $event->del();
        }
        foreach ($this->timerEvents as $event) {
            $event->del();
        }
        
        $this->readEvents = [];
        $this->writeEvents = [];
        $this->signalEvents = [];
        $this->timerEvents = [];
        $this->eventCallbacks = [];
        
        if ($this->eventBase) {
            $this->eventBase->exit();
        }
    }
    
    /**
     * @inheritDoc
     */
    public function clearAllTimer(): void
    {
        foreach ($this->timerEvents as $event) {
            $event->del();
        }
        $this->timerEvents = [];
        unset($this->eventCallbacks['timer']);
    }
    
    /**
     * @inheritDoc
     */
    public function getTimerCount(): int
    {
        return \count($this->timerEvents);
    }
    
    /**
     * 获取事件循环名称
     */
    public static function getName(): string
    {
        return 'event';
    }
    
    /**
     * 检查是否可用
     */
    public static function isAvailable(): bool
    {
        return \class_exists('EventBase');
    }
}
