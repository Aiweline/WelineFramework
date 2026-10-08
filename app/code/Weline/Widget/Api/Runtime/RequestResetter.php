<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Runtime;

use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Widget\Taglib\Widget;
use Weline\Framework\Runtime\ProcessSharedInterface;


final class RequestResetter implements ProcessSharedInterface, RequestResetterInterface
{
    public function resetRequest(): void
    {
        Widget::resetRequestState();
    }
}
