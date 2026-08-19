<?php

namespace Weline\Framework\App\test;

use Weline\Framework\App\Env;
use Weline\Framework\Test\TestCore;

class EnvGroupAccessorTest extends TestCore
{
    public function testSystemAccessorReadsAKeyFromTheSystemGroup(): void
    {
        $system = Env::system();

        self::assertIsArray($system);
        self::assertArrayHasKey('currency', $system);
        self::assertSame($system['currency'], Env::system('currency', 'FALLBACK'));
    }

    public function testGroupedAccessorsReturnTheirDeclaredDefaultsForMissingKeys(): void
    {
        self::assertSame('FALLBACK', Env::system('__missing__', 'FALLBACK'));
        self::assertSame('FALLBACK', Env::router('__missing__', 'FALLBACK'));
        self::assertSame('FALLBACK', Env::dev('__missing__', 'FALLBACK'));
    }
}
