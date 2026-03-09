<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\App\Env;
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\Auth\AuthenticatedSession;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Storage\FileStorage;
use Weline\Framework\Session\Storage\RedisStorage;
use Weline\Framework\Session\Storage\SessionStorageInterface;
use Weline\Framework\Session\Storage\WlsSharedStorage;
use Weline\Framework\Session\Strategy\FpmStrategy;
use Weline\Framework\Session\Strategy\SessionStrategyInterface;
use Weline\Framework\Session\Strategy\WlsStrategy;

/**
 * Session 工厂
 *
 * 替代原有的 SessionManager，提供更清晰的职责分离和依赖注入支持。
 *
 * 遵循 SOLID 原则：
 * - SRP: 只负责创建 Session 相关实例
 * - OCP: 通过配置和策略模式支持扩展，无需修改工厂代码
 * - DIP: 返回接口类型，调用方依赖抽象
 */
class SessionFactory
{
    /** 配置 */
    private array $config;

    /** 已创建的存储实例（进程级缓存） */
    private static array $storageInstances = [];

    /** 已创建的策略实例（进程级缓存） */
    private static array $strategyInstances = [];

    /** 已创建的 Session 实例（请求级，WLS 下需重置） */
    private ?SessionInterface $sessionInstance = null;

    /** 已创建的 AuthenticatedSession 实例（请求级） */
    private array $authSessionInstances = [];

    /**
     * 构造函数
     *
     * @param array|null $config 配置，为空则从 Env 读取
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array)Env::getInstance()->getConfig('session');
    }

    // ==================== 存储层创建 ====================

    /**
     * 创建存储实例
     *
     * @param string $type 存储类型：file, redis, wls
     * @return SessionStorageInterface
     */
    public function createStorage(string $type = ''): SessionStorageInterface
    {
        if ($type === '') {
            $type = $this->resolveStorageType();
        } else {
            $type = \strtolower(\trim($type));
            if ($type === '') {
                $type = $this->resolveStorageType();
            } elseif ($this->isWlsMode() && $type === 'file' && $this->shouldHijackFileToWls()) {
                $type = 'wls';
            }
        }

        if (isset(self::$storageInstances[$type])) {
            return self::$storageInstances[$type];
        }

        $storageConfig = $this->getStorageConfig($type);
        
        $storage = match ($type) {
            'redis' => new RedisStorage($storageConfig),
            'wls' => new WlsSharedStorage($storageConfig),
            default => new FileStorage($storageConfig),
        };

        self::$storageInstances[$type] = $storage;
        
        return $storage;
    }

    /**
     * 解析存储类型
     *
     * 智能检测存储类型：
     * 1. 读取 session.default 作为显式驱动
     * 2. WLS 下仅接管 file -> wls
     * 3. 非 file 显式驱动保持原样（如 redis）
     */
    private function resolveStorageType(): string
    {
        $configured = \strtolower(\trim((string)($this->config['default'] ?? 'file')));
        if ($configured === '') {
            $configured = 'file';
        }

        if (!$this->isWlsMode()) {
            return $configured;
        }

        // WLS 常驻模式：仅接管 file 驱动，其他显式驱动保持原样
        if ($configured === 'file' && $this->shouldHijackFileToWls()) {
            return 'wls';
        }

        return $configured;
    }

    /**
     * 是否启用 file -> wls 接管策略。
     * 若 env session.wls_managed=false，强制使用 file 存储（Session Server 不可用时的 fallback）
     */
    private function shouldHijackFileToWls(): bool
    {
        $wlsManaged = $this->config['wls_managed'] ?? true;
        if ($wlsManaged === false) {
            return false;
        }
        if (\class_exists(\Weline\Server\Service\Runtime\RoutingPolicyRegistry::class)) {
            return \Weline\Server\Service\Runtime\RoutingPolicyRegistry::shouldHijackSessionFile();
        }
        return true;
    }

    /**
     * 获取存储配置
     *
     * 统一从 drivers 下读取配置，支持 file、redis、wls 等驱动
     */
    private function getStorageConfig(string $type): array
    {
        $baseConfig = [
            'lifetime' => (int)($this->config['lifetime'] ?? $this->config['session_ttl'] ?? 3600),
        ];

        $drivers = $this->config['drivers'] ?? [];

        return \array_merge($baseConfig, $drivers[$type] ?? []);
    }

    // ==================== 策略层创建 ====================

    /**
     * 创建策略实例
     *
     * @param SessionStorageInterface|null $storage 存储实例
     * @return SessionStrategyInterface
     */
    public function createStrategy(?SessionStorageInterface $storage = null): SessionStrategyInterface
    {
        $storage ??= $this->createStorage();
        $isWls = $this->isWlsMode();
        $type = $isWls ? 'wls' : 'fpm';

        $cacheKey = $type . '_' . \spl_object_id($storage);
        if (isset(self::$strategyInstances[$cacheKey])) {
            return self::$strategyInstances[$cacheKey];
        }

        $strategyConfig = $this->getStrategyConfig();

        $strategy = $isWls
            ? new WlsStrategy($storage, $strategyConfig)
            : new FpmStrategy($storage, $strategyConfig);

        self::$strategyInstances[$cacheKey] = $strategy;

        return $strategy;
    }

    /**
     * 获取策略配置
     */
    private function getStrategyConfig(): array
    {
        return [
            'lifetime' => (int)($this->config['lifetime'] ?? $this->config['session_ttl'] ?? 3600),
            'cookie_path' => $this->config['cookie_path'] ?? '/',
            'cookie_domain' => $this->config['cookie_domain'] ?? '',
            'cookie_secure' => $this->config['cookie_secure'] ?? null,
            'cookie_httponly' => $this->config['cookie_httponly'] ?? true,
            'cookie_samesite' => $this->config['cookie_samesite'] ?? 'Lax',
            'cookie_lifetime' => (int)($this->config['cookie_lifetime'] ?? 86400 * 30),
        ];
    }

    // ==================== Session 创建 ====================

    /**
     * 创建 Session 实例
     *
     * @return SessionInterface
     */
    public function createSession(): SessionInterface
    {
        if ($this->sessionInstance !== null) {
            return $this->sessionInstance;
        }

        $storage = $this->createStorage();
        $strategy = $this->createStrategy($storage);
        $ttl = (int)($this->config['lifetime'] ?? $this->config['session_ttl'] ?? 3600);

        $this->sessionInstance = new Session($storage, $strategy, $ttl);

        return $this->sessionInstance;
    }

    /**
     * 创建认证 Session 实例
     *
     * @param string $area 区域：backend, frontend, api, rest_backend
     * @return AuthenticatedSessionInterface
     */
    public function createAuthenticatedSession(string $area = 'frontend'): AuthenticatedSessionInterface
    {
        if (isset($this->authSessionInstances[$area])) {
            return $this->authSessionInstances[$area];
        }

        $session = $this->createSession();
        $areaConfig = new AreaConfig($area);

        $authSession = new AuthenticatedSession($session, $areaConfig);
        $this->authSessionInstances[$area] = $authSession;

        return $authSession;
    }

    /**
     * 创建后台认证 Session
     *
     * @return AuthenticatedSessionInterface
     */
    public function createBackendSession(): AuthenticatedSessionInterface
    {
        return $this->createAuthenticatedSession('backend');
    }

    /**
     * 创建前台认证 Session
     *
     * @return AuthenticatedSessionInterface
     */
    public function createFrontendSession(): AuthenticatedSessionInterface
    {
        return $this->createAuthenticatedSession('frontend');
    }

    /**
     * 创建 API 认证 Session
     *
     * @return AuthenticatedSessionInterface
     */
    public function createApiSession(): AuthenticatedSessionInterface
    {
        return $this->createAuthenticatedSession('api');
    }

    /**
     * 创建结账认证 Session
     *
     * @return AuthenticatedSessionInterface
     */
    public function createCheckoutSession(): AuthenticatedSessionInterface
    {
        return $this->createAuthenticatedSession('checkout');
    }

    /**
     * 创建自定义区域认证 Session
     *
     * @param string $area 区域名称
     * @param array $config 区域配置（可选，如果区域已注册则使用已注册配置）
     * @return AuthenticatedSessionInterface
     *
     * @example
     * // 方式1：先注册区域，再创建 Session
     * AreaConfig::registerArea('wishlist', ['login_key' => 'WF_WISHLIST_USER', ...]);
     * $session = SessionFactory::getInstance()->createCustomSession('wishlist');
     *
     * // 方式2：直接传入配置
     * $session = SessionFactory::getInstance()->createCustomSession('wishlist', [
     *     'login_key' => 'WF_WISHLIST_USER',
     *     'login_id_key' => 'WF_WISHLIST_USER_ID',
     * ]);
     */
    public function createCustomSession(string $area, array $config = []): AuthenticatedSessionInterface
    {
        // 如果传入配置且区域未注册，则先注册
        if (!empty($config) && !AreaConfig::hasArea($area)) {
            AreaConfig::registerArea($area, $config);
        }

        return $this->createAuthenticatedSession($area);
    }

    // ==================== 辅助方法 ====================

    /**
     * 检查是否为 WLS 常驻内存模式
     */
    private function isWlsMode(): bool
    {
        if (\class_exists('Weline\\Framework\\Runtime\\Runtime', false)) {
            return \Weline\Framework\Runtime\Runtime::isPersistent();
        }
        
        return false;
    }

    /**
     * 获取配置
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * 重置请求级实例（WLS 模式下每请求调用）
     */
    public function resetRequestInstances(): void
    {
        if ($this->sessionInstance !== null && \method_exists($this->sessionInstance, 'reset')) {
            $this->sessionInstance->reset();
        }
        
        foreach ($this->authSessionInstances as $authSession) {
            if (\method_exists($authSession, 'reset')) {
                $authSession->reset();
            }
        }
        
        $this->sessionInstance = null;
        $this->authSessionInstances = [];
    }

    /**
     * 重置所有实例（包括进程级缓存和单例）
     */
    public static function resetAll(): void
    {
        if (self::$instance !== null) {
            self::$instance->resetRequestInstances();
            self::$instance = null;
        }
        self::$storageInstances = [];
        self::$strategyInstances = [];
    }

    // ==================== 静态便捷方法 ====================

    /** 单例实例 */
    private static ?self $instance = null;

    /**
     * 获取单例实例
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 快速创建 Session
     */
    public static function session(): SessionInterface
    {
        return self::getInstance()->createSession();
    }

    /**
     * 快速创建后台认证 Session
     */
    public static function backend(): AuthenticatedSessionInterface
    {
        return self::getInstance()->createBackendSession();
    }

    /**
     * 快速创建前台认证 Session
     */
    public static function frontend(): AuthenticatedSessionInterface
    {
        return self::getInstance()->createFrontendSession();
    }
}
