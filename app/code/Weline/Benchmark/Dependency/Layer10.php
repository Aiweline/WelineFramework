<?php

declare(strict_types=1);

namespace Weline\Benchmark\Dependency;

class Layer10
{
    public function __construct(private readonly Layer09 $previous)
    {
    }

    public function depth(): int
    {
        return $this->previous->depth() + 1;
    }
}
