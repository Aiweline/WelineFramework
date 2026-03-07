<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

final class ChildProcessIdentity
{
    public function __construct(
        public readonly string $role,
        public readonly int $pid,
        public readonly int $port = 0,
        public readonly int $workerId = 0,
        public readonly int $epoch = 0,
        public readonly string $launchId = ''
    ) {
    }
}

