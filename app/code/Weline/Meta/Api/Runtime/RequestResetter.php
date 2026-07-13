<?php

declare(strict_types=1);

namespace Weline\Meta\Api\Runtime;

use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Meta\Taglib\WMeta;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        WMeta::resetRequestState();
    }
}
