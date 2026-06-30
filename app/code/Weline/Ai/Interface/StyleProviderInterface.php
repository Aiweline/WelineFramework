<?php

declare(strict_types=1);

namespace Weline\Ai\Interface;

interface StyleProviderInterface
{
    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function listStyles(): array;
}
