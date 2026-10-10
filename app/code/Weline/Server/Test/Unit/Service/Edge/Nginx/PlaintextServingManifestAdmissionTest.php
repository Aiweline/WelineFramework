<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\PlaintextServingManifestAdmission;

final class PlaintextServingManifestAdmissionTest extends TestCase
{
    public function testLocalWorkerHealthPathIsProbe(): void
    {
        self::assertTrue(PlaintextServingManifestAdmission::isLocalWorkerProbePath('/_wls/health'));
        self::assertTrue(PlaintextServingManifestAdmission::isLocalWorkerProbePath('/_wls/health?detail=1'));
        self::assertTrue(PlaintextServingManifestAdmission::isLocalWorkerProbePath('/_wls'));
        self::assertFalse(PlaintextServingManifestAdmission::isLocalWorkerProbePath('/'));
        self::assertFalse(PlaintextServingManifestAdmission::isLocalWorkerProbePath('/checkout'));
    }

    public function testGatewayTerminatedHttpsFromForwardedProto(): void
    {
        self::assertTrue(PlaintextServingManifestAdmission::hasGatewayTerminatedHttps([
            'x-forwarded-proto' => 'https',
        ]));
        self::assertTrue(PlaintextServingManifestAdmission::hasGatewayTerminatedHttps([
            'x-forwarded-proto' => 'HTTPS',
        ]));
        self::assertTrue(PlaintextServingManifestAdmission::hasGatewayTerminatedHttps([
            'x-forwarded-proto' => 'https, http',
        ]));
        self::assertFalse(PlaintextServingManifestAdmission::hasGatewayTerminatedHttps([
            'x-forwarded-proto' => 'http',
        ]));
        self::assertFalse(PlaintextServingManifestAdmission::hasGatewayTerminatedHttps([]));
    }

    public function testForceHttpsBypassForProbeAndGateway(): void
    {
        self::assertTrue(PlaintextServingManifestAdmission::shouldAllowDespiteForceHttps(
            '/_wls/health',
            ['host' => '127.0.0.1'],
        ));
        self::assertTrue(PlaintextServingManifestAdmission::shouldAllowDespiteForceHttps(
            '/',
            ['host' => 'p05113ef3.test.weline.com', 'x-forwarded-proto' => 'https'],
        ));
        self::assertFalse(PlaintextServingManifestAdmission::shouldAllowDespiteForceHttps(
            '/',
            ['host' => 'p05113ef3.test.weline.com:9555'],
        ));
    }
}
