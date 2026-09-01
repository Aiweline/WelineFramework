<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

require_once __DIR__ . '/../bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\PayPalSandboxPublicOriginService;

final class PayPalSandboxPublicOriginServiceTest extends TestCase
{
    private string $instanceFile;

    protected function setUp(): void
    {
        $this->instanceFile = BP . 'var/server/instances/default.json';
    }

    public function testResolvesPublicOriginFromDefaultWlsInstanceFile(): void
    {
        if (!is_file($this->instanceFile)) {
            self::markTestSkipped('default WLS instance file is unavailable in this environment.');
        }

        $service = new PayPalSandboxPublicOriginService();
        $origin = $service->resolvePublicOrigin();

        self::assertNotSame('', $origin);
        self::assertStringContainsString('weline.test', $origin);
        self::assertStringContainsString(':9555', $origin);
    }

    public function testBuildFrontendPathUrlUsesResolvedOrigin(): void
    {
        if (!is_file($this->instanceFile)) {
            self::markTestSkipped('default WLS instance file is unavailable in this environment.');
        }

        $service = new PayPalSandboxPublicOriginService();
        $url = $service->buildFrontendPathUrl('payment/frontend/callback/return');

        self::assertStringContainsString('/payment/frontend/callback/return', $url);
        self::assertStringStartsWith('https://', $url);
    }

    public function testRejectsMalformedSchemeOnlyOriginViaReflection(): void
    {
        $service = new PayPalSandboxPublicOriginService();
        $method = new \ReflectionMethod($service, 'isUsablePublicOrigin');
        $method->setAccessible(true);

        self::assertFalse((bool) $method->invoke($service, 'http:'));
        self::assertFalse((bool) $method->invoke($service, 'https:'));
        self::assertFalse((bool) $method->invoke($service, ''));
        self::assertTrue((bool) $method->invoke($service, 'https://p05113ef3.weline.test:9555'));
    }
}
