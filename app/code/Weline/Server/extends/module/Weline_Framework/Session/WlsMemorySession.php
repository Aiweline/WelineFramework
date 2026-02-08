<?php

declare(strict_types=1);

/*
 * WLS 模式进程内内存 Session 驱动
 * 
 * 通过 extends 衍生机制继承 Framework 的 File Session 驱动。
 * 在 WLS 常驻进程下用进程内存替代文件 I/O，大幅提升性能。
 * 
 * 原理：
 * - WLS 每个请求会传入 Session ID（通过 Cookie 或 Header）
 * - 首次读取时若内存无数据，回退到父类读文件并缓存到内存
 * - 写入时同时写内存和文件（保证重启后 Session 可恢复）
 * - 后续读取直接走内存
 * 
 * 注意：
 * - 跨 Worker 进程的 Session 通过文件持久化保持一致
 * - 同一 Worker 内的 Session 走内存，性能最佳
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Extends\Module\Weline_Framework\Session;

use Weline\Framework\Session\Driver\File;
use Weline\Framework\Session\Session;

class WlsMemorySession extends File
{
    /** 进程内 Session 缓存：session_id => session_data */
    private static array $memoryStore = [];
    
    /** 每个 Session 的过期时间：session_id => expire_timestamp */
    private static array $expiryMap = [];
    
    /** 默认 Session 过期时间（秒） */
    private int $defaultLifetime = 3600;
    
    /** 当前请求的 Session ID（WLS 模式下不依赖 session_id() 函数） */
    private string $currentSessionId = '';
    
    /** 是否已设置 Cookie 标志（避免重复设置） */
    private bool $cookieSet = false;

    /**
     * 构造函数
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
        
        // 读取配置的 lifetime
        $this->defaultLifetime = (int)($config['lifetime'] ?? 3600);
        
        // 初始化 Session ID（从 Cookie 获取或生成新的）
        $this->initSessionId();
    }
    
    /**
     * 初始化 Session ID
     * 
     * WLS 模式下不调用 session_start()，需要自行管理 Session ID
     */
    private function initSessionId(): void
    {
        // 尝试从 Cookie 获取 Session ID
        $sessionName = Session::session_name;
        if (isset($_COOKIE[$sessionName]) && !empty($_COOKIE[$sessionName])) {
            $this->currentSessionId = $_COOKIE[$sessionName];
            
            // 尝试从文件加载 Session 数据到内存
            $this->loadSessionData();
        } else {
            // 生成新的 Session ID
            $this->currentSessionId = $this->generateSessionId();
            
            // 初始化空的 Session 数据
            $_SESSION = [];
            self::$memoryStore[$this->currentSessionId] = [];
            self::$expiryMap[$this->currentSessionId] = \time() + $this->defaultLifetime;
            
            // 设置 Cookie
            $this->setSessionCookie();
        }
    }
    
    /**
     * 生成新的 Session ID
     */
    private function generateSessionId(): string
    {
        return \bin2hex(\random_bytes(16));
    }
    
    /**
     * 从文件加载 Session 数据到内存
     */
    private function loadSessionData(): void
    {
        $sessionId = $this->currentSessionId;
        
        // 如果内存中已有数据，直接使用
        if (isset(self::$memoryStore[$sessionId])) {
            // 检查过期
            $expiry = self::$expiryMap[$sessionId] ?? 0;
            if ($expiry > 0 && $expiry < \time()) {
                // 已过期，清理
                unset(self::$memoryStore[$sessionId], self::$expiryMap[$sessionId]);
            } else {
                $_SESSION = self::$memoryStore[$sessionId];
                return;
            }
        }
        
        // 从文件读取
        $data = parent::read($sessionId);
        if ($data !== false && $data !== '') {
            $sessionData = @\unserialize($data);
            if (\is_array($sessionData)) {
                $_SESSION = $sessionData;
                self::$memoryStore[$sessionId] = $sessionData;
                self::$expiryMap[$sessionId] = \time() + $this->defaultLifetime;
                return;
            }
        }
        
        // 没有数据，初始化空 Session
        $_SESSION = [];
        self::$memoryStore[$sessionId] = [];
        self::$expiryMap[$sessionId] = \time() + $this->defaultLifetime;
    }
    
    /**
     * 设置 Session Cookie
     * 
     * WLS 模式下 header() 无效（响应由 Worker 自行构建 HTTP 字符串），
     * 必须通过 HeaderCollector 收集 Cookie，由 Worker 在构建响应时包含。
     */
    private function setSessionCookie(): void
    {
        if ($this->cookieSet) {
            return;
        }
        
        $sessionName = Session::session_name;
        $sessionId = $this->currentSessionId;
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        
        // 通过 HeaderCollector 设置 Cookie（WLS Worker 会将其合并进 HTTP 响应）
        $headerCollector = \Weline\Framework\Http\HeaderCollector::getInstance();
        $headerCollector->setCookie(
            $sessionName,
            $sessionId,
            \time() + 86400 * 30, // 30 天
            '/',
            '',
            $isSecure,
            true,
            'Lax'
        );
        
        $this->cookieSet = true;
    }
    
    /**
     * 获取 Session ID（覆盖父类方法）
     * 
     * WLS 模式下不依赖 session_id() 函数
     */
    public function getSessionId(): string
    {
        return $this->currentSessionId;
    }

    /**
     * 设置 Session 值（内存 + 文件双写）
     */
    public function set($name, $value): bool
    {
        // 设置到 $_SESSION
        $_SESSION[$name] = $value;
        
        // 同步到内存缓存
        $sessionId = $this->getSessionId();
        if ($sessionId) {
            self::$memoryStore[$sessionId] = $_SESSION;
            self::$expiryMap[$sessionId] = \time() + $this->defaultLifetime;
            
            // 写入文件持久化（确保 Worker 重启后可恢复）
            $this->persistToFile($sessionId);
        }
        
        return true;
    }
    
    /**
     * 将 Session 数据持久化到文件
     */
    private function persistToFile(string $sessionId): void
    {
        $sessionData = self::$memoryStore[$sessionId] ?? $_SESSION ?? [];
        parent::write($sessionId, \serialize($sessionData));
    }

    /**
     * 获取 Session 值（优先内存）
     */
    public function get($name = null): mixed
    {
        $sessionId = $this->getSessionId();
        
        // 检查内存缓存
        if ($sessionId && isset(self::$memoryStore[$sessionId])) {
            // 检查过期
            $expiry = self::$expiryMap[$sessionId] ?? 0;
            if ($expiry > 0 && $expiry < \time()) {
                // 已过期，清理内存
                unset(self::$memoryStore[$sessionId], self::$expiryMap[$sessionId]);
            } else {
                // 内存命中
                $sessionData = self::$memoryStore[$sessionId];
                if ($name === null) {
                    return $sessionData;
                }
                return $sessionData[$name] ?? null;
            }
        }
        
        // 内存未命中，回退到父类
        $result = parent::get($name);
        
        // 缓存到内存
        if ($sessionId && isset($_SESSION)) {
            self::$memoryStore[$sessionId] = $_SESSION;
            self::$expiryMap[$sessionId] = \time() + $this->defaultLifetime;
        }
        
        return $result;
    }

    /**
     * 删除 Session 值（内存 + 文件同步删除）
     */
    public function delete($name): bool
    {
        $result = parent::delete($name);
        
        // 同步内存
        $sessionId = $this->getSessionId();
        if ($sessionId && isset(self::$memoryStore[$sessionId][$name])) {
            unset(self::$memoryStore[$sessionId][$name]);
        }
        
        return $result;
    }

    /**
     * 读取 Session 数据（SessionHandlerInterface）
     */
    public function read(string $id): string|false
    {
        // 检查内存
        if (isset(self::$memoryStore[$id])) {
            $expiry = self::$expiryMap[$id] ?? 0;
            if ($expiry === 0 || $expiry >= \time()) {
                return \serialize(self::$memoryStore[$id]);
            }
            // 已过期
            unset(self::$memoryStore[$id], self::$expiryMap[$id]);
        }
        
        // 回退文件
        $data = parent::read($id);
        if ($data !== false && $data !== '') {
            self::$memoryStore[$id] = \unserialize($data) ?: [];
            self::$expiryMap[$id] = \time() + $this->defaultLifetime;
        }
        return $data;
    }

    /**
     * 写入 Session 数据（SessionHandlerInterface）
     */
    public function write(string $id, string $session_data): bool
    {
        // 写内存
        self::$memoryStore[$id] = $session_data ? (\unserialize($session_data) ?: []) : [];
        self::$expiryMap[$id] = \time() + $this->defaultLifetime;
        
        // 写文件
        return parent::write($id, $session_data);
    }

    /**
     * 销毁 Session（内存 + 文件）
     */
    public function destroy(string $session_id): bool
    {
        unset(self::$memoryStore[$session_id], self::$expiryMap[$session_id]);
        return parent::destroy($session_id);
    }

    /**
     * 垃圾回收
     */
    public function gc(int $max_lifetime): int|false
    {
        // 清理内存中过期的 Session
        $now = \time();
        foreach (self::$expiryMap as $id => $expiry) {
            if ($expiry > 0 && $expiry < $now) {
                unset(self::$memoryStore[$id], self::$expiryMap[$id]);
            }
        }
        
        return parent::gc($max_lifetime);
    }

    /**
     * 清空所有内存 Session（用于进程重置）
     */
    public static function clearAllMemory(): void
    {
        self::$memoryStore = [];
        self::$expiryMap = [];
    }
    
    /**
     * 获取内存统计信息
     */
    public static function getMemoryStats(): array
    {
        return [
            'session_count' => \count(self::$memoryStore),
            'driver' => 'WlsMemorySession (extends File)',
        ];
    }
}
