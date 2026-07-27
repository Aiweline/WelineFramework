<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface StorefrontWebsiteCodeResolverInterface
{
    public function resolveWebsiteCode(string $fullUri): ?string;
}
