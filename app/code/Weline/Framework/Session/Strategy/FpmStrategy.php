<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Strategy;

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

    /** Cookie SameSite */
    private string $cookieSameSite;

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
        $this->cookieSecure = (bool)($config['cookie_secure'] ?? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'));
        $this->cookieHttpOnly = (bool)($config['cookie_httponly'] ?? true);
        $this->cookieSameSite = $config['cookie_samesite'] ?? 'Lax';
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
                return $currentId;
            }
            return '';
        }

        \session_name(self::SESSION_NAME);

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
        
        return true;
    }

    /**
     * @inheritDoc
     */
    public function destroy(string $sessionId): bool
    {
        if (\session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            
            if (\ini_get('session.use_cookies')) {
                $params = \session_get_cookie_params();
                \setcookie(
                    self::SESSION_NAME,
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
            return $newId;
        }

        $newId = $this->generateSessionId();
        
        if ($deleteOld && $oldSessionId !== '') {
            $this->storage->destroy($oldSessionId);
        }

        $this->storage->write($newId, $data, $ttl > 0 ? $ttl : $this->defaultTtl);
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
        
        \setcookie(
            self::SESSION_NAME,
            $sessionId,
            [
                'expires' => $expires,
                'path' => $this->cookiePath,
                'domain' => $this->cookieDomain,
                'secure' => $this->cookieSecure,
                'httponly' => $this->cookieHttpOnly,
                'samesite' => $this->cookieSameSite,
            ]
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
