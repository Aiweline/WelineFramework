<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Session\Storage\SessionStorageInterface;
use Weline\Framework\Session\Strategy\SessionStrategyInterface;

/**
 * Session 实现
 *
 * 遵循 SOLID 原则：
 * - SRP: 只负责数据存取和生命周期管理，不涉及认证逻辑
 * - OCP: 通过 Strategy 模式支持不同运行环境，无需修改此类
 * - DIP: 依赖 Storage 和 Strategy 抽象接口，而非具体实现
 *
 * 认证逻辑由独立的 AuthenticatedSession 类负责，通过组合此类实现。
 */
class Session implements SessionInterface
{
    /** 本请求内已 start 的 Session 实例（用于 shutdown 时统一 save + writeClose） */
    private static array $instancesForShutdown = [];

    /** 是否已注册 shutdown（只注册一次） */
    private static bool $shutdownRegistered = false;

    /** 存储实例 */
    private SessionStorageInterface $storage;

    /** 策略实例 */
    private SessionStrategyInterface $strategy;

    /** 当前 Session ID */
    private string $sessionId = '';

    /** Session 数据 */
    private array $data = [];

    /** 是否已启动 */
    private bool $started = false;

    /** 是否有未持久化的变更 */
    private bool $dirty = false;

    /** 默认 TTL（秒） */
    private int $defaultTtl;

    /**
     * 构造函数
     *
     * @param SessionStorageInterface $storage 存储实例
     * @param SessionStrategyInterface $strategy 策略实例
     * @param int $defaultTtl 默认 TTL（秒）
     */
    public function __construct(
        SessionStorageInterface $storage,
        SessionStrategyInterface $strategy,
        int $defaultTtl = 3600
    ) {
        $this->storage = $storage;
        $this->strategy = $strategy;
        $this->defaultTtl = $defaultTtl;
    }

    // ==================== SessionLifecycleInterface ====================

    /**
     * @inheritDoc
     */
    public function start(?string $sessionId = null): void
    {
        if ($this->started) {
            return;
        }

        $this->sessionId = $this->strategy->initialize($sessionId, $this->data);
        $this->started = true;
        $this->dirty = false;

        self::sessionLog('start', 'sid=' . ($this->sessionId !== '' ? substr($this->sessionId, 0, 8) . '...' : 'new'));

        self::$instancesForShutdown[$this->sessionId ?: spl_object_id($this)] = $this;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'flushOnShutdown']);
        }
    }

    /**
     * 请求结束前统一落盘并关闭，避免 302 等提前结束导致 Session 未写入
     */
    public static function flushOnShutdown(): void
    {
        self::flushRequestSessions();
    }

    /**
     * 请求结束时统一保存本请求内已启动的 Session。
     * 仅从队列移除已成功落库或无需保存的 Session，落库失败则保留以便下次 flush（如 302 前）重试。
     */
    public static function flushRequestSessions(): void
    {
        $count = count(self::$instancesForShutdown);
        if ($count > 0 && function_exists('w_log_info')) {
            w_log_info('[Session] flushRequestSessions count=' . $count, [], 'session');
        }
        $keep = [];
        foreach (self::$instancesForShutdown as $session) {
            if (!$session instanceof self) {
                continue;
            }
            $session->save();
            $session->getStrategy()->writeClose();
            if ($session->isDirty()) {
                $keep[] = $session;
            }
        }
        self::$instancesForShutdown = $keep;
    }

    /**
     * WLS 每请求重置：清空 shutdown 待落盘队列，避免跨请求残留引用。
     */
    public static function resetRequestState(): void
    {
        self::$instancesForShutdown = [];
    }

    /**
     * @inheritDoc
     */
    public function destroy(): void
    {
        if ($this->sessionId !== '') {
            self::sessionLog('destroy', 'sid=' . substr($this->sessionId, 0, 8) . '...');
            $this->strategy->destroy($this->sessionId);
        }

        $this->data = [];
        $this->sessionId = '';
        $this->started = false;
        $this->dirty = false;
    }

    /**
     * @inheritDoc
     */
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->ensureStarted();

        $oldId = $this->sessionId;
        $this->sessionId = $this->strategy->regenerate(
            $this->sessionId,
            $this->data,
            $deleteOldSession,
            $this->defaultTtl
        );
        self::sessionLog('regenerate', 'delete_old=' . ($deleteOldSession ? '1' : '0') . ' sid=' . substr($this->sessionId, 0, 8) . '...');
        $this->dirty = false;
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return $this->sessionId;
    }

    /**
     * @inheritDoc
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    // ==================== SessionDataInterface ====================

    /**
     * @inheritDoc
     */
    public function get(string $key): mixed
    {
        $this->ensureStarted();
        $value = $this->data[$key] ?? null;
        self::sessionLog('get', 'key=' . $key . ' found=' . (array_key_exists($key, $this->data) ? '1' : '0'));
        return $value;
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $this->data[$key] = $value;
        $this->dirty = true;
        $vType = gettype($value);
        $vLen = is_string($value) ? strlen($value) : (is_array($value) ? count($value) : null);
        self::sessionLog('set', 'key=' . $key . ' value_type=' . $vType . ($vLen !== null ? ' len=' . $vLen : ''));
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        
        return \array_key_exists($key, $this->data);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): void
    {
        $this->ensureStarted();
        if (\array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
            self::sessionLog('delete', 'key=' . $key);
        }
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        $this->ensureStarted();
        
        return $this->data;
    }

    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        $this->ensureStarted();
        
        $this->data = [];
        $this->dirty = true;
    }

    // ==================== 辅助方法 ====================

    /**
     * 确保 Session 已启动
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }

    /**
     * 落盘：将 dirty 数据写入 Storage。
     * 由 Response 终止时（sendResponse/redirect/noRouter）或 shutdown 统一调用，不在每次 set/delete 时调用。
     * 约定：302 与响应体发送前必须先 flush，且 Storage 必须在 write 返回前完成落库（同步持久化），
     * 以便下次请求（含 WLS 多 Worker）能读到 Session。
     */
    public function save(): void
    {
        if ($this->dirty && $this->sessionId !== '') {
            $ok = $this->strategy->persist($this->sessionId, $this->data, $this->defaultTtl);
            self::sessionLog('save', 'sid=' . substr($this->sessionId, 0, 8) . '... dirty=1 ok=' . ($ok ? '1' : '0'));
            if ($ok) {
                $this->dirty = false;
            } else {
                w_log_warning('[Session] 落库失败，sessionId=' . \substr($this->sessionId, 0, 8) . '...', [], 'session');
            }
        }
    }

    /**
     * 检查是否有未保存的变更
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * 获取存储实例
     */
    public function getStorage(): SessionStorageInterface
    {
        return $this->storage;
    }

    /**
     * 获取策略实例
     */
    public function getStrategy(): SessionStrategyInterface
    {
        return $this->strategy;
    }

    /**
     * 获取默认 TTL
     */
    public function getDefaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * 设置默认 TTL
     */
    public function setDefaultTtl(int $ttl): void
    {
        $this->defaultTtl = $ttl;
    }

    /**
     * 重置 Session 状态（WLS 模式下请求结束时调用）
     */
    public function reset(): void
    {
        $this->data = [];
        $this->sessionId = '';
        $this->started = false;
        $this->dirty = false;
    }

    /**
     * 写入 Session 操作日志到 var/log/session.log（开发/线上均记录，便于排查）
     */
    private static function sessionLog(string $op, string $detail): void
    {
        if (!function_exists('w_log_info')) {
            return;
        }
        w_log_info('[Session] ' . $op . ' ' . $detail, [], 'session');
    }

    // ==================== 兼容方法（过渡期使用） ====================

    /**
     * 兼容旧的 getData 方法
     *
     * @deprecated 使用 get() 或 all() 代替
     */
    public function getData(string $name = ''): mixed
    {
        if ($name === '') {
            return $this->all();
        }
        return $this->get($name);
    }

    /**
     * 兼容旧的 setData 方法
     *
     * @deprecated 使用 set() 代替
     */
    public function setData(string $name, mixed $value): static
    {
        $this->set($name, $value);
        return $this;
    }

    /**
     * 追加数据到指定键（字符串拼接）
     *
     * @deprecated 使用 append() 代替
     */
    public function addData(string $name, mixed $value): static
    {
        $this->ensureStarted();
        
        $existing = $this->data[$name] ?? '';
        $this->data[$name] = $existing . $value;
        $this->dirty = true;
        
        return $this;
    }

    /**
     * 追加数据到指定键（字符串拼接）
     */
    public function append(string $key, string $value): void
    {
        $this->ensureStarted();

        $existing = $this->data[$key] ?? '';
        $this->data[$key] = $existing . $value;
        $this->dirty = true;
    }

    // ==================== 认证兼容方法（原始 Session 无认证，返回默认值） ====================

    /**
     * @inheritDoc
     * 原始 Session 无认证上下文，恒返回 false
     */
    public function isLogin(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     * 原始 Session 不支持登录，应使用 AuthenticatedSessionInterface
     */
    public function login(AuthenticableInterface $user): void
    {
        // 无操作，认证由 AuthenticatedSession 负责
    }

    /**
     * @inheritDoc
     * 原始 Session 恒返回 null
     */
    public function getLoginUser(string $model = ''): ?AuthenticableInterface
    {
        return null;
    }

    /**
     * @inheritDoc
     * 原始 Session 恒返回 null
     */
    public function getLoginUsername(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     * 原始 Session 恒返回 null
     */
    public function getLoginUserID(): int|string|null
    {
        return null;
    }

    /**
     * @inheritDoc
     * 原始 Session 无操作
     */
    public function logout(): void
    {
        // 无操作
    }

    /**
     * @inheritDoc
     * 返回自身（原始 Session 即底层实例）
     */
    public function getOriginSession(): SessionInterface
    {
        return $this;
    }
}
