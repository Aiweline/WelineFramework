<?php

declare(strict_types=1);

namespace Weline\Server\IPC\ChildControl;

/**
 * Loop hook container to keep script migration incremental.
 */
final class ChildLoopHooks
{
    /**
     * @param null|callable():void $beforeTick
     * @param null|callable():void $afterTick
     */
    public function __construct(
        public readonly mixed $beforeTick = null,
        public readonly mixed $afterTick = null
    ) {
    }
}

