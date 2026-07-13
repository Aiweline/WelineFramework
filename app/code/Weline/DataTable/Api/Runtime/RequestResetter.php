<?php

declare(strict_types=1);

namespace Weline\DataTable\Api\Runtime;

use Weline\DataTable\Taglib\Field;
use Weline\Framework\Runtime\RequestResetterInterface;

final class RequestResetter implements RequestResetterInterface
{
    public function resetRequest(): void
    {
        Field::resetRequestState();
    }
}
