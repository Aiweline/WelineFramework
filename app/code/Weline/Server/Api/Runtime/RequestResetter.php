<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;
use Weline\Server\Observer\CacheFlushedObserver;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        WlsMemoryAdapter::resetRequestState();
        CacheFlushedObserver::resetRequestState();
    }
}
