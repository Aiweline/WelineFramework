<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

/**
 * Session 数据存取接口（最小依赖）
 *
 * 遵循 ISP（接口隔离原则）：只需要读写 Session 数据的模块可以只依赖此接口，
 * 无需依赖认证、生命周期等其他职责。
 */
interface SessionDataInterface
{
    /**
     * 获取 Session 值
     *
     * @param string $key 键名
     * @return mixed 值，不存在返回 null
     */
    public function get(string $key): mixed;

    /**
     * 设置 Session 值
     *
     * @param string $key 键名
     * @param mixed $value 值
     */
    public function set(string $key, mixed $value): void;

    /**
     * 检查 Session 中是否存在指定键
     *
     * @param string $key 键名
     * @return bool 是否存在
     */
    public function has(string $key): bool;

    /**
     * 删除 Session 中的指定键
     *
     * @param string $key 键名
     */
    public function delete(string $key): void;

    /**
     * 获取所有 Session 数据
     *
     * @return array 所有数据
     */
    public function all(): array;

    /**
     * 清空所有 Session 数据（保留 Session ID）
     */
    public function clear(): void;
}
