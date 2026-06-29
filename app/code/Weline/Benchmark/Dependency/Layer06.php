<?php

declare(strict_types=1);

namespace Weline\Benchmark\Dependency;

class Layer06
{
    public function __construct(private readonly Layer05 $previous)
    {
    }

    public function depth(): int
    {
        return $this->previous->depth() + 1;
    }
}
