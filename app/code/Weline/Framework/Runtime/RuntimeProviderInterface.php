<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface RuntimeProviderInterface
{
    public function supports(string $mode): bool;

    public function create(string $mode): RuntimeInterface;
}
