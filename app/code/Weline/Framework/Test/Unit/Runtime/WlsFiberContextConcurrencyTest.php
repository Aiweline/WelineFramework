<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\WlsFiberContext;

final class WlsFiberContextConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        SseContext::reset();
        RequestContext::cleanup();
        parent::tearDown();
    }

    public function testRestoreSyncsRequestContextWelineStaticsAfterGlobalCleanup(): void
    {
        $_SERVER['WELINE_AREA'] = 'backend';
        $_SERVER['WELINE_USER_LANG'] = 'en_US';
        RequestContext::syncFromServer();

        $ctx = WlsFiberContext::capture();

        RequestContext::cleanup();

        self::assertSame(RequestContext::AREA_FRONTEND, RequestContext::getWelineArea());

        $ctx->restore();

        self::assertSame('backend', $_SERVER['WELINE_AREA']);
        self::assertSame('backend', RequestContext::getWelineArea());
        self::assertSame('en_US', RequestContext::getWelineUserLang());
    }

    public function testRestoreSuperglobalsIncludeCookieRequestFiles(): void
    {
        $_SERVER['WELINE_AREA'] = 'frontend';
        $_GET = ['a' => '1'];
        $_POST = ['b' => '2'];
        $_COOKIE = ['sid' => 'abc'];
        $_REQUEST = ['a' => '1', 'b' => '2'];
        $_FILES = [];

        $ctx = WlsFiberContext::capture();

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_REQUEST = [];
        $_SERVER = ['WELINE_AREA' => 'frontend'];

        $ctx->restore();

        self::assertSame(['a' => '1'], $_GET);
        self::assertSame(['b' => '2'], $_POST);
        self::assertSame(['sid' => 'abc'], $_COOKIE);
        self::assertSame(['a' => '1', 'b' => '2'], $_REQUEST);
    }
}
