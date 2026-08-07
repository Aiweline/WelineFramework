<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostBootIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayLeaseIdentity;
use Weline\Server\Service\Edge\Gateway\GatewayRuntimeEndpointPublisher;

final class GatewayRuntimeEndpointPublisherTest extends TestCase
{
    public function testHealthyObservationChangesOnlyTheRuntimeProjection(): void
    {
        $endpoint = $this->endpoint();
        $endpoint += [
            'port' => 26439,
            'edge_adapter' => 'wls',
        ];
        $endpoint['gateway']['mode'] = 'wls';
        $endpoint['gateway']['certificate_generation'] = 7;
        $endpoint['gateway']['degraded_reason'] = 'GATEWAY_UNAVAILABLE';
        $endpoint['gateway']['fallback_state'] = 'DEGRADED_WLS';
        $status = $this->healthyStatus();
        $updated = GatewayRuntimeEndpointPublisher::applyHealthyObservation(
            $endpoint,
            $status,
            'DRAINING',
            $this->servingProof($endpoint, $status),
            1800000000,
            123.0,
        );

        self::assertSame(26439, $updated['port']);
        self::assertSame('wls', $updated['edge_adapter']);
        self::assertSame($this->projectUuid(), $updated['gateway']['project_uuid']);
        self::assertSame(7, $updated['gateway']['certificate_generation']);
        self::assertSame('wls', $updated['gateway']['mode']);
        self::assertSame('gateway', $updated['gateway']['serving_mode']);
        self::assertSame('wls-edge/2', $updated['gateway']['protocol']);
        self::assertSame(80, $updated['gateway']['public_http']);
        self::assertSame(443, $updated['gateway']['public_https']);
        self::assertSame('', $updated['gateway']['degraded_reason']);
        self::assertSame('NATIVE_EDGE_DRAINING', $updated['gateway']['fallback_state']);
    }

    public function testFallbackObservationPreservesProjectFactsAndPublishesLease(): void
    {
        $endpoint = $this->endpoint();
        $endpoint['edge_adapter'] = 'wls';
        $endpoint['gateway']['mode'] = 'gateway';
        $endpoint['gateway']['certificate_generation'] = 7;
        $updated = GatewayRuntimeEndpointPublisher::applyFallbackObservation(
            $endpoint,
            27673,
            'GATEWAY_DATA_PLANE_UNAVAILABLE',
            1800000000,
            $this->fallbackLeaseProof($endpoint, 27673),
            123.0,
        );

        self::assertSame('wls', $updated['edge_adapter']);
        self::assertSame($this->projectUuid(), $updated['gateway']['project_uuid']);
        self::assertSame(7, $updated['gateway']['certificate_generation']);
        self::assertSame('gateway', $updated['gateway']['mode']);
        self::assertSame('fallback_wls', $updated['gateway']['serving_mode']);
        self::assertSame(0, $updated['gateway']['public_http']);
        self::assertSame(27673, $updated['gateway']['public_https']);
        self::assertSame('DEGRADED_WLS', $updated['gateway']['fallback_state']);
    }

    public function testDrainingFallbackLeaseCannotBePublishedAsAccepting(): void
    {
        $endpoint = $this->endpoint();
        $proof = $this->fallbackLeaseProof($endpoint, 27673);
        $proof['state'] = 'DRAINING';
        unset($proof['proof_digest']);
        $proof['proof_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($proof),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fallback lease proof is invalid');
        GatewayRuntimeEndpointPublisher::applyFallbackObservation(
            $endpoint,
            27673,
            'GATEWAY_DATA_PLANE_UNAVAILABLE',
            1800000000,
            $proof,
            123.0,
        );
    }

    public function testFallbackProofBuildSelectsAnyLiveListenerOwner(): void
    {
        $method = new \ReflectionMethod(
            GatewayRuntimeEndpointPublisher::class,
            'buildFallbackLeaseProof',
        );
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString('->liveServingLeaseForAnyOwner(', $source);
        self::assertStringNotContainsString('->liveServingLease(', $source);
    }

    public function testUnauthenticatedHealthyObservationIsRejected(): void
    {
        $status = $this->healthyStatus();
        $status['project_ready'] = false;

        $this->expectException(\RuntimeException::class);
        GatewayRuntimeEndpointPublisher::applyHealthyObservation(
            $this->endpoint(),
            $status,
            'ACTIVE',
            [],
            1800000000,
        );
    }

    public function testFallbackAuthorityMustBeCoveredByServingManifest(): void
    {
        $method = new \ReflectionMethod(
            GatewayRuntimeEndpointPublisher::class,
            'fallbackAuthorityForManifest',
        );
        $method->setAccessible(true);
        $manifest = ['payload' => ['routes' => [
            ['domain' => 'shop.example.test'],
            ['domain' => 'certificate.example.test'],
        ]]];

        self::assertSame('shop.example.test', $method->invoke(null, [
            'public_host' => 'Shop.Example.Test.',
        ], $manifest));
        self::assertSame('certificate.example.test', $method->invoke(null, [
            'public_host' => 'user@example.test',
            'ssl_domain' => 'certificate.example.test',
        ], $manifest));

        foreach ([
            'user@example.test',
            'example.test:443',
            'example.test?tenant=other',
            'example.test#other',
            "example.test\nother.test",
            '*.example.test',
            'localhost',
            '0.0.0.0',
            '::',
            '[::1]',
            '2001:0db8::1',
            '127.000.000.001',
        ] as $candidate) {
            self::assertSame('shop.example.test', $method->invoke(null, [
                'public_host' => $candidate,
                'ssl_domain' => $candidate,
            ], $manifest), $candidate);
        }
        self::assertSame('tenant.example.test', $method->invoke(null, [
            'public_host' => 'tenant.example.test',
        ], ['payload' => ['routes' => [['domain' => '*.example.test']]] ]));

        $this->expectException(\RuntimeException::class);
        $method->invoke(null, [
            'public_host' => '*',
        ], ['payload' => ['routes' => [['domain' => '*.example.test']]] ]);
    }

    public function testServingProofRequiresRedirectTargetInSameActivePublication(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(
                GatewayRuntimeEndpointPublisher::class,
            ))->getFileName(),
        );

        self::assertStringContainsString(
            '\'www.\' . (string)$local[\'domain\']',
            $source,
        );
        self::assertStringContainsString('isset($active[$targetRouteId])', $source);
        self::assertStringContainsString(
            '!\\hash_equals($rootToWwwTarget, $remoteRootToWwwTarget)',
            $source,
        );
        self::assertStringContainsString(
            '$remoteRedirectTargetReady !== true',
            $source,
        );
        self::assertStringContainsString('|| !$redirectTargetInActiveSet', $source);
    }

    public function testServingProofSelectsNumericInstanceIdFromWireList(): void
    {
        $method = new \ReflectionMethod(
            GatewayRuntimeEndpointPublisher::class,
            'backendInstanceForServingProof',
        );
        $row = [
            'instance_id' => '0',
            'backends' => [[
                'host' => '127.0.0.1',
                'port' => 9502,
                'weight' => 1,
            ]],
            'backend_identity' => ['schema' => 'test-public-identity'],
        ];

        self::assertSame($row, $method->invoke(null, [$row], '0'));

        $this->expectException(\RuntimeException::class);
        $method->invoke(null, ['01' => $row], '0');
    }

    public function testHealthyProjectionPublicationIsBoundedByAgentTickDeadline(): void
    {
        $publisher = (string)\file_get_contents(
            (string)(new \ReflectionClass(
                GatewayRuntimeEndpointPublisher::class,
            ))->getFileName(),
        );
        $agent = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Console/Server/Gateway/Agent.php',
        );

        self::assertStringContainsString(
            '?float $deadlineMonotonic = null',
            $publisher,
        );
        self::assertMatchesRegularExpression(
            '/\$routeGenerations,\s*\\\\count\(\$routes\) === '
                . '\\\\count\(\$desired\),\s*\$deadlineMonotonic,\s*\)/',
            $publisher,
        );
        self::assertStringContainsString(
            'self::remainingDeadlineSeconds($deadlineMonotonic, 5.0)',
            $publisher,
        );
        self::assertMatchesRegularExpression(
            '/->publishHealthy\([\s\S]*?\$activeRouteIds,\s*\$tickDeadline,\s*\)/',
            $agent,
        );
    }

    public function testFallbackProjectionPublicationIsBoundedByAgentTickDeadline(): void
    {
        $method = new \ReflectionMethod(
            GatewayRuntimeEndpointPublisher::class,
            'publishFallbackActive',
        );
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $publisher = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
        $agent = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Console/Server/Gateway/Agent.php',
        );

        self::assertMatchesRegularExpression(
            '/function publishFallbackActive\([\s\S]*?'
                . '\?float \$deadlineMonotonic = null,\s*\): bool/',
            $publisher,
        );
        self::assertMatchesRegularExpression(
            '/updateJsonFileAtomically\([\s\S]*?'
                . '\$endpointWriteTimeout,\s*\);/',
            $publisher,
        );
        self::assertMatchesRegularExpression(
            '/->publishFallbackActive\([\s\S]*?'
                . "'GATEWAY_DATA_PLANE_UNAVAILABLE',\s*"
                . '\$tickDeadline,\s*\)/',
            $agent,
        );
    }

    /** @return array<string,mixed> */
    private function healthyStatus(): array
    {
        return [
            'ok' => true,
            'ready' => true,
            'project_ready' => true,
            'state' => 'HEALTHY',
            'protocol' => 'wls-edge/2',
            'epoch' => \str_repeat('a', 32),
            'generation' => 88,
            'active_config_generation' => 88,
            'active_config_digest' => \str_repeat('b', 64),
            'project_uuid' => $this->projectUuid(),
            'project_generation' => 11,
            'request_digest' => \str_repeat('c', 64),
            'non_certificate_desired_digest' => \str_repeat('d', 64),
            'host_boot_id' => GatewayHostBootIdentity::current(),
            'public_http' => 80,
            'public_https' => 443,
            'data_plane' => ['running' => true],
        ];
    }

    /** @return array<string,mixed> */
    private function endpoint(): array
    {
        return [
            'master_pid' => 12345,
            'master_epoch' => 9,
            'public_host' => 'shop.example.test',
            'gateway' => [
                'project_uuid' => $this->projectUuid(),
                'instance_id' => 'publisher-unit',
                'instance_generation' => 4,
                'launch_id' => \str_repeat('e', 32),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function servingProof(array $endpoint, array $status): array
    {
        $proof = [
            'schema_version' => 2,
            'project_uuid' => $this->projectUuid(),
            'instance_id' => 'publisher-unit',
            'project_generation' => 11,
            'request_digest' => \str_repeat('c', 64),
            'non_certificate_desired_digest' => \str_repeat('d', 64),
            'instance_generation' => 4,
            'instance_digest' => \str_repeat('f', 64),
            'master_pid' => 12345,
            'master_epoch' => 9,
            'launch_id' => \str_repeat('e', 32),
            'gateway_epoch' => \str_repeat('a', 32),
            'host_boot_id' => (string)$status['host_boot_id'],
            'active_config_generation' => 88,
            'active_config_digest' => \str_repeat('b', 64),
            'serving_manifest_generation' => 3,
            'serving_manifest_digest' => \str_repeat('1', 64),
            'active_routes' => [[
                'route_id' => \str_repeat('2', 32),
                'domain' => 'shop.example.test',
                'route_generation' => 5,
                'certificate_generation' => 6,
                'certificate_source_digest' => \str_repeat('3', 64),
                'backend_public_digest' => \str_repeat('4', 64),
                'force_https' => true,
                'force_root_to_www' => false,
                'root_to_www_target' => '',
                'root_to_www_target_ready' => true,
            ]],
            'public_probe_verified' => true,
        ];
        $proof['proof_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($proof),
        );
        return $proof;
    }

    /** @return array<string,mixed> */
    private function fallbackLeaseProof(array $endpoint, int $port): array
    {
        $proof = [
            'schema_version' => 2,
            'project_uuid' => $this->projectUuid(),
            'instance_id' => 'publisher-unit',
            'lease_instance_id' => GatewayLeaseIdentity::forRole(
                'publisher-unit',
                GatewayLeaseIdentity::ROLE_FALLBACK,
            ),
            'lease_id' => \str_repeat('5', 32),
            'bind_host' => '127.0.0.1',
            'authority_host' => 'shop.example.test',
            'port' => $port,
            'master_pid' => (int)$endpoint['master_pid'],
            'worker_launch_id' => \str_repeat('6', 32),
            'state' => 'ACTIVE',
            'confirmed_timestamp' => 1800000000,
            'serving_manifest_generation' => 3,
            'serving_manifest_digest' => \str_repeat('1', 64),
        ];
        $proof['proof_digest'] = \hash(
            'sha256',
            GatewayClient::canonicalJson($proof),
        );
        return $proof;
    }

    private function projectUuid(): string
    {
        return '123e4567-e89b-42d3-a456-426614174000';
    }
}
