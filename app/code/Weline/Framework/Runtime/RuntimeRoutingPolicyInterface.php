<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface RuntimeRoutingPolicyInterface
{
    public function shouldHijackCacheFile(): bool;

    public function shouldHijackSessionFile(): bool;
}
