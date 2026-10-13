<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;

/**
 * Shared FPC HIT/STALE must not emit bootstrap Set-Cookie (Worker merges pending).
 */
final class FullPageCacheHitCookieSuppressContractTest extends TestCase
{
    public function testMarkCacheHitSourceClearsCookiesBeforeAndAfterDecorators(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Router/FullPageCacheCoordinator.php'
        );
        self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'cache_hit\')', $src);
        self::assertStringContainsString('Decorators must not reintroduce Set-Cookie', $src);
        self::assertStringContainsString("WelineEnv::set('response.from_cache', true", $src);

        $runtimeSrc = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Server/Runtime/WlsRuntime.php'
        );
        self::assertNotSame('', $runtimeSrc);
        self::assertStringContainsString('SharedFpcSetCookieSuppress::isArmed()', $runtimeSrc);
        self::assertStringContainsString("'cookies' => \$suppressCookies ? [] : \$headerCollector->getCookies()", $runtimeSrc);
    }
}
