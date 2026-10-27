<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Runtime\WlsRuntime;

final class WlsRuntimeWarmupHostTest extends TestCase
{
    public function testRegisteredWarmupHostComparisonKeepsWwwAliasButSplitsPreviewHosts(): void
    {
        $runtime = new WlsRuntime();
        $method = new \ReflectionMethod($runtime, 'normalizeWarmupHostForCompare');
        $method->setAccessible(true);

        self::assertSame('changanhanfu.com', $method->invoke($runtime, 'www.changanhanfu.com'));
        self::assertSame('changanhanfu.com', $method->invoke($runtime, 'https://www.changanhanfu.com/'));
        self::assertNotSame(
            'changanhanfu.com',
            $method->invoke($runtime, 'p05113ef3.test.weline.com'),
            'Local preview hosts must not be treated as the production website host.'
        );
    }
}
