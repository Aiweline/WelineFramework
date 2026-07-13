<?php

declare(strict_types=1);

namespace Weline\Framework\App\Localization;

interface LocaleNameProviderInterface
{
    public function resolveName(string $sourceCode, string $targetCode): ?string;
}
