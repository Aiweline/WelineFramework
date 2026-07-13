<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Runtime;

use Weline\Acl\Taglib\Acl;
use Weline\Framework\Runtime\RequestResetterInterface;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        Acl::resetRequestState();
    }
}
