<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Strategy;

use Weline\Framework\Session\SessionInterface;

/**
 * Session 运行策略接口
 *
 * 遵循 OCP（开闭原则）：新增运行模式（如 Swoole、RoadRunner）只需实现此接口，
 * 无需修改 Session 核心代码。
 *
 * 遵循策略模式：将不同运行环境下的 Session 处理逻辑封装为独立策略，
 * 运行时根据环境自动选择合适的策略。
 *
 * 当前策略：
 * - FpmStrategy: 传统 PHP-FPM 模式，使用 PHP 原生 session_*() 函数
 * - WlsStrategy: WLS 常驻内存模式，使用 SessionStorage 直接读写
 */
interface SessionStrategyInterface
{
    /**
     * 检查此策略是否适用于当前运行环境
     *
     * @return bool 是否适用
     */
    public function supports(): bool;

    /**
     * 获取策略优先级（数值越大优先级越高）
     *
     * 当多个策略都支持当前环境时，选择优先级最高的。
     *
     * @return int 优先级
     */
    public function getPriority(): int;

    /**
     * 初始化 Session（在 Session::start() 时调用）
     *
     * 不同策略有不同的初始化逻辑：
     * - FPM: 调用 session_start()
     * - WLS: 从 Cookie 读取 Session ID，通过 Storage 加载数据
     *
     * @param string|null $sessionId 可选的 Session ID
     * @param array $data 引用传递的 Session 数据数组，策略应将加载的数据填充到此数组
     * @return string 实际使用的 Session ID
     */
    public function initialize(?string $sessionId, array &$data): string;

    /**
     * 持久化 Session（在数据变更时或请求结束时调用）
     *
     * @param string $sessionId Session ID
     * @param array $data 要持久化的数据
     * @param int $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    public function persist(string $sessionId, array $data, int $ttl): bool;

    /**
     * 销毁 Session
     *
     * @param string $sessionId Session ID
     * @return bool 是否成功
     */
    public function destroy(string $sessionId): bool;

    /**
     * 重新生成 Session ID
     *
     * @param string $oldSessionId 旧 Session ID
     * @param array $data 当前 Session 数据
     * @param bool $deleteOld 是否删除旧 Session
     * @param int $ttl 过期时间（秒）
     * @return string 新的 Session ID
     */
    public function regenerate(string $oldSessionId, array $data, bool $deleteOld, int $ttl): string;

    /**
     * 设置 Session Cookie
     *
     * @param string $sessionId Session ID
     * @param int $lifetime Cookie 生存时间（秒），0 表示浏览器会话
     */
    public function setCookie(string $sessionId, int $lifetime = 0): void;
}
