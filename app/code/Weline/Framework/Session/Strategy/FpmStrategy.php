<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Strategy;

use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\Storage\SessionStorageInterface;

/**
 * FPM 策略实现
 *
 * 适用于传统 PHP-FPM 模式，使用 PHP 原生 session_*() 函数。
 * 利用 PHP 内置的 Session 处理机制，包括文件锁、Cookie 管理等。
 */
final class FpmStrategy implements SessionStrategyInterface
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

    /** Cookie SameSite (fallback; final value resolves at Set-Cookie time) */
    private string $cookieSameSite;

    /** Explicit session.cookie_samesite from env (empty = auto) */
    private string $configuredCookieSameSite;

    /** Explicit session.cookie_partitioned from env (null = auto) */
    private mixed $configuredCookiePartitioned;

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
        $this->cookieSecure = (bool)($config['cookie_secure'] ?? (\w_env('server.https') === 'on'));
        $this->cookieHttpOnly = (bool)($config['cookie_httponly'] ?? true);
        $this->configuredCookieSameSite = \trim((string)($config['cookie_samesite'] ?? ''));
        $this->configuredCookiePartitioned = $config['cookie_partitioned'] ?? null;
        $this->cookieSameSite = $this->configuredCookieSameSite !== '' ? $this->configuredCookieSameSite : 'Lax';
    }

    /**
     * @inheritDoc
     */
    public function supports(): bool
    {
        if (PHP_SAPI === 'cli') {
            if (\class_exists('Weline\\Framework\\Runtime\\Runtime', false)) {
                return !\Weline\Framework\Runtime\Runtime::isPersistent();
            }
            return true;
        }
        
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getPriority(): int
    {
        return 10;
    }

    /**
     * @inheritDoc
     */
    public function initialize(?string $sessionId, array &$data): string
    {
        if (\headers_sent()) {
            $currentId = \session_id();
            if ($currentId !== '' && $currentId !== false) {
                $data = $_SESSION ?? [];
                $this->syncToFrameworkSession($data);
                return $currentId;
            }
            return '';
        }

        // 会话已激活时，不能直接调用 session_name()，否则会触发 warning。
        // - 相同会话：直接复用
        // - 目标会话不同：先关闭当前会话，后续再切换
        if (\session_status() === PHP_SESSION_ACTIVE) {
            $currentId = \session_id();
            if ($sessionId === null || $sessionId === '' || $currentId === $sessionId) {
                $data = $_SESSION ?? [];
                $this->syncToFrameworkSession($data);
                return $currentId ?: '';
            }
            \session_write_close();
        }

        \session_name(\Weline\Framework\Session\SessionCookieNameResolver::resolve());

        if ($sessionId !== null && $sessionId !== '') {
            if (\session_status() === PHP_SESSION_ACTIVE && \session_id() !== $sessionId) {
                \session_write_close();
            }
            if (\session_status() !== PHP_SESSION_ACTIVE) {
                \session_id($sessionId);
            }
        }

        if (\session_status() !== PHP_SESSION_ACTIVE) {
            \session_start();
        }

        $data = $_SESSION ?? [];
        $this->syncToFrameworkSession($data);
        
        return \session_id() ?: '';
    }

    /**
     * @inheritDoc
     */
    public function persist(string $sessionId, array $data, int $ttl): bool
    {
        if (\session_status() !== PHP_SESSION_ACTIVE) {
            return $this->storage->write($sessionId, $data, $ttl > 0 ? $ttl : $this->defaultTtl);
        }

        $_SESSION = $data;
        $this->syncToFrameworkSession($data);
        
        return true;
    }

    /**
     * @inheritDoc
     */
    public function writeClose(): void
    {
        if (\function_exists('session_write_close') && \session_status() === \PHP_SESSION_ACTIVE) {
            \session_write_close();
        }
    }

    /**
     * @inheritDoc
     */
    public function destroy(string $sessionId): bool
    {
        if (\session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            $this->clearFrameworkSession();
            
            if (\ini_get('session.use_cookies')) {
                $params = \session_get_cookie_params();
                \setcookie(
                    \Weline\Framework\Session\SessionCookieNameResolver::resolve(),
                    '',
                    \time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            
            \session_destroy();
        }

        return $this->storage->destroy($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function regenerate(string $oldSessionId, array $data, bool $deleteOld, int $ttl): string
    {
        if (\session_status() === PHP_SESSION_ACTIVE) {
            \session_regenerate_id($deleteOld);
            $newId = \session_id() ?: '';
            $_SESSION = $data;
            $this->syncToFrameworkSession($data);
            return $newId;
        }

        $newId = $this->generateSessionId();
        
        if ($deleteOld && $oldSessionId !== '') {
            $this->storage->destroy($oldSessionId);
        }

        $this->storage->write($newId, $data, $ttl > 0 ? $ttl : $this->defaultTtl);
        $this->syncToFrameworkSession($data);
        $this->setCookie($newId, $this->defaultTtl);
        
        return $newId;
    }

    /**
     * @inheritDoc
     */
    public function setCookie(string $sessionId, int $lifetime = 0): void
    {
        if (\headers_sent()) {
            return;
        }

        $expires = $lifetime > 0 ? \time() + $lifetime : 0;
        $secure = $this->cookieSecure || (\function_exists('w_env') && \w_env('server.https') === 'on');
        $sameSite = \Weline\Framework\Session\SessionCookieNameResolver::resolveSameSite(
            $secure,
            $this->configuredCookieSameSite !== '' ? $this->configuredCookieSameSite : null,
            $this->configuredCookiePartitioned,
        );

        // PHP setcookie() does not accept CHIPS "Partitioned"; emit Set-Cookie manually.
        if (\str_contains($sameSite, 'Partitioned')) {
            $parts = [
                \rawurlencode(\Weline\Framework\Session\SessionCookieNameResolver::resolve()) . '=' . \rawurlencode($sessionId),
            ];
            if ($expires > 0) {
                $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $expires);
            }
            if ($this->cookiePath !== '') {
                $parts[] = 'Path=' . $this->cookiePath;
            }
            if ($this->cookieDomain !== '') {
                $parts[] = 'Domain=' . $this->cookieDomain;
            }
            if ($secure) {
                $parts[] = 'Secure';
            }
            if ($this->cookieHttpOnly) {
                $parts[] = 'HttpOnly';
            }
            $parts[] = 'SameSite=None';
            $parts[] = 'Partitioned';
            \header('Set-Cookie: ' . \implode('; ', $parts), false);
            return;
        }
        
        \setcookie(
            \Weline\Framework\Session\SessionCookieNameResolver::resolve(),
            $sessionId,
            [
                'expires' => $expires,
                'path' => $this->cookiePath,
                'domain' => $this->cookieDomain,
                'secure' => $secure,
                'httponly' => $this->cookieHttpOnly,
                'samesite' => $sameSite,
            ]
        );
    }

    /**
     * 同步数据到框架 Session
     */
    private function syncToFrameworkSession(array $data): void
    {
        try {
            $session = SessionFactory::getInstance()->createSession();
            // During Session::start(), initialize() runs before the framework session is marked started.
            // Syncing back at that point re-enters start() and can recurse until memory is exhausted.
            if (!$session->isStarted()) {
                return;
            }
            foreach ($data as $key => $value) {
                $session->set($key, $value);
            }
        } catch (\Throwable $e) {
            // Session 未初始化时静默忽略，fallback 到 $_SESSION
        }
    }

    /**
     * 清空框架 Session
     */
    private function clearFrameworkSession(): void
    {
        try {
            $session = SessionFactory::getInstance()->createSession();
            $session->clear();
        } catch (\Throwable $e) {
            // Session 未初始化时静默忽略
        }
    }

    /**
     * 生成新的 Session ID
     */
    private function generateSessionId(): string
    {
        return \bin2hex(\random_bytes(16));
    }
}
