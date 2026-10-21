<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Runtime\WlsRuntime;

final class WlsRuntimeStorefrontCriticalsContractTest extends TestCase
{
    public function testRuntimeDoesNotHardcodeStorefrontBusinessRoute(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(WlsRuntime::class))->getFileName(),
        );

        self::assertStringNotContainsString("'/products'", $source);
        self::assertStringNotContainsString('"/products"', $source);
    }

    public function testProviderPathsChooseCriticalSlotsWithoutBusinessLiteral(): void
    {
        $runtime = (new \ReflectionClass(WlsRuntime::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(WlsRuntime::class, 'storefrontCriticalWarmupPaths');
        $method->setAccessible(true);

        self::assertSame(['/'], $method->invoke($runtime, []));
        self::assertSame(
            ['/', '/products'],
            $method->invoke($runtime, [
                '/' => '/',
                '/ar_SA/' => '/ar_SA/',
                '/products' => '/products',
            ]),
            'A provider path (not a runtime literal) must own the directory-representative slot.',
        );
    }
}
