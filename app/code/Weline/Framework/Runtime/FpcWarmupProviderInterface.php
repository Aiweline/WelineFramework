<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface FpcWarmupProviderInterface
{
    public function warmProcessFastPathPayloads(): void;

    /** @return list<string> */
    public function warmupPaths(): array;
}
