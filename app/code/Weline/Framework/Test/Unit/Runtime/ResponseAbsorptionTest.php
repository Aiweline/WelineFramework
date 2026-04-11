<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\WlsRuntime;

final class ResponseAbsorptionTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
        ObjectManager::removeInstance(Request::class);
    }

    public function testWlsRuntimeAbsorbsStandaloneResponseIntoCurrentRequestResponse(): void
    {
        $request = new class {
            private Response $response;

            public function __construct()
            {
                $this->response = new Response();
            }

            public function getResponse(): Response
            {
                return $this->response;
            }
        };

        ObjectManager::setInstance(Request::class, $request);

        $runtime = new WlsRuntime();
        $method = new ReflectionMethod(WlsRuntime::class, 'absorbResponseObject');
        $method->setAccessible(true);

        $detached = Response::json(['ok' => true], 202);
        $detached->setHeader('X-Test', 'value');
        $detached->setCookie('sid', 'abc', 0, '/');

        $body = $method->invoke($runtime, $detached);

        self::assertSame($detached->getBody(), $body);
        self::assertSame(202, $request->getResponse()->getStatusCode());
        self::assertSame('value', $request->getResponse()->getHeader('X-Test'));
        self::assertSame('abc', $request->getResponse()->getCookies()['sid']['value'] ?? null);
    }
}
