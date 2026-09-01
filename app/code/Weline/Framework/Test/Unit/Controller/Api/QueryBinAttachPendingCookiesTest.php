<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Controller\Api\QueryBin;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Response;

/**
 * QueryBin detached responses must carry Session cookies written during providers.
 */
final class QueryBinAttachPendingCookiesTest extends TestCase
{
    protected function tearDown(): void
    {
        HeaderCollector::reset();
        parent::tearDown();
    }

    public function testAttachPendingCookiesCopiesSessionCookieOntoDetachedResponse(): void
    {
        HeaderCollector::reset();
        HeaderCollector::getInstance()->setCookie(
            'WELINE_SESSID_9555',
            'sess-from-login',
            \time() + 3600,
            '/',
            '',
            true,
            true,
            'Lax'
        );

        $response = Response::fromContent('bin', 200, 'application/octet-stream');
        self::assertSame([], $response->getCookies());

        $bin = (new ReflectionClass(QueryBin::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(QueryBin::class, 'attachPendingCookies');
        $method->setAccessible(true);
        /** @var Response $out */
        $out = $method->invoke($bin, $response);

        $cookies = $out->getCookies();
        self::assertNotEmpty($cookies);
        $matched = null;
        foreach ($cookies as $cookie) {
            if (($cookie['value'] ?? '') === 'sess-from-login') {
                $matched = $cookie;
                break;
            }
        }
        self::assertNotNull($matched, 'Expected pending session cookie value on detached response');
        self::assertStringContainsString('SESSID', (string)$matched['name']);
        self::assertSame('Lax', $matched['sameSite'] ?? null);
        self::assertNotSame('', (string)($out->getHeader('X-Weline-Query-Bin-Cookies') ?? ''));
    }
}
