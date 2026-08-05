<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Security\EmptySecurityHeaderPolicyOverrideProvider;
use Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;

final class CoreSecurityHeadersTest extends TestCase
{
    private ?SecurityHeaderPolicyOverrideProviderInterface $previousOverrideProvider = null;

    protected function setUp(): void
    {
        HeaderCollector::reset();
        WelineEnv::getInstance()->reset();
        try {
            $provider = ObjectManager::getInstance(SecurityHeaderPolicyOverrideProviderInterface::class);
            if ($provider instanceof SecurityHeaderPolicyOverrideProviderInterface) {
                $this->previousOverrideProvider = $provider;
            }
        } catch (\Throwable) {
        }

        $emptyProvider = new EmptySecurityHeaderPolicyOverrideProvider();
        ObjectManager::setInstance(SecurityHeaderPolicyOverrideProviderInterface::class, $emptyProvider);
    }

    protected function tearDown(): void
    {
        HeaderCollector::reset();
        WelineEnv::getInstance()->reset();
        Env::getInstance()->reload();
        if ($this->previousOverrideProvider !== null) {
            ObjectManager::setInstance(
                SecurityHeaderPolicyOverrideProviderInterface::class,
                $this->previousOverrideProvider,
            );
        } else {
            ObjectManager::removeInstance(SecurityHeaderPolicyOverrideProviderInterface::class);
        }
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

    public function testHeaderXssAddsCorsOnlyForTheCurrentAllowedOrigin(): void
    {
        $env = Env::getInstance()->reload();
        $env->applyRuntimeConfig([
            'security' => [
                'headers' => [
                    'cors_origins' => 'https://allowed.example https://other.example',
                ],
            ],
        ]);
        WelineEnv::setServer('HTTP_ORIGIN', 'https://allowed.example', 'unit-test');

        $router = new Core();
        $router->header_xss();

        $collector = HeaderCollector::getInstance();
        self::assertSame(
            'https://allowed.example',
            $collector->getHeader('Access-Control-Allow-Origin')
        );
        self::assertSame('Origin', $collector->getHeader('Vary'));
    }
}
