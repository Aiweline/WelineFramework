<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\PlaintextWorkerPublicHttpsRedirect;

final class PlaintextWorkerPublicHttpsRedirectTest extends TestCase
{
    public function testDirectWorkerPortHostRedirectsToManagedNginxOrigin(): void
    {
        $location = PlaintextWorkerPublicHttpsRedirect::locationOrNull(
            'p05113ef3.test.weline.com:9555',
            9555,
            'https://p05113ef3.test.weline.com',
            '/jRax/theme/backend/theme-editor/index?theme_id=3',
            'nginx',
        );

        self::assertSame(
            'https://p05113ef3.test.weline.com/jRax/theme/backend/theme-editor/index?theme_id=3',
            $location,
        );
    }

    public function testPureWlsEdgeDoesNotBounceWorkerListenPort(): void
    {
        self::assertNull(PlaintextWorkerPublicHttpsRedirect::locationOrNull(
            'p05113ef3.test.weline.com:9555',
            9555,
            'https://p05113ef3.test.weline.com:9555',
            '/',
            'wls',
        ));
    }

    public function testMissingEdgeAdapterDoesNotBounce(): void
    {
        $prev = $_SERVER['WLS_EDGE_ADAPTER'] ?? null;
        unset($_SERVER['WLS_EDGE_ADAPTER'], $_ENV['WLS_EDGE_ADAPTER']);
        @\putenv('WLS_EDGE_ADAPTER');
        try {
            self::assertNull(PlaintextWorkerPublicHttpsRedirect::locationOrNull(
                'p05113ef3.test.weline.com:9555',
                9555,
                'https://p05113ef3.test.weline.com',
                '/',
                '',
            ));
        } finally {
            if ($prev === null) {
                unset($_SERVER['WLS_EDGE_ADAPTER']);
            } else {
                $_SERVER['WLS_EDGE_ADAPTER'] = $prev;
            }
        }
    }

    public function testNginxBackendHostWithoutWorkerPortIsNotRedirected(): void
    {
        self::assertNull(PlaintextWorkerPublicHttpsRedirect::locationOrNull(
            'p05113ef3.test.weline.com',
            9555,
            'https://p05113ef3.test.weline.com',
            '/api/framework/query-bin',
            'nginx',
        ));
    }

    public function testLoopbackWorkerHealthProbesAreNotRedirected(): void
    {
        self::assertNull(PlaintextWorkerPublicHttpsRedirect::locationOrNull(
            '127.0.0.1:9555',
            9555,
            'https://p05113ef3.test.weline.com',
            '/_wls/health',
            'nginx',
        ));
    }

    public function testPublicHostWorkerHealthPathStaysOnPlaintextListener(): void
    {
        self::assertNull(PlaintextWorkerPublicHttpsRedirect::locationOrNull(
            'p05113ef3.test.weline.com:9555',
            9555,
            'https://p05113ef3.test.weline.com',
            '/_wls/health',
            'nginx',
        ));
    }

    public function testResponseIs308WithLocation(): void
    {
        $response = PlaintextWorkerPublicHttpsRedirect::responseOrNull(
            'p05113ef3.test.weline.com:9555',
            9555,
            'https://p05113ef3.test.weline.com',
            '/',
            'nginx',
        );

        self::assertIsString($response);
        self::assertStringStartsWith('HTTP/1.1 308 Permanent Redirect', $response);
        self::assertStringContainsString(
            'Location: https://p05113ef3.test.weline.com/',
            $response,
        );
    }

    public function testMismatchedHostnameDoesNotRedirect(): void
    {
        self::assertNull(PlaintextWorkerPublicHttpsRedirect::locationOrNull(
            'other.test.weline.com:9555',
            9555,
            'https://p05113ef3.test.weline.com',
            '/',
            'nginx',
        ));
    }

    public function testFallbackWithoutPublicOriginStillBouncesPublicHostWorkerPort(): void
    {
        self::assertSame(
            'https://p05113ef3.test.weline.com/theme/backend/theme-editor',
            PlaintextWorkerPublicHttpsRedirect::locationOrNull(
                'p05113ef3.test.weline.com:9555',
                9555,
                '',
                '/theme/backend/theme-editor',
                'nginx',
            ),
        );
    }
}
