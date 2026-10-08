<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Runtime;

use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Meta\Taglib\WMeta;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class RequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function resetRequest(): void
    {
        WMeta::resetRequestState();
    }
}
