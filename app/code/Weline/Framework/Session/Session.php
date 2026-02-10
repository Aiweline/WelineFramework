<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Session;

use Weline\Framework\App\Env;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Driver\SessionDriverHandlerInterface;

class
Session implements SessionInterface
{
    public const session_name = 'WELINE_SESSID';
    public const login_KEY = 'WL_USER';
    public const login_KEY_ID = 'WL_USER_ID';
    public const login_USER_MODEL = 'WL_USER_MODEL';

    private ?AbstractModel $user = null;

    private ?SessionDriverHandlerInterface $session = null;
    
    /**
     * 懒加载标志：Session 是否已启动
     * 优化：避免每次请求都立即启动 Session（FPM 下会触发文件锁，WLS 下会触发文件读取）
     */
    private bool $lazyStarted = false;

    public function __construct()
    {
        // 懒加载：不在构造函数中启动 Session
        // Session 将在首次访问数据时通过 ensureStarted() 按需启动
    }

    public function __init()
    {
        // 懒加载：__init 由 ObjectManager 调用，但不再立即启动 Session
        // Session 将在首次数据访问时按需启动
    }
    
    /**
     * 确保 Session 已启动（懒加载核心方法）
     * 
     * 只在首次访问 Session 数据时才执行初始化：
     * - 创建 Session Driver
     * - 调用 start()（FPM: session_start()，WLS: 文件读取）
     * - 设置 type 和 path
     * 
     * 性能优化：
     * - FPM 模式：避免不需要 Session 的请求触发 session_start()（带文件锁）
     * - WLS 模式：避免不需要 Session 的请求触发 file_get_contents + unserialize
     */
    private function ensureStarted(): void
    {
        if ($this->lazyStarted) {
            return;
        }
        $this->lazyStarted = true;
        
        if ((!CLI || defined('ENV_TEST')) && isset($_SERVER['REQUEST_URI']) && !isset($this->session)) {
            $type = 'frontend';
            $identity_path = '/';
            $backendPrefix = Env::getAreaRoutePrefix('backend') ?? '';
            $restBackendPrefix = Env::getAreaRoutePrefix('rest_backend') ?? '';
            if ($backendPrefix && is_int(strpos($_SERVER['REQUEST_URI'], $backendPrefix))) {
                $identity_path .= $backendPrefix;
                $type = 'backend';
            } elseif ($restBackendPrefix && is_int(strpos($_SERVER['REQUEST_URI'], $restBackendPrefix))) {
                $identity_path .= $restBackendPrefix;
                $type = 'rest_backend';
            }
            $this->session = SessionManager::getInstance()->create();
            $this->start();
            $this->setType($type)->setData('path', $identity_path);
        }
    }

    public function start(string|null $session_id = ''): void
    {
        // 检测是否是 HTTP 请求环境
        // 1. 非 CLI 环境：正常 Web 请求
        // 2. 常驻内存模式：CLI 但模拟了 HTTP 环境（通过 REQUEST_URI 检测）
        $isHttpRequest = PHP_SAPI !== 'cli' || $this->isHttpRequestEnvironment();
        
        // 如果 headers 已发送，跳过 session 启动
        if (\headers_sent()) {
            return;
        }
        
        // 非 HTTP 请求环境，跳过 session 启动
        if (!$isHttpRequest) {
            return;
        }
        
        // 检查配置：是否使用自定义会话管理（适用于常驻内存模式）
        // 常驻内存模式下默认托管（与 SessionDriverInterceptor 一致，默认 true）
        $sessionConfig = Env::getInstance()->getConfig('session') ?: [];
        $isPersistent = \class_exists(\Weline\Framework\Runtime\Runtime::class, false)
            && \Weline\Framework\Runtime\Runtime::isPersistent();
        $customManaged = $sessionConfig['custom_managed']
            ?? $sessionConfig['wls_managed']
            ?? $isPersistent;  // 常驻内存模式下默认 true
        
        if ($customManaged) {
            // 自定义会话管理模式：由 Session Driver（如 WlsMemorySession）全权管理
            // WlsMemorySession 在 __construct() → initSessionId() 中已完成：
            //   - 从 Cookie 读取或生成 Session ID
            //   - 从文件/内存加载 Session 数据
            //   - 通过 HeaderCollector 设置 Set-Cookie 响应头
            // 这里无需重复操作，直接返回
            return;
        }
        
        // 标准 PHP 会话管理
        if ($session_id) {
            if (\session_id() !== $session_id) {
                \session_id($session_id);
            }
        }
        if (\session_status() !== PHP_SESSION_ACTIVE) {
            \session_name(self::session_name);
            \session_start();
        }
    }
    
    /**
     * 检测是否是模拟的 HTTP 请求环境
     * 
     * 用于检测常驻内存服务器（如 WLS）模拟的 HTTP 环境
     * 无需判断 WLS_MODE，通过环境变量检测
     */
    private function isHttpRequestEnvironment(): bool
    {
        // 如果有 REQUEST_URI，说明是 HTTP 请求环境
        if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] !== '') {
            return true;
        }
        
        // 如果有 REQUEST_METHOD，说明是 HTTP 请求环境
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== '') {
            return true;
        }
        
        // 通过 RequestContext 检测
        if (\class_exists(\Weline\Framework\Runtime\RequestContext::class, false)) {
            return \Weline\Framework\Runtime\RequestContext::isInitialized();
        }
        
        return false;
    }
    
    /**
     * WLS 托管模式：从文件加载 Session 数据
     */
    private function loadSessionFromFile(string $session_id): void
    {
        $sessionConfig = Env::getInstance()->getConfig('session') ?: [];
        $sessionPath = BP . \str_replace('/', DS, $sessionConfig['drivers']['file']['path'] ?? 'var/session/');
        $sessionFile = $sessionPath . $session_id;
        
        if (\file_exists($sessionFile)) {
            $data = @\file_get_contents($sessionFile);
            if ($data !== false && $data !== '') {
                $sessionData = @\unserialize($data);
                if (\is_array($sessionData)) {
                    $_SESSION = $sessionData;
                }
            }
        }
    }
    
    /**
     * WLS 托管模式：生成新的 Session ID
     */
    private function generateSessionId(): string
    {
        return \bin2hex(\random_bytes(16));
    }
    
    /**
     * WLS 托管模式：设置 Session Cookie（通过 HeaderCollector）
     */
    private function setSessionCookie(string $session_id): void
    {
        // 使用 HeaderCollector 设置 Cookie，由 Runtime 层统一发送
        $headerCollector = \Weline\Framework\Http\HeaderCollector::getInstance();
        
        $headerCollector->setCookie(
            self::session_name,
            $session_id,
            \time() + 86400 * 30, // 30 天
            '/',
            '',
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            true,
            'Lax'
        );
    }

    /**
     * @DESC          # 设置session值
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/22 21:45
     * 参数区：
     *
     * @param string $name
     * @param string $value
     *
     * @return SessionDriverHandlerInterface
     */
    public function setData(string $name, mixed $value): SessionDriverHandlerInterface
    {
        $this->ensureStarted();
        $this->session->set($name, $value);
        return $this->session;
    }

    /**
     * @DESC          # 获取session值
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/22 21:45
     * 参数区：
     *
     * @param string $name
     *
     * @return string
     */
    public function getData(string $name = ''): mixed
    {
        $this->ensureStarted();
        if ($name) {
            return $this->session->get($name);
        }
        return $this->session->get();
    }

    /**
     * @DESC          # 获取session值
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/22 21:45
     * 参数区：
     *
     * @param string $name
     *
     * @return string
     */
    public function addData(string $name, string $value): string
    {
        $this->ensureStarted();
        return $this->session->set($name, $this->session->get($name) . $value);
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @return SessionDriverHandlerInterface
     */
    public function getOriginSession(): SessionDriverHandlerInterface
    {
        $this->ensureStarted();
        return $this->session;
    }

    public function getSessionId(): string
    {
        $this->ensureStarted();
        return $this->session->getSessionId();
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @return mixed
     */
    public function isLogin(): bool
    {
        $this->ensureStarted();
        return (bool)$this->session->get($this::login_KEY);
    }

    public function login(\Weline\Framework\Database\Model $user): static
    {
        $this->ensureStarted();
        $this->start($user->getSessionId());
        $this->session->set($this::login_KEY, $user->getUsername());
        $this->session->set($this::login_KEY_ID, $user->getId());
        $this->session->set($this::login_USER_MODEL, $user::class);
        return $this;
    }

    public function getLoginUser(string $model): ?AbstractModel
    {
        $this->ensureStarted();
        if ($this->user) {
            return $this->user;
        }
        $this->user = ObjectManager::make($model)->load($this->session->get($this::login_KEY_ID) ?: '');
        return $this->user;
    }

    public function getLoginUsername()
    {
        $this->ensureStarted();
        return $this->session->get($this::login_KEY);
    }

    public function getLoginUserID()
    {
        $this->ensureStarted();
        return (int)$this->session->get($this::login_KEY_ID);
    }

    public function logout(): bool
    {
        $this->ensureStarted();
        $this->session->delete($this::login_KEY);
        return $this->session->delete($this::login_KEY_ID);
    }

    public function getType(): string
    {
        $this->ensureStarted();
        return $this->session->get('type');
    }

    public function setType(string $type): static
    {
        $this->ensureStarted();
        $this->session->set('type', $type);
        return $this;
    }

    public function isBackend(): bool
    {
        return $this->getType() === 'backend';
    }

    public function isApi(): bool
    {
        return $this->getType() === 'api';
    }

    public function isApiBackend(): bool
    {
        return $this->getType() === 'api_backend';
    }

    public function isFrontend(): bool
    {
        return $this->getType() === 'frontend';
    }

    public function destroy(string $id = ''): bool
    {
        $this->ensureStarted();
        return $this->session->destroy($id ?: $this->session->getSessionId());
    }

    public function delete(string $name): bool
    {
        $this->ensureStarted();
        // 通过驱动删除（兼容 WLS 自定义会话和原生 $_SESSION 两种模式）
        if (isset($this->session)) {
            return $this->session->delete($name);
        }
        // 兜底：直接操作 $_SESSION
        if (isset($_SESSION[$name])) {
            unset($_SESSION[$name]);
            return true;
        }
        return false;
    }

    public function getGcMaxLifeTime(): int
    {
        return ini_get('session.gc_maxlifetime');
    }
}
