<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Runtime;

use Weline\Acl\Taglib\Acl;
use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class RequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function resetRequest(): void
    {
        Acl::resetRequestState();
    }
}
