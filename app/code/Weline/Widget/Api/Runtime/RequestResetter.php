<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Runtime;

use Weline\Framework\Runtime\RequestResetterInterface;
use Weline\Widget\Taglib\Widget;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        Widget::resetRequestState();
    }
}
