<?php
declare(strict_types=1);

namespace Weline\Server\EventLoop;

final class SelectEventLoop implements EventLoopInterface
{
    public function wait(
        array &$read,
        array &$write,
        array &$except,
        int $timeoutSec,
        int $timeoutUsec
    ): int|false {
        return @\stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
    }

    public function backend(): string
    {
        return 'select';
    }
}

