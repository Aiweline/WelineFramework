<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Cache\Contract\SharedBufferStateFactoryInterface;
use Weline\Framework\Cache\Contract\SharedBufferStateInterface;
use Weline\Server\Service\MemoryStateFacade;

final class SharedBufferStateProvider implements SharedBufferStateFactoryInterface
{
    public function create(array $options = []): ?SharedBufferStateInterface
    {
        return new MemoryStateFacade($options);
    }
}
