<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Websites\ScopeDisplayType;

use Weline\Websites\Api\ScopeDisplayTypeProviderInterface;

final class B2B implements ScopeDisplayTypeProviderInterface
{
    public const CODE = 'b2b';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('B2B 批发展示');
    }

    public function getModule(): string
    {
        return 'Weline_B2B';
    }

    public function getSortOrder(): int
    {
        return 10;
    }
}
