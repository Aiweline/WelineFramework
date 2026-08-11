<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayBootstrapAdmissionProjectionTest extends TestCase
{
    public function testHealthyIsolationAndRouteDegradedAreDistinctAdmissions(): void
    {
        $healthy = $this->projection('HEALTHY', 'NONE', false, true, '');
        self::assertSame('HEALTHY', $healthy['admission_state']);
        self::assertTrue($healthy['promotion_eligible']);
        self::assertTrue($healthy['guardian_continuity_healthy']);

        $isolation = $this->projection(
            'STATE_REBUILD',
            'STATE_REBUILD',
            true,
            false,
            '',
        );
        self::assertSame('ISOLATION_REPLAY', $isolation['admission_state']);
        self::assertFalse($isolation['promotion_eligible']);
        self::assertTrue($isolation['guardian_continuity_healthy']);
        foreach (['STATE_MISSING', 'INSTANCE_RETIREMENT_REBUILD'] as $rebuildState) {
            $rebuild = $this->projection(
                $rebuildState,
                'STATE_REBUILD',
                true,
                false,
                '',
            );
            self::assertSame('ISOLATION_REPLAY', $rebuild['admission_state']);
            self::assertFalse($rebuild['promotion_eligible']);
        }

        $routeDegraded = $this->projection(
            'ROUTE_DEGRADED',
            'ROUTE_DEGRADED',
            false,
            false,
            'backend_transport',
        );
        self::assertSame('ROUTE_DEGRADED', $routeDegraded['admission_state']);
        self::assertFalse($routeDegraded['promotion_eligible']);
        self::assertTrue($routeDegraded['guardian_continuity_healthy']);
        self::assertSame(
            'backend_transport',
            $routeDegraded['route_failure_kind'],
        );
    }

    public function testIncompleteOrUnsafeAdmissionCombinationsAreRejected(): void
    {
        $cases = [];
        $cases['nginx-not-running'] = $this->projection(
            'STATE_REBUILD',
            'STATE_REBUILD',
            true,
            false,
            '',
            dataPlane: ['ok' => true, 'running' => false],
        );
        $cases['control-not-ready'] = $this->projection(
            'STATE_REBUILD',
            'STATE_REBUILD',
            true,
            false,
            '',
            controlPlaneReady: false,
        );
        $cases['generation-not-published'] = $this->projection(
            'STATE_REBUILD',
            'STATE_REBUILD',
            true,
            false,
            '',
            activeGeneration: 6,
        );
        $cases['isolation-health-mismatch'] = $this->projection(
            'SECURITY_MUTATION_FAILED_CLOSED',
            'STATE_REBUILD',
            true,
            false,
            '',
        );
        $cases['route-reason-missing'] = $this->projection(
            'ROUTE_DEGRADED',
            'ROUTE_DEGRADED',
            false,
            false,
            '',
        );
        $cases['service-tree-restart'] = $this->projection(
            'HEALTHY',
            'NONE',
            false,
            true,
            '',
            serviceTreeRestartRequested: true,
        );

        foreach ($cases as $name => $projection) {
            self::assertSame('REJECTED', $projection['admission_state'], $name);
            self::assertFalse($projection['promotion_eligible'], $name);
            self::assertFalse($projection['guardian_continuity_healthy'], $name);
        }
    }

    public function testPublicationPendingPreservesGuardianContinuityWithoutPromotion(): void
    {
        $pending = $this->projection(
            'HEALTHY',
            'NONE',
            false,
            true,
            '',
            activeGeneration: 6,
            publicationPending: true,
        );
        self::assertSame('PUBLICATION_PENDING', $pending['admission_state']);
        self::assertFalse($pending['promotion_eligible']);
        self::assertTrue($pending['guardian_continuity_healthy']);

        foreach ([
            'data-plane-down' => $this->projection(
                'HEALTHY',
                'NONE',
                false,
                true,
                '',
                dataPlane: ['ok' => true, 'running' => false],
                publicationPending: true,
            ),
            'publication-failed' => $this->projection(
                'CONTROL_DEGRADED',
                'PUBLICATION_RECOVERY',
                false,
                false,
                'candidate failed',
                publicationPending: true,
            ),
            'service-restart' => $this->projection(
                'HEALTHY',
                'NONE',
                false,
                true,
                '',
                publicationPending: true,
                serviceTreeRestartRequested: true,
            ),
        ] as $name => $rejected) {
            self::assertSame('REJECTED', $rejected['admission_state'], $name);
            self::assertFalse($rejected['guardian_continuity_healthy'], $name);
        }
    }

    public function testTopLevelReadyUsesTheSameLiveNginxObservation(): void
    {
        $status = self::controllerMethodSource('status');
        $ready = \strpos($status, "'ready' => (bool)(\$this->state['ready'] ?? false)");
        self::assertIsInt($ready);
        $projection = \substr($status, $ready, 700);
        self::assertStringContainsString(
            "(\$nginx['ok'] ?? false) === true",
            $projection,
        );
        self::assertStringContainsString(
            "(\$nginx['running'] ?? false) === true",
            $projection,
        );
    }

    public function testNativeBrokersAdmitRecoveryButNeverPromoteIt(): void
    {
        foreach (self::nativeBrokerSources() as $platform => $source) {
            foreach ([
                'admission_state',
                'promotion_eligible',
                'control_plane_ready',
                'isolation_mode',
                'health_state',
                'recovery_stage',
                'route_failure_kind',
                'guardian_continuity_healthy',
            ] as $field) {
                self::assertStringContainsString('"' . $field . '"', $source, $platform);
            }
            self::assertStringContainsString(
                'strcmp(admission_state, "ISOLATION_REPLAY") == 0',
                $source,
                $platform,
            );
            self::assertStringContainsString(
                'strcmp(admission_state, "ROUTE_DEGRADED") == 0',
                $source,
                $platform,
            );
            self::assertMatchesRegularExpression(
                '/(?:strcmp|_stricmp)\(\s*admission_state,\s*'
                    . '"PUBLICATION_PENDING"\s*\)\s*==\s*0/',
                $source,
                $platform,
            );
            self::assertStringContainsString(
                'result == WLS_BOOTSTRAP_CONTROL_ADMITTED',
                $source,
                $platform,
            );
            self::assertStringContainsString(
                'receipt->promotion_eligible',
                $source,
                $platform,
            );
            self::assertStringContainsString(
                'strcmp(receipt->admission_state, "HEALTHY") == 0',
                $source,
                $platform,
            );
        }
    }

    /**
     * @param array{ok:bool,running:bool}|null $dataPlane
     * @return array{admission_state:string,promotion_eligible:bool,route_failure_kind:string,guardian_continuity_healthy:bool}
     */
    private function projection(
        string $healthState,
        string $recoveryStage,
        bool $isolationMode,
        bool $ready,
        string $routeFailureKind,
        ?array $dataPlane = null,
        bool $controlPlaneReady = true,
        int $activeGeneration = 7,
        bool $publicationPending = false,
        bool $serviceTreeRestartRequested = false,
    ): array {
        self::loadController();
        $method = new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'bootstrapAdmissionProjection',
        );
        $state = [
            'generation' => 7,
            'active_config_generation' => $activeGeneration,
            'active_config_digest' => \str_repeat('a', 64),
            'health_state' => $healthState,
            'isolation_mode' => $isolationMode,
            'recovery' => [
                'stage' => $recoveryStage,
                'last_failure' => $routeFailureKind,
            ],
        ];
        $status = [
            'ready' => $ready,
            'control_plane_ready' => $controlPlaneReady,
            'isolation_mode' => $isolationMode,
        ];
        $result = $method->invoke(
            null,
            $status,
            $dataPlane ?? ['ok' => true, 'running' => true],
            $state,
            $publicationPending,
            true,
            $serviceTreeRestartRequested,
        );
        self::assertIsArray($result);
        return $result;
    }

    private static function controllerMethodSource(string $method): string
    {
        self::loadController();
        $reflection = new \ReflectionMethod(\WlsEdgeGatewayController::class, $method);
        $lines = \file((string)$reflection->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }

    private static function loadController(): void
    {
        if (\class_exists('WlsEdgeGatewayController', false)) {
            return;
        }
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require \dirname(__DIR__, 5) . '/bin/wls_gateway_controller.php';
    }

    /** @return array{posix:string,windows:string} */
    private static function nativeBrokerSources(): array
    {
        $root = \dirname(__DIR__, 5) . '/Service/Edge/Gateway/Native';
        $sources = [];
        foreach (['posix', 'windows'] as $platform) {
            $source = \file_get_contents($root . '/' . $platform . '/wls_gateway_broker.c');
            self::assertIsString($source);
            $sources[$platform] = $source;
        }
        return $sources;
    }
}
