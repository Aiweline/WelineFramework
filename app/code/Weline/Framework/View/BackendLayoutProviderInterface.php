<?php

declare(strict_types=1);

namespace Weline\Framework\View;

interface BackendLayoutProviderInterface
{
    public function resolve(string $layoutType, string $layoutOption): ?string;
}
