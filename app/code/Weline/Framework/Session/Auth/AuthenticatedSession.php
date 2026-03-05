<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionInterface;

/**
 * 认证 Session 实现
 *
 * 遵循 SOLID 原则：
 * - SRP: 专门处理用户认证，与 Session 数据存取分离
 * - OCP: 通过 AreaConfig 配置不同区域，无需创建子类
 * - DIP: 依赖 SessionInterface 和 AuthenticableInterface 抽象
 *
 * 通过组合 Session 实现认证功能，替代原有的继承链：
 * AdminSession -> BackendSession -> Session
 */
class AuthenticatedSession implements AuthenticatedSessionInterface
{
    /** 底层 Session 实例 */
    private SessionInterface $session;

    /** 区域配置 */
    private AreaConfig $areaConfig;

    /** 缓存的用户实例 */
    private ?AuthenticableInterface $cachedUser = null;

    /**
     * 构造函数
     *
     * @param SessionInterface $session 底层 Session 实例
     * @param AreaConfig $areaConfig 区域配置
     */
    public function __construct(SessionInterface $session, AreaConfig $areaConfig)
    {
        $this->session = $session;
        $this->areaConfig = $areaConfig;
    }

    /**
     * @inheritDoc
     *
     * 切换用户时：无 session 则生成新的，有 session 则切换到该用户的 session。
     * 若用户已有 session_id，需先 destroy 当前 session 再 start 目标 session，
     * 否则 Session::start() 在已启动时会直接返回，无法完成切换。
     */
    public function login(AuthenticableInterface $user): void
    {
        $sessionId = $user->getAuthSessionId();

        if ($sessionId !== '') {
            // 用户已有 session：先 destroy 当前 session，再加载该用户的 session
            $this->session->destroy();
            $this->session->start($sessionId);
        }
        // 用户无 session：保持当前 session（或由 strategy 创建新 session），后续 regenerate 会生成新 ID

        $this->session->set($this->areaConfig->getLoginKey(), $user->getAuthUsername());
        $this->session->set($this->areaConfig->getLoginIdKey(), $user->getAuthIdentifier());
        $this->session->set($this->areaConfig->getUserModelKey(), $user::getAuthModelClass());

        // 后台登录不更换 session id，避免 302 重定向时部分环境不保存新 Cookie 导致下一请求无登录态、admin↔login 循环
        if ($this->areaConfig->getArea() === 'backend') {
            $this->session->save();
        } else {
            $this->session->regenerate(false);
        }

        $this->cachedUser = $user;
    }

    /**
     * @inheritDoc
     */
    public function logout(): void
    {
        $this->session->delete($this->areaConfig->getLoginKey());
        $this->session->delete($this->areaConfig->getLoginIdKey());
        $this->session->delete($this->areaConfig->getUserModelKey());

        $this->cachedUser = null;
    }

    /**
     * @inheritDoc
     */
    public function isLoggedIn(): bool
    {
        $loginKey = $this->session->get($this->areaConfig->getLoginKey());
        $loginIdKey = $this->session->get($this->areaConfig->getLoginIdKey());
        
        return $loginKey !== null && $loginKey !== '' && $loginIdKey !== null;
    }

    /**
     * @inheritDoc
     */
    public function getUser(): ?AuthenticableInterface
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }

        $userId = $this->getUserId();
        $modelClass = $this->session->get($this->areaConfig->getUserModelKey());

        if ($userId === null || $modelClass === null || $modelClass === '') {
            return null;
        }

        if (!\class_exists($modelClass)) {
            return null;
        }

        try {
            $model = ObjectManager::make($modelClass);
            
            if (\method_exists($model, 'load')) {
                $model->load($userId);
            }
            
            if ($model instanceof AuthenticableInterface) {
                $this->cachedUser = $model;
                return $model;
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getUserId(): int|string|null
    {
        $id = $this->session->get($this->areaConfig->getLoginIdKey());
        
        if ($id === null || $id === '') {
            return null;
        }
        
        return $id;
    }

    /**
     * @inheritDoc
     */
    public function getUsername(): ?string
    {
        $username = $this->session->get($this->areaConfig->getLoginKey());
        
        if ($username === null || $username === '') {
            return null;
        }
        
        return (string)$username;
    }

    /**
     * @inheritDoc
     */
    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): mixed
    {
        return $this->session->get($key);
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $key): void
    {
        $this->session->delete($key);
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return $this->session->getId();
    }

    /**
     * @inheritDoc
     */
    public function start(?string $sessionId = null): void
    {
        $this->session->start($sessionId);
    }

    /**
     * @inheritDoc
     */
    public function destroy(): void
    {
        $this->session->destroy();
    }

    /**
     * @inheritDoc
     */
    public function regenerate(bool $deleteOldSession = true): void
    {
        $this->session->regenerate($deleteOldSession);
    }

    /**
     * @inheritDoc
     */
    public function isStarted(): bool
    {
        return $this->session->isStarted();
    }

    /**
     * @inheritDoc
     */
    public function getArea(): string
    {
        return $this->areaConfig->getArea();
    }

    /**
     * 获取区域配置
     */
    public function getAreaConfig(): AreaConfig
    {
        return $this->areaConfig;
    }

    /**
     * 检查是否为后台区域
     */
    public function isBackend(): bool
    {
        return $this->areaConfig->isBackend();
    }

    /**
     * 检查是否为前台区域
     */
    public function isFrontend(): bool
    {
        return $this->areaConfig->isFrontend();
    }

    /**
     * 检查是否为 API 区域
     */
    public function isApi(): bool
    {
        return $this->areaConfig->isApi();
    }

    /**
     * 重置缓存（WLS 模式下请求结束时调用）
     */
    public function reset(): void
    {
        $this->cachedUser = null;
        
        if (\method_exists($this->session, 'reset')) {
            $this->session->reset();
        }
    }

    // ==================== 兼容方法（过渡期使用） ====================

    /**
     * 兼容旧的 getData 方法
     *
     * @deprecated 使用 getSession()->get() 代替
     */
    public function getData(string $name = ''): mixed
    {
        if ($name === '') {
            return $this->session->all();
        }
        return $this->session->get($name);
    }

    /**
     * 兼容旧的 setData 方法
     *
     * @deprecated 使用 getSession()->set() 代替
     */
    public function setData(string $name, mixed $value): static
    {
        $this->session->set($name, $value);
        return $this;
    }

    /**
     * 兼容旧的 isLogin 方法
     *
     * @deprecated 使用 isLoggedIn() 代替
     */
    public function isLogin(): bool
    {
        return $this->isLoggedIn();
    }

    /**
     * 兼容旧的 getLoginUser 方法
     *
     * @deprecated 使用 getUser() 代替
     */
    public function getLoginUser(string $model = ''): ?AuthenticableInterface
    {
        return $this->getUser();
    }

    /**
     * 兼容旧的 getLoginUsername 方法
     *
     * @deprecated 使用 getUsername() 代替
     */
    public function getLoginUsername(): ?string
    {
        return $this->getUsername();
    }

    /**
     * 兼容旧的 getLoginUserID 方法
     *
     * @deprecated 使用 getUserId() 代替
     */
    public function getLoginUserID(): int|string|null
    {
        return $this->getUserId();
    }

    /**
     * 兼容旧的 getLoginUserData 方法
     *
     * @deprecated 使用 getUser()->getData() 或直接访问用户模型
     * @param string $key 数据键名，为空时返回全部用户数据
     * @return mixed 用户数据
     */
    public function getLoginUserData(string $key = ''): mixed
    {
        $user = $this->getUser();
        if ($user === null) {
            return $key === '' ? [] : null;
        }

        if (\method_exists($user, 'getData')) {
            if ($key === '') {
                return $user->getData();
            }
            return $user->getData($key);
        }

        return $key === '' ? [] : null;
    }

    /**
     * 兼容旧的 getSessionId 方法
     *
     * @deprecated 使用 getSession()->getId() 代替
     */
    public function getSessionId(): string
    {
        return $this->session->getId();
    }

    /**
     * 兼容旧的 getType 方法
     *
     * @deprecated 使用 getArea() 代替
     */
    public function getType(): string
    {
        return $this->getArea();
    }
}
