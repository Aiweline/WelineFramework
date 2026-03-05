<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth;

use Weline\Framework\Session\SessionLifecycleInterface;

/**
 * 认证 Session 接口
 *
 * 遵循 SRP（单一职责原则）：专门处理用户认证相关的 Session 操作。
 * 遵循 ISP（接口隔离原则）：认证逻辑与数据存取分离，只需要存取的模块不必依赖认证。
 *
 * 继承 SessionLifecycleInterface 以复用 start/destroy/regenerate/getId/isStarted 契约，
 * 避免与底层 Session 的委托方法产生 redeclare 冲突。
 */
interface AuthenticatedSessionInterface extends SessionLifecycleInterface
{
    /**
     * 用户登录
     *
     * @param AuthenticableInterface $user 实现了 AuthenticableInterface 的用户模型
     */
    public function login(AuthenticableInterface $user): void;

    /**
     * 用户登出
     */
    public function logout(): void;

    /**
     * 检查用户是否已登录
     *
     * @return bool 是否已登录
     */
    public function isLoggedIn(): bool;

    /**
     * 获取当前登录用户
     *
     * @return AuthenticableInterface|null 用户模型，未登录返回 null
     */
    public function getUser(): ?AuthenticableInterface;

    /**
     * 获取当前登录用户 ID
     *
     * @return int|string|null 用户 ID，未登录返回 null
     */
    public function getUserId(): int|string|null;

    /**
     * 获取当前登录用户名
     *
     * @return string|null 用户名，未登录返回 null
     */
    public function getUsername(): ?string;

    /**
     * 获取底层 Session 实例（用于直接数据存取）
     *
     * @return \Weline\Framework\Session\SessionInterface
     */
    public function getSession(): \Weline\Framework\Session\SessionInterface;

    /**
     * 获取 Session 值（委托至底层 Session）
     */
    public function get(string $key): mixed;

    /**
     * 设置 Session 值（委托至底层 Session）
     */
    public function set(string $key, mixed $value): void;

    /**
     * 删除 Session 键（委托至底层 Session）
     */
    public function delete(string $key): void;

    /**
     * 获取当前区域类型
     *
     * @return string 区域类型：frontend, backend, api, rest_backend 等
     */
    public function getArea(): string;
}
