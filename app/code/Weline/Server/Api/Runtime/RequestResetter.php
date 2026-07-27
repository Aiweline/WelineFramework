<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Server\Observer\CacheFlushedObserver;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        CacheFlushedObserver::resetRequestState();
    }
}
