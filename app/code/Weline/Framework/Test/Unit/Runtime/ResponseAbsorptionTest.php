<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\WlsRuntime;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
if (!\function_exists('w_env_set')) {
    require_once BP . 'app/code/Weline/Framework/Common/functions.php';
}

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

    public function testHeaderCollectorKeepsSameCookieNameAcrossDifferentPaths(): void
    {
        $collector = HeaderCollector::getInstance();
        $collector->setCookie('w_sandbox', '', \time() - 3600, '/');
        $collector->setCookie('w_sandbox', '', \time() - 3600, '/backend');

        $cookies = \array_values($collector->getCookies());

        self::assertCount(2, $cookies);
        self::assertSame(['/', '/backend'], \array_column($cookies, 'path'));

        $headers = $collector->toHttpHeaderString();
        self::assertStringContainsString('Set-Cookie: w_sandbox=; Expires=', $headers);
        self::assertStringContainsString('Path=/', $headers);
        self::assertStringContainsString('Path=/backend', $headers);
    }

    public function testCookieDeleteUsesExpiredSetCookieHeader(): void
    {
        Cookie::delete('w_sandbox', ['path' => '/']);

        $cookies = HeaderCollector::getInstance()->getCookies();

        self::assertArrayHasKey('w_sandbox', $cookies);
        self::assertSame('', $cookies['w_sandbox']['value'] ?? null);
        self::assertLessThan(\time(), $cookies['w_sandbox']['expire'] ?? 0);
    }
}
