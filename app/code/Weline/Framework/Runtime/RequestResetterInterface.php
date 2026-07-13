<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface RequestResetterInterface
{
    public function resetRequest(): void;
}
