<?php
declare(strict_types=1);

namespace Weline\Server\EventLoop;

interface EventLoopInterface
{
    /**
     * 等待 I/O 事件（与 stream_select 语义一致）
     *
     * @param array<int|string, resource> $read
     * @param array<int|string, resource> $write
     * @param array<int|string, resource> $except
     * @return int|false
     */
    public function wait(
        array &$read,
        array &$write,
        array &$except,
        int $timeoutSec,
        int $timeoutUsec
    ): int|false;

    public function backend(): string;
}

