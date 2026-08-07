<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\SharedStateRuntimeScope;

final class SharedStateRuntimeScopeTest extends TestCase
{
    public function testUnixTokensUseDedicatedLowCardinalityStateDirectory(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows already uses a project-scoped local application-data directory.');
        }

        self::assertSame(
            BP . 'var/session/.wls-state/tokens/',
            \str_replace('\\', '/', SharedStateRuntimeScope::tokenDirectory()),
        );
        self::assertSame(
            BP . 'var/session/.wls-state/tokens/session_server.test.token',
            \str_replace(
                '\\',
                '/',
                SharedStateRuntimeScope::tokenFilePath('../session_server.test.token'),
            ),
        );
    }
}
