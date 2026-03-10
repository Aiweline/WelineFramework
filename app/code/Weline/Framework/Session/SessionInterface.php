<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Session\Auth\AuthenticableInterface;

/**
 * 完整 Session 接口（组合数据存取 + 生命周期）
 *
 * 遵循 ISP（接口隔离原则）：
 * - 需要完整 Session 功能的模块依赖此接口
 * - 只需要数据存取的模块可以只依赖 SessionDataInterface
 * - 只需要生命周期管理的模块可以只依赖 SessionLifecycleInterface
 *
 * 认证方法为兼容层：原始 Session 返回默认值，实际认证请使用 AuthenticatedSessionInterface。
 */
interface SessionInterface extends SessionDataInterface, SessionLifecycleInterface
{
    /**
     * 手动持久化未保存的变更（用于重定向前确保写入完成，避免 WLS 下跨请求读不到）
     */
    public function save(): void;

    /**
     * 是否已登录（兼容层，原始 Session 恒为 false）
     */
    public function isLogin(): bool;

    /**
     * 用户登录（兼容层，原始 Session 无实现，应使用 AuthenticatedSessionInterface）
     */
    public function login(AuthenticableInterface $user): void;

    /**
     * 获取当前登录用户（兼容层，原始 Session 恒返回 null）
     */
    public function getLoginUser(string $model = ''): ?AuthenticableInterface;

    /**
     * 获取当前登录用户名（兼容层，原始 Session 恒返回 null）
     */
    public function getLoginUsername(): ?string;

    /**
     * 获取当前登录用户 ID（兼容层，原始 Session 恒返回 null）
     */
    public function getLoginUserID(): int|string|null;

    /**
     * 用户登出（兼容层，原始 Session 无操作）
     */
    public function logout(): void;

    /**
     * 获取原始 Session 实例（用于兼容，Session 自身返回 $this）
     */
    public function getOriginSession(): SessionInterface;
}
