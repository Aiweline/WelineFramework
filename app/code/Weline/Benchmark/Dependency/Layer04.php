<?php

declare(strict_types=1);

namespace Weline\Benchmark\Dependency;

class Layer04
{
    public function __construct(private readonly Layer03 $previous)
    {
    }

    public function depth(): int
    {
        return $this->previous->depth() + 1;
    }
}
