<?php

declare(strict_types=1);

namespace Weline\Benchmark\Dependency;

class Layer09
{
    public function __construct(private readonly Layer08 $previous)
    {
    }

    public function depth(): int
    {
        return $this->previous->depth() + 1;
    }
}
