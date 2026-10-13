<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Fpc\SharedFpcSetCookieSuppress;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Runtime\RequestContext;

final class SharedFpcSetCookieSuppressContractTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
        RequestContext::cleanup();
        WelineEnv::set('response.from_cache', false, 'unit-test');
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
        RequestContext::cleanup();
        WelineEnv::set('response.from_cache', false, 'unit-test');
    }

    public function testArmClearsPendingCookiesAndBlocksFurtherSetCookie(): void
    {
        $collector = HeaderCollector::getInstance();
        $collector->setCookie('WELINE_SESSID', 'before', \time() + 3600, '/', '', true, true, 'Lax');
        self::assertNotSame([], $collector->getCookies());

        SharedFpcSetCookieSuppress::arm('unit_build');
        self::assertTrue(SharedFpcSetCookieSuppress::isArmed());
        self::assertSame([], $collector->getCookies());

        $collector->setCookie('WELINE_SESSID', 'after', \time() + 3600, '/', '', true, true, 'Lax');
        self::assertSame([], $collector->getCookies());
    }

    public function testFromCacheAloneArmsSuppress(): void
    {
        self::assertFalse(SharedFpcSetCookieSuppress::isArmed());
        WelineEnv::set('response.from_cache', true, 'unit-test');
        self::assertTrue(SharedFpcSetCookieSuppress::isArmed());
    }

    public function testWiringSourceCoversBuildHitAndPublish(): void
    {
        // This file lives at Framework/Test/Unit/Http/Fpc/ → up 4 = Framework.
        $frameworkRoot = \dirname(__DIR__, 4);
        $corePath = $frameworkRoot . '/Router/Core.php';
        self::assertFileExists($corePath);
        $core = (string)\file_get_contents($corePath);
        self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'build_lock\')', $core);
        self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'anonymous_shareable_ssr\')', $core);

        $coordinator = (string)\file_get_contents($frameworkRoot . '/Router/FullPageCacheCoordinator.php');
        self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'cache_hit\')', $coordinator);
        self::assertStringContainsString('SharedFpcSetCookieSuppress::arm(\'can_publish\')', $coordinator);

        $strategy = (string)\file_get_contents($frameworkRoot . '/Session/Strategy/WlsStrategy.php');
        self::assertStringContainsString('SharedFpcSetCookieSuppress::isArmed()', $strategy);
    }
}
