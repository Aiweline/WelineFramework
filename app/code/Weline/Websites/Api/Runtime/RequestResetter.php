<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Runtime;

use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Websites\Data\WebsiteData;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        WebsiteData::resetRequestState();
    }
}
