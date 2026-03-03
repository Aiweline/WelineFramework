<?php

declare(strict_types=1);

namespace WeShop\Customer\Session;

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\Auth\AuthenticableInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

/**
 * WeShop 客户 Session 门面类
 *
 * 提供对新 Session 架构的简化访问，保持向后兼容。
 * 推荐新代码直接使用 SessionFactory::getInstance()->createFrontendSession()
 */
class CustomerSession implements AuthenticatedSessionInterface
{
    private AuthenticatedSessionInterface $session;

    public function __construct()
    {
        $this->session = SessionFactory::getInstance()->createFrontendSession();
    }

    /**
     * @inheritDoc
     */
    public function login(AuthenticableInterface $user): void
    {
        $this->session->login($user);
    }

    /**
     * @inheritDoc
     */
    public function logout(): void
    {
        $this->session->logout();
    }

    /**
     * @inheritDoc
     */
    public function isLoggedIn(): bool
    {
        return $this->session->isLoggedIn();
    }

    /**
     * @inheritDoc
     */
    public function getUser(): ?AuthenticableInterface
    {
        return $this->session->getUser();
    }

    /**
     * @inheritDoc
     */
    public function getUserId(): int|string|null
    {
        return $this->session->getUserId();
    }

    /**
     * @inheritDoc
     */
    public function getUsername(): ?string
    {
        return $this->session->getUsername();
    }

    /**
     * @inheritDoc
     */
    public function getSession(): SessionInterface
    {
        return $this->session->getSession();
    }

    /**
     * @inheritDoc
     */
    public function getArea(): string
    {
        return $this->session->getArea();
    }

    // ==================== 兼容方法 ====================

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
     * 兼容旧的 getLoginUserId 方法
     *
     * @deprecated 使用 getUserId() 代替
     */
    public function getLoginUserId(): int|string|null
    {
        return $this->getUserId();
    }

    /**
     * 兼容旧的 getSessionId 方法
     *
     * @deprecated 使用 getSession()->getId() 代替
     */
    public function getSessionId(): string
    {
        return $this->session->getSession()->getId();
    }

    /**
     * 兼容旧的 getData 方法
     *
     * @deprecated 使用 getSession()->get() 代替
     */
    public function getData(string $name = ''): mixed
    {
        if ($name === '') {
            return $this->session->getSession()->all();
        }
        return $this->session->getSession()->get($name);
    }

    /**
     * 兼容旧的 setData 方法
     *
     * @deprecated 使用 getSession()->set() 代替
     */
    public function setData(string $name, mixed $value): static
    {
        $this->session->getSession()->set($name, $value);
        return $this;
    }

    /**
     * 兼容旧的 delete 方法
     *
     * @deprecated 使用 getSession()->delete() 代替
     */
    public function delete(string $name): bool
    {
        $this->session->getSession()->delete($name);
        return true;
    }
}
