<?php
declare(strict_types=1);

namespace Weline\Server\Runtime;

/**
 * Worker 进程内 Fiber 列表快照（供健康检查等读取）
 * 由 worker.php 主循环写入，健康检查 detail=1&fibers=1 时读取。
 */
final class WorkerFiberSnapshot
{
    /** @var array<int, array{conn_id: int, status: string, protocol: string}> */
    private static array $snapshot = [];

    /**
     * 设置当前 Worker 的 Fiber 快照（仅 worker.php 主循环调用）
     *
     * @param list<array{conn_id: int, status: string, protocol: string}> $list
     */
    public static function setSnapshot(array $list): void
    {
        self::$snapshot = $list;
    }

    /**
     * 获取最近一次快照（健康检查等调用）
     *
     * @return list<array{conn_id: int, status: string, protocol: string}>
     */
    public static function getSnapshot(): array
    {
        return self::$snapshot;
    }

    public static function getFiberCount(): int
    {
        return \count(self::$snapshot);
    }
}
