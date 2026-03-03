<?php

declare(strict_types=1);

/**
 * WLS 共享 Session 驱动
 *
 * 架构设计：
 * 1. 请求到达 → initSessionId() 一次性从后端读取全部 Session 数据
 * 2. 请求期间 → get() 直接返回本地缓存，set()/delete() 只修改本地缓存
 * 3. 请求结束 → close() 如果有修改，一次性写回后端
 * 4. 连接复用 → 后端连接由 SessionBackendFactory 进程级缓存，跨请求复用
 *
 * 优化效果：
 * - 每请求最多 2 次网络 IO（读 1 次 + 写 1 次）
 * - 无修改时只有 1 次读取，无写入
 * - 后端连接持久化，无频繁连接/断开
 *
 * @author Aiweline
 */

namespace Weline\Server\Extends\Module\Weline_Framework\Session;

use Weline\Framework\App\Env;
use Weline\Framework\Session\Driver\SessionDriverHandlerInterface;
use Weline\Framework\Session\Session;
use Weline\Server\Session\Backend\SessionBackendInterface;
use Weline\Server\Session\Backend\SessionBackendFactory;

class WlsSharedSession implements SessionDriverHandlerInterface
{
    /** Session 后端（进程级复用） */
    private SessionBackendInterface $backend;

    /** 
     * 当前请求的 Session ID（请求级状态）
     * 每个请求从 $_COOKIE 读取或新生成
     */
    private string $currentSessionId = '';

    /** 默认 Session 过期时间（秒） */
    private int $defaultLifetime = 3600;

    /** 
     * 是否已设置 Cookie 标志（请求级状态）
     * 避免同一请求内重复设置 Cookie
     */
    private bool $cookieSet = false;

    /** 
     * 本地 Session 数据缓存（请求级状态）
     * 减少网络请求，请求结束时随实例销毁
     */
    private array $localCache = [];

    /** 
     * 本地缓存是否有效（请求级状态）
     */
    private bool $localCacheValid = false;

    /** 配置 */
    private array $config;
    
    /** 
     * 降级模式标志
     * Session Server 不可用时启用，使用 localCache 提供有限服务
     */
    private bool $degradedMode = false;
    
    /** 降级模式下的待写入队列 */
    private array $pendingWrites = [];
    
    /**
     * 脏数据标志：本地缓存是否有未写入后端的变更
     * 请求结束时通过 close() 批量写入
     */
    private bool $dirty = false;
    
    /**
     * 变更的键列表（用于增量写入优化）
     */
    private array $changedKeys = [];

    /**
     * 构造函数
     *
     * @param array $config 配置项
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->defaultLifetime = (int)($config['lifetime'] ?? 3600);

        $this->backend = SessionBackendFactory::create($config);

        $this->initSessionId();
        
        \register_shutdown_function([$this, 'close']);
    }

    /**
     * 初始化 Session ID
     */
    private function initSessionId(): void
    {
        $sessionName = Session::session_name;

        if (isset($_COOKIE[$sessionName]) && !empty($_COOKIE[$sessionName])) {
            $this->currentSessionId = $_COOKIE[$sessionName];
            $this->loadSessionData();
        } else {
            $this->currentSessionId = $this->generateSessionId();
            $_SESSION = [];
            $this->localCache = [];
            $this->localCacheValid = true;
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
     * 从后端加载 Session 数据
     * 
     * 降级模式：如果后端不可用，使用空数据并标记降级
     */
    private function loadSessionData(): void
    {
        $sessionId = $this->currentSessionId;

        try {
            if (!$this->backend->isConnected() && !$this->backend->connect()) {
                $this->enterDegradedMode('Backend connection failed');
                $_SESSION = [];
                $this->localCache = [];
                $this->localCacheValid = true;
                return;
            }
            
            $data = $this->backend->getAll($sessionId);
            
            if ($this->degradedMode) {
                $this->exitDegradedMode();
            }
            
            $_SESSION = $data;
            $this->localCache = $data;
            $this->localCacheValid = true;
        } catch (\Throwable $e) {
            $this->enterDegradedMode('Backend error: ' . $e->getMessage());
            $_SESSION = [];
            $this->localCache = [];
            $this->localCacheValid = true;
        }
    }
    
    /**
     * 进入降级模式
     */
    private function enterDegradedMode(string $reason): void
    {
        if (!$this->degradedMode) {
            $this->degradedMode = true;
            w_log_warning("[WlsSharedSession] Entering degraded mode: {$reason}", [], 'wls_session');
        }
    }
    
    /**
     * 退出降级模式，尝试重放待写入队列
     */
    private function exitDegradedMode(): void
    {
        if (!$this->degradedMode) {
            return;
        }
        
        $this->degradedMode = false;
        w_log_warning('[WlsSharedSession] Exiting degraded mode, backend recovered', [], 'wls_session');
        
        $this->flushPendingWrites();
    }
    
    /**
     * 刷新待写入队列到后端
     */
    private function flushPendingWrites(): void
    {
        if (empty($this->pendingWrites)) {
            return;
        }
        
        $sessionId = $this->getSessionId();
        if (!$sessionId) {
            return;
        }
        
        foreach ($this->pendingWrites as $write) {
            try {
                if ($write['type'] === 'set') {
                    $this->backend->set($sessionId, $write['key'], $write['value'], $this->defaultLifetime);
                } elseif ($write['type'] === 'delete') {
                    $this->backend->delete($sessionId, $write['key']);
                }
            } catch (\Throwable $e) {
                w_log_error("[WlsSharedSession] Failed to flush pending write: " . $e->getMessage(), [], 'wls_session');
            }
        }
        
        $this->pendingWrites = [];
    }

    /**
     * 设置 Session Cookie
     */
    private function setSessionCookie(): void
    {
        if ($this->cookieSet) {
            return;
        }

        $sessionName = Session::session_name;
        $sessionId = $this->currentSessionId;
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

        $headerCollector = \Weline\Framework\Http\HeaderCollector::getInstance();
        $headerCollector->setCookie(
            $sessionName,
            $sessionId,
            \time() + 86400 * 30,
            '/',
            '',
            $isSecure,
            true,
            'Lax'
        );

        $this->cookieSet = true;
    }

    /**
     * 获取 Session ID
     */
    public function getSessionId(): string
    {
        return $this->currentSessionId;
    }

    /**
     * 设置 Session 值
     * 
     * 优化：只更新本地缓存，标记为脏数据。
     * 请求结束时通过 close() 批量写入后端，减少网络请求。
     */
    public function set($name, $value): bool
    {
        $_SESSION[$name] = $value;
        $this->localCache[$name] = $value;
        $this->dirty = true;
        $this->changedKeys[$name] = true;

        return true;
    }

    /**
     * 获取 Session 值
     * 
     * 直接从本地缓存返回，不再访问后端。
     * Session 数据在 initSessionId() 时已一次性加载。
     */
    public function get($name = null): mixed
    {
        if ($name === null) {
            return $this->localCache;
        }

        return $this->localCache[$name] ?? null;
    }

    /**
     * 删除 Session 值
     * 
     * 优化：只更新本地缓存，标记为脏数据。
     * 请求结束时通过 close() 批量写入后端。
     */
    public function delete($name): bool
    {
        unset($_SESSION[$name], $this->localCache[$name]);
        $this->dirty = true;
        unset($this->changedKeys[$name]);

        return true;
    }

    // ==================== SessionHandlerInterface 实现 ====================

    /**
     * @inheritDoc
     */
    public function open(string $path, string $name): bool
    {
        return $this->backend->connect();
    }

    /**
     * @inheritDoc
     * 
     * 请求结束时调用，批量写入所有变更到后端。
     * 优化：一次网络请求写入所有数据，减少 RTT。
     */
    public function close(): bool
    {
        if (!$this->dirty) {
            return true;
        }
        
        $sessionId = $this->getSessionId();
        if (!$sessionId) {
            return true;
        }
        
        if ($this->degradedMode) {
            return true;
        }
        
        try {
            $result = $this->backend->setAll($sessionId, $this->localCache, $this->defaultLifetime);
            if ($result) {
                $this->dirty = false;
                $this->changedKeys = [];
            }
            return $result;
        } catch (\Throwable $e) {
            $this->enterDegradedMode('Close/flush error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     * 
     * 注意：此方法是 SessionHandlerInterface 的实现，供 PHP session_* 函数使用。
     * 在 WLS 模式下，我们使用自定义的 get()/set() 方法，此方法不应被频繁调用。
     * 如果被调用，直接返回本地缓存的序列化数据。
     */
    public function read(string $id): string|false
    {
        if ($id === $this->currentSessionId && $this->localCacheValid) {
            return empty($this->localCache) ? '' : \serialize($this->localCache);
        }
        
        $data = $this->backend->getAll($id);
        if (empty($data)) {
            return '';
        }
        return \serialize($data);
    }

    /**
     * @inheritDoc
     * 
     * 注意：此方法是 SessionHandlerInterface 的实现，供 PHP session_* 函数使用。
     * 在 WLS 模式下，我们使用 close() 批量写入，此方法通常不被调用。
     * 如果是当前 Session 且已通过 close() 写入，则跳过。
     */
    public function write(string $id, string $data): bool
    {
        if ($id === $this->currentSessionId && !$this->dirty) {
            return true;
        }
        
        if ($data === '') {
            return true;
        }

        $sessionData = @\unserialize($data);
        if (!\is_array($sessionData)) {
            return false;
        }

        return $this->backend->setAll($id, $sessionData, $this->defaultLifetime);
    }

    /**
     * @inheritDoc
     */
    public function destroy(string $id): bool
    {
        $this->localCache = [];
        $this->localCacheValid = false;
        $_SESSION = [];

        return $this->backend->destroy($id);
    }

    /**
     * @inheritDoc
     */
    public function gc(int $max_lifetime): int|false
    {
        return $this->backend->gc($max_lifetime);
    }

    /**
     * 刷新本地缓存（强制从后端重新加载）
     */
    public function refresh(): void
    {
        $this->localCacheValid = false;
        $this->loadSessionData();
    }

    /**
     * 获取后端统计信息
     */
    public function getStats(): array
    {
        return $this->backend->getStats();
    }

    /**
     * 获取后端实例
     */
    public function getBackend(): SessionBackendInterface
    {
        return $this->backend;
    }
    
    /**
     * 重置请求级状态
     * 
     * 注意：此方法用于显式重置，但在正常使用中不需要调用。
     * WLS 模式下，SessionManager::$_session 在每请求后被 StateManager 清空，
     * 下个请求会创建新的 WlsSharedSession 实例。
     */
    public function reset(): void
    {
        $this->currentSessionId = '';
        $this->cookieSet = false;
        $this->localCache = [];
        $this->localCacheValid = false;
        $this->degradedMode = false;
        $this->pendingWrites = [];
        $this->dirty = false;
        $this->changedKeys = [];
        $_SESSION = [];
    }
    
    /**
     * 检查是否处于降级模式
     */
    public function isDegradedMode(): bool
    {
        return $this->degradedMode;
    }
    
    /**
     * 获取待写入队列（降级模式下积累的写入）
     */
    public function getPendingWrites(): array
    {
        return $this->pendingWrites;
    }
}
