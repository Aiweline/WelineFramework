<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Cache\Contract\SharedCacheStateFactoryInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Server\Service\MemoryStateFacade;

final class SharedCacheStateProvider implements SharedCacheStateFactoryInterface
{
    public function create(array $options = []): ?SharedCacheStateInterface
    {
        return new MemoryStateFacade($options);
    }
}
