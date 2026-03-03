<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

/**
 * Session 生命周期接口
 *
 * 遵循 SRP（单一职责原则）：只负责 Session 的生命周期管理，
 * 不涉及数据存取或认证逻辑。
 */
interface SessionLifecycleInterface
{
    /**
     * 启动 Session
     *
     * @param string|null $sessionId 可选的 Session ID，为空则自动生成或从 Cookie 读取
     */
    public function start(?string $sessionId = null): void;

    /**
     * 销毁 Session（删除所有数据并使 Session ID 失效）
     */
    public function destroy(): void;

    /**
     * 重新生成 Session ID（安全措施，防止 Session 固定攻击）
     *
     * @param bool $deleteOldSession 是否删除旧 Session 数据
     */
    public function regenerate(bool $deleteOldSession = true): void;

    /**
     * 获取当前 Session ID
     *
     * @return string Session ID，未启动则返回空字符串
     */
    public function getId(): string;

    /**
     * 检查 Session 是否已启动
     *
     * @return bool 是否已启动
     */
    public function isStarted(): bool;
}
