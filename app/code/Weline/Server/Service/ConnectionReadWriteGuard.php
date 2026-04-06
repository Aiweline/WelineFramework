<?php
declare(strict_types=1);

namespace Weline\Server\Service;

final class ConnectionReadWriteGuard
{
    /**
     * 当连接上仍有待发送响应，或已经标记为待关闭时，禁止继续读取下一个请求。
     *
     * @param array<int|string, string> $writeBuffers
     * @param array<int|string, mixed> $pendingClose
     */
    public static function shouldDeferRead(
        array $writeBuffers,
        array $pendingClose,
        int|string $connId,
        bool $hasActiveRequest = false
    ): bool
    {
        if ($hasActiveRequest) {
            return true;
        }

        if (isset($pendingClose[$connId])) {
            return true;
        }

        return isset($writeBuffers[$connId]) && $writeBuffers[$connId] !== '';
    }
}
