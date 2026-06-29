<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth;

/**
 * 区域配置
 *
 * 用组合替代继承：原有的 BackendSession、FrontendSession 等子类
 * 只是改变了 login_KEY 等常量，现在通过配置对象实现。
 *
 * 预定义区域：
 * - backend: 后台管理
 * - frontend: 前台用户
 * - api: 前台 API
 * - rest_backend: 后台 REST API
 */
final class AreaConfig
{
    /** 预定义区域配置 */
    private const PRESETS = [
        'backend' => [
            'login_key' => 'WF_BACKEND_USER',
            'login_id_key' => 'WF_BACKEND_USER_ID',
            'user_model_key' => 'WF_BACKEND_USER_MODEL',
            'cookie_path' => '/',
        ],
        'frontend' => [
            'login_key' => 'WF_FRONTEND_USER',
            'login_id_key' => 'WF_FRONTEND_USER_ID',
            'user_model_key' => 'WF_FRONTEND_USER_MODEL',
            'cookie_path' => '/',
        ],
        'api' => [
            'login_key' => 'WF_API_USER',
            'login_id_key' => 'WF_API_USER_ID',
            'user_model_key' => 'WF_API_USER_MODEL',
            'cookie_path' => '/',
        ],
        'rest_backend' => [
            // rest_backend is a route/API area, not a separate browser login realm.
            'login_key' => 'WF_BACKEND_USER',
            'login_id_key' => 'WF_BACKEND_USER_ID',
            'user_model_key' => 'WF_BACKEND_USER_MODEL',
            'cookie_path' => '/',
        ],
        'checkout' => [
            'login_key' => 'WF_CHECKOUT_USER',
            'login_id_key' => 'WF_CHECKOUT_USER_ID',
            'user_model_key' => 'WF_CHECKOUT_USER_MODEL',
            'cookie_path' => '/',
        ],
    ];

    /** 运行时注册的自定义区域 */
    private static array $customAreas = [];

    /** 区域名称 */
    private string $area;

    /** 登录用户名存储键 */
    private string $loginKey;

    /** 登录用户 ID 存储键 */
    private string $loginIdKey;

    /** 用户模型类存储键 */
    private string $userModelKey;

    /** Cookie 路径 */
    private string $cookiePath;

    /**
     * 构造函数
     *
     * @param string $area 区域名称
     * @param array $overrides 覆盖配置
     */
    public function __construct(string $area, array $overrides = [])
    {
        $this->area = $area;
        
        // 优先从自定义区域获取，然后是预设，最后回退到 frontend
        $preset = self::$customAreas[$area] ?? self::PRESETS[$area] ?? self::PRESETS['frontend'];
        
        $this->loginKey = $overrides['login_key'] ?? $preset['login_key'];
        $this->loginIdKey = $overrides['login_id_key'] ?? $preset['login_id_key'];
        $this->userModelKey = $overrides['user_model_key'] ?? $preset['user_model_key'];
        $this->cookiePath = $overrides['cookie_path'] ?? $preset['cookie_path'];
    }

    /**
     * 注册自定义区域
     *
     * 允许模块在运行时注册自己的区域配置
     *
     * @param string $area 区域名称
     * @param array $config 区域配置，包含 login_key, login_id_key, user_model_key, cookie_path
     *
     * @example
     * AreaConfig::registerArea('checkout', [
     *     'login_key' => 'WF_CHECKOUT_USER',
     *     'login_id_key' => 'WF_CHECKOUT_USER_ID',
     *     'user_model_key' => 'WF_CHECKOUT_USER_MODEL',
     *     'cookie_path' => '/',
     * ]);
     */
    public static function registerArea(string $area, array $config): void
    {
        self::$customAreas[$area] = \array_merge([
            'login_key' => 'WF_' . \strtoupper($area) . '_USER',
            'login_id_key' => 'WF_' . \strtoupper($area) . '_USER_ID',
            'user_model_key' => 'WF_' . \strtoupper($area) . '_USER_MODEL',
            'cookie_path' => '/',
        ], $config);
    }

    /**
     * 检查区域是否已注册
     */
    public static function hasArea(string $area): bool
    {
        return isset(self::PRESETS[$area]) || isset(self::$customAreas[$area]);
    }

    /**
     * 获取所有可用区域
     */
    public static function getAvailableAreas(): array
    {
        return \array_unique(\array_merge(
            \array_keys(self::PRESETS),
            \array_keys(self::$customAreas)
        ));
    }

    /**
     * 创建后台区域配置
     */
    public static function backend(array $overrides = []): self
    {
        return new self('backend', $overrides);
    }

    /**
     * 创建前台区域配置
     */
    public static function frontend(array $overrides = []): self
    {
        return new self('frontend', $overrides);
    }

    /**
     * 创建 API 区域配置
     */
    public static function api(array $overrides = []): self
    {
        return new self('api', $overrides);
    }

    /**
     * 创建后台 REST API 区域配置
     */
    public static function restBackend(array $overrides = []): self
    {
        return new self('rest_backend', $overrides);
    }

    /**
     * 创建结账区域配置
     */
    public static function checkout(array $overrides = []): self
    {
        return new self('checkout', $overrides);
    }

    /**
     * 创建自定义区域配置
     *
     * @param string $area 区域名称
     * @param array $overrides 覆盖配置
     */
    public static function custom(string $area, array $overrides = []): self
    {
        return new self($area, $overrides);
    }

    /**
     * 获取区域名称
     */
    public function getArea(): string
    {
        return $this->area;
    }

    /**
     * 获取登录用户名存储键
     */
    public function getLoginKey(): string
    {
        return $this->loginKey;
    }

    /**
     * 获取登录用户 ID 存储键
     */
    public function getLoginIdKey(): string
    {
        return $this->loginIdKey;
    }

    /**
     * 获取用户模型类存储键
     */
    public function getUserModelKey(): string
    {
        return $this->userModelKey;
    }

    /**
     * 获取 Cookie 路径
     */
    public function getCookiePath(): string
    {
        return $this->cookiePath;
    }

    /**
     * 检查是否为后台区域
     */
    public function isBackend(): bool
    {
        return $this->area === 'backend' || $this->area === 'rest_backend';
    }

    /**
     * 检查是否为前台区域
     */
    public function isFrontend(): bool
    {
        return $this->area === 'frontend' || $this->area === 'api';
    }

    /**
     * 检查是否为 API 区域
     */
    public function isApi(): bool
    {
        return $this->area === 'api' || $this->area === 'rest_backend';
    }
}
