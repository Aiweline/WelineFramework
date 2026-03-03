<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

/**
 * Session 存储接口
 *
 * 遵循 SRP（单一职责原则）：只负责 Session 数据的持久化存储。
 * 遵循 OCP（开闭原则）：新增存储后端（如 Redis、Memcached）只需实现此接口，无需修改核心代码。
 * 遵循 DIP（依赖倒置原则）：Session 依赖此抽象接口，而非具体存储实现。
 *
 * 此接口统一了原有的 SessionDriverHandlerInterface 和 SessionBackendInterface，
 * 消除职责重叠。
 */
interface SessionStorageInterface
{
    /**
     * 读取 Session 数据
     *
     * @param string $sessionId Session ID
     * @return array Session 数据，不存在返回空数组
     */
    public function read(string $sessionId): array;

    /**
     * 写入 Session 数据
     *
     * @param string $sessionId Session ID
     * @param array $data 要写入的数据
     * @param int $ttl 过期时间（秒）
     * @return bool 是否成功
     */
    public function write(string $sessionId, array $data, int $ttl): bool;

    /**
     * 销毁 Session
     *
     * @param string $sessionId Session ID
     * @return bool 是否成功
     */
    public function destroy(string $sessionId): bool;

    /**
     * 检查 Session 是否存在
     *
     * @param string $sessionId Session ID
     * @return bool 是否存在
     */
    public function exists(string $sessionId): bool;

    /**
     * 刷新 Session 过期时间
     *
     * @param string $sessionId Session ID
     * @param int $ttl 新的过期时间（秒）
     * @return bool 是否成功
     */
    public function touch(string $sessionId, int $ttl): bool;

    /**
     * 垃圾回收（清理过期 Session）
     *
     * @param int $maxLifetime 最大生存时间（秒）
     * @return int 清理的 Session 数量
     */
    public function gc(int $maxLifetime): int;

    /**
     * 获取存储配置
     *
     * @return array 配置数组
     */
    public function getConfig(): array;

    /**
     * 列出 Session（支持过滤）
     *
     * @param array $options 选项：
     *   - filter: array 过滤条件，如 ['type' => 'backend']
     *   - limit: int 最大返回数量，默认 50
     * @return array Session 列表，每项包含 'session_id' 和 'data'
     *   格式: [['session_id' => '...', 'data' => [...]], ...]
     */
    public function list(array $options = []): array;
}
