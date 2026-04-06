<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\WlsFiberContext;

final class WlsFiberContextConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        HeaderCollector::reset();
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

    public function testRestoreReinstatesCapturedResponseProtocolState(): void
    {
        $collector = HeaderCollector::getInstance();
        $collector->setHeader('Content-Type', 'text/html; charset=utf-8');
        $collector->setHeader('X-WLS-Link-Protocol', 'doc/http');
        $collector->setCookie('sid', 'doc-session', 0, '/');
        $collector->setStatusCode(202);

        $ctx = WlsFiberContext::capture();

        $collector->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $collector->setHeader('X-WLS-Link-Protocol', 'text/http');
        $collector->setCookie('sid', 'text-session', 0, '/');
        $collector->setStatusCode(500);

        $ctx->restore();

        self::assertSame('text/html; charset=utf-8', $collector->getHeader('Content-Type'));
        self::assertSame('doc/http', $collector->getHeader('X-WLS-Link-Protocol'));
        self::assertSame(202, $collector->getStatusCode());
        self::assertTrue($collector->hasExplicitStatusCode());
        self::assertSame('doc-session', $collector->getCookies()['sid']['value'] ?? null);
    }

    public function testRestoreWithoutResponseStateDoesNotReplayStaleHeaders(): void
    {
        $collector = HeaderCollector::getInstance();
        $collector->setHeader('Content-Type', 'text/html; charset=utf-8');
        $collector->setHeader('X-WLS-Link-Protocol', 'doc/http');
        $collector->setStatusCode(201);

        $ctx = WlsFiberContext::capture();

        $collector->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $collector->setHeader('X-WLS-Link-Protocol', 'text/http');
        $collector->setStatusCode(500);

        $ctx->restore(false);

        self::assertSame('text/plain; charset=utf-8', $collector->getHeader('Content-Type'));
        self::assertSame('text/http', $collector->getHeader('X-WLS-Link-Protocol'));
        self::assertSame(500, $collector->getStatusCode());
    }
}
