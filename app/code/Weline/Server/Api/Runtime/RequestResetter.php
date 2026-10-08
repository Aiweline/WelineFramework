<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Server\Observer\CacheFlushedObserver;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class RequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function resetRequest(): void
    {
        CacheFlushedObserver::resetRequestState();
    }
}
