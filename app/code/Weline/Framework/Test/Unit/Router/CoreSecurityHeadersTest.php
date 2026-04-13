<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Router\Core;

final class CoreSecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        HeaderCollector::reset();
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
        Env::getInstance()->reload();
    }

    public function testHeaderXssAddsDefaultSecurityHeadersWithoutCspByDefault(): void
    {
        Env::getInstance()->reload();

        $router = new Core();
        $router->header_xss();

        $collector = HeaderCollector::getInstance();
        self::assertSame('SAMEORIGIN', $collector->getHeader('X-Frame-Options'));
        self::assertSame('nosniff', $collector->getHeader('X-Content-Type-Options'));
        self::assertSame('1; mode=block', $collector->getHeader('X-XSS-Protection'));
        self::assertNull($collector->getHeader('Content-Security-Policy'));
        self::assertNull($collector->getHeader('Content-Security-Policy-Report-Only'));
    }

    public function testHeaderXssAddsConfiguredCspHeaders(): void
    {
        $env = Env::getInstance()->reload();
        $env->applyRuntimeConfig([
            'security' => [
                'headers' => [
                    'csp_report_only' => "default-src 'self'; report-uri /csp-report",
                    'csp' => "default-src 'self'",
                ],
            ],
        ]);

        $router = new Core();
        $router->header_xss();

        $collector = HeaderCollector::getInstance();
        self::assertSame(
            "default-src 'self'; report-uri /csp-report",
            $collector->getHeader('Content-Security-Policy-Report-Only')
        );
        self::assertSame("default-src 'self'", $collector->getHeader('Content-Security-Policy'));
    }
}
