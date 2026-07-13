<?php

declare(strict_types=1);

namespace Weline\Framework\View;

interface FrontendLayoutProviderInterface
{
    public function resolve(string $layoutType): ?string;
}
