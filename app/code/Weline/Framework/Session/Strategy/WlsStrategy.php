<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Strategy;

use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Session\Storage\SessionStorageInterface;

/**
 * WLS 策略实现
 *
 * 适用于 WLS 常驻内存模式，不使用 PHP 原生 session_*() 函数。
 * 直接通过 SessionStorage 读写数据，通过 HeaderCollector 管理 Cookie。
 *
 * 特点：
 * - 避免 session_start() 带来的文件锁问题
 * - 支持跨 Worker 的 Session 共享
 * - 与 WLS 的 HeaderCollector 集成，正确处理响应头
 */
final class WlsStrategy implements SessionStrategyInterface
{
    /** Session 名称 */
    public const SESSION_NAME = 'WELINE_SESSID';

    /** 存储实例 */
    private SessionStorageInterface $storage;

    /** 配置 */
    private array $config;

    /** 默认 TTL */
    private int $defaultTtl;

    /** Cookie 路径 */
    private string $cookiePath;

    /** Cookie 域名 */
    private string $cookieDomain;

    /** Cookie 是否安全 */
    private bool $cookieSecure;

    /** Cookie HttpOnly */
    private bool $cookieHttpOnly;

    /** Cookie SameSite */
    private string $cookieSameSite;

    /** Cookie 生存时间（秒），0 表示浏览器会话 */
    private int $cookieLifetime;

    /**
     * 构造函数
     *
     * @param SessionStorageInterface $storage 存储实例
     * @param array $config 配置项
     */
    public function __construct(SessionStorageInterface $storage, array $config = [])
    {
        $this->storage = $storage;
        $this->config = $config;
        $this->defaultTtl = (int)($config['lifetime'] ?? $config['session_ttl'] ?? 3600);
        $this->cookiePath = $config['cookie_path'] ?? '/';
        $this->cookieDomain = $config['cookie_domain'] ?? '';
        $this->cookieSecure = (bool)($config['cookie_secure'] ?? (w_env('server.https') === 'on'));
        $this->cookieHttpOnly = (bool)($config['cookie_httponly'] ?? true);
        $this->cookieSameSite = $config['cookie_samesite'] ?? 'Lax';
        $this->cookieLifetime = (int)($config['cookie_lifetime'] ?? 86400 * 30);
    }

    /**
     * @inheritDoc
     */
    public function supports(): bool
    {
        if (\class_exists('Weline\\Framework\\Runtime\\Runtime', false)) {
            return \Weline\Framework\Runtime\Runtime::isPersistent();
        }
        
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return 100;
    }

    /**
     * @inheritDoc
     */
    public function initialize(?string $sessionId, array &$data): string
    {
        if ($sessionId === null || $sessionId === '') {
            $sessionId = \w_env_cookie(self::SESSION_NAME) ?? '';
        }

        if ($sessionId === '') {
            $sessionId = $this->generateSessionId();
            $data = [];
            $this->setCookie($sessionId, $this->cookieLifetime);
            return $sessionId;
        }

        $data = $this->storage->read($sessionId);

        return $sessionId;
    }

    /**
     * @inheritDoc
     */
    public function persist(string $sessionId, array $data, int $ttl): bool
    {
        return $this->storage->write($sessionId, $data, $ttl > 0 ? $ttl : $this->defaultTtl);
    }

    /**
     * @inheritDoc
     */
    public function destroy(string $sessionId): bool
    {
        $this->clearCookie();
        
        return $this->storage->destroy($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function regenerate(string $oldSessionId, array $data, bool $deleteOld, int $ttl): string
    {
        $newId = $this->generateSessionId();

        if ($deleteOld && $oldSessionId !== '') {
            $this->storage->destroy($oldSessionId);
        }

        $this->storage->write($newId, $data, $ttl > 0 ? $ttl : $this->defaultTtl);
        $this->setCookie($newId, $this->cookieLifetime);

        return $newId;
    }

    /**
     * @inheritDoc
     * WLS 下 persist 已直接写存储，无需额外关闭。
     */
    public function writeClose(): void
    {
    }

    /**
     * @inheritDoc
     */
    public function setCookie(string $sessionId, int $lifetime = 0): void
    {
        $headerCollector = HeaderCollector::getInstance();
        
        $expires = $lifetime > 0 ? \time() + $lifetime : 0;
        
        $headerCollector->setCookie(
            self::SESSION_NAME,
            $sessionId,
            $expires,
            $this->cookiePath,
            $this->cookieDomain,
            $this->cookieSecure,
            $this->cookieHttpOnly,
            $this->cookieSameSite
        );
    }

    /**
     * 清除 Session Cookie
     */
    private function clearCookie(): void
    {
        $headerCollector = HeaderCollector::getInstance();
        
        $headerCollector->setCookie(
            self::SESSION_NAME,
            '',
            \time() - 42000,
            $this->cookiePath,
            $this->cookieDomain,
            $this->cookieSecure,
            $this->cookieHttpOnly,
            $this->cookieSameSite
        );
    }

    /**
     * 生成新的 Session ID
     */
    private function generateSessionId(): string
    {
        return \bin2hex(\random_bytes(16));
    }
}
