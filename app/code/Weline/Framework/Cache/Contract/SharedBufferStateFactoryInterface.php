<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

interface SharedBufferStateFactoryInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function create(array $options = []): ?SharedBufferStateInterface;
}
