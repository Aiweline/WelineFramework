<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Service\CommerceCartTypeRegistry;
use Weline\Cart\Service\SellingTypeResolver;

final class SellingTypeResolverTest extends TestCase
{
    public function testUnregisteredPreferenceFallsBackToToc(): void
    {
        $resolver = SellingTypeResolver::forTesting(CommerceCartTypeRegistry::forTesting());
        $resolved = $resolver->resolve('tob', true, 1, 0);

        self::assertSame(CommerceCartTypeRegistry::CODE_TOC, $resolved['code']);
        self::assertSame(CommerceCartTypeRegistry::CODE_TOC, $resolved['type']->getCode());
    }

    public function testEmptyPreferenceUsesToc(): void
    {
        $resolver = SellingTypeResolver::forTesting(CommerceCartTypeRegistry::forTesting());
        $resolved = $resolver->resolve('', false);

        self::assertSame(CommerceCartTypeRegistry::CODE_TOC, $resolved['code']);
    }
}
