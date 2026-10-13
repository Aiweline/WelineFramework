<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Capacity\HostCapacityTier;

/**
 * Writer conf generation needs full Framework BP/DS bootstrap (suite runner).
 * This contract locks the tier fragments + writer injection points without Env.
 */
final class ManagedNginxConfigWriterCapacityTierLimitContractTest extends TestCase
{
    public function testTinyEdgeFragmentsIncludeLimitReqAndConn(): void
    {
        $edge = HostCapacityTier::nginxEdgeLimitFragments(1024);
        self::assertTrue($edge['enabled']);
        self::assertStringContainsString('limit_req_zone $binary_remote_addr zone=wls_req:10m rate=20r/s;', $edge['http_zone_block']);
        self::assertStringContainsString('limit_conn_zone $binary_remote_addr zone=wls_conn:10m;', $edge['http_zone_block']);
        self::assertStringContainsString('limit_req zone=wls_req burst=40 nodelay;', $edge['location_directives']);
        self::assertStringContainsString('limit_conn wls_conn 20;', $edge['location_directives']);
    }

    public function testWriterSourceInjectsEdgeLimitOnOriginNotTlsProbe(): void
    {
        // __DIR__=.../Test/Unit/Service/Edge/Nginx → up 5 = Server
        $path = \dirname(__DIR__, 5) . '/Service/Edge/Nginx/ManagedNginxConfigWriter.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('HostCapacityTier::nginxEdgeLimitFragments()', $src);
        self::assertStringContainsString('{$edgeLimitHttpZone}', $src);
        self::assertStringContainsString('{$edgeLimitLocation}', $src);
        self::assertStringContainsString('location @wls_html_origin', $src);
        self::assertStringContainsString('location = /_wls/nginx/tls-session-probe', $src);
        // Probe block must not reference edgeLimitLocation (exemption).
        $probePos = \strpos($src, 'location = /_wls/nginx/tls-session-probe');
        self::assertNotFalse($probePos);
        $probeSlice = \substr($src, $probePos, 500);
        self::assertStringNotContainsString('edgeLimitLocation', $probeSlice);
        self::assertStringContainsString('{$edgeLimitLocation}', $src);
    }

    public function testDisabledTierEmitsEmptyEdgeFragments(): void
    {
        $edge = HostCapacityTier::nginxEdgeLimitFragments(512, ['enabled' => false]);
        self::assertFalse($edge['enabled']);
        self::assertSame('', $edge['http_zone_block']);
        self::assertSame('', $edge['location_directives']);
    }
}
