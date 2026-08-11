<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayControllerAbsoluteDeadlineTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require_once \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php';
    }

    public function testBrokerTimeoutIsClippedToCallerAbsoluteDeadline(): void
    {
        $controller = (new \ReflectionClass(\WlsEdgeGatewayController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'brokerActionTimeoutForDeadline',
        );
        $deadline = \hrtime(true) / 1_000_000_000 + 0.25;
        $timeout = $method->invoke($controller, $deadline, 3.0);

        self::assertIsFloat($timeout);
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(0.25, $timeout);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deadline expired');
        $method->invoke(
            $controller,
            \hrtime(true) / 1_000_000_000 - 0.01,
            3.0,
        );
    }

    public function testBrokerTimeoutConsumesTheActiveMutationDeadlineWhenNoLeafOverridesIt(): void
    {
        $controller = (new \ReflectionClass(\WlsEdgeGatewayController::class))
            ->newInstanceWithoutConstructor();
        $deadline = \hrtime(true) / 1_000_000_000 + 0.25;
        (new \ReflectionProperty(
            \WlsEdgeGatewayController::class,
            'activeBrokerMutationDeadlineMonotonic',
        ))->setValue($controller, $deadline);

        $timeout = (new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'brokerActionTimeoutForDeadline',
        ))->invoke($controller, null, 6.0);

        self::assertIsFloat($timeout);
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(0.25, $timeout);
    }

    public function testActiveMutationDeadlineAlsoClipsALongerLeafDeadline(): void
    {
        $controller = (new \ReflectionClass(\WlsEdgeGatewayController::class))
            ->newInstanceWithoutConstructor();
        $now = \hrtime(true) / 1_000_000_000;
        (new \ReflectionProperty(
            \WlsEdgeGatewayController::class,
            'activeBrokerMutationDeadlineMonotonic',
        ))->setValue($controller, $now + 0.1);

        $timeout = (new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'brokerActionTimeoutForDeadline',
        ))->invoke($controller, $now + 2.0, 13.0);

        self::assertIsFloat($timeout);
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(0.1, $timeout);
    }

    public function testRegisterSnapshotBrokerChainKeepsOneTwentySecondDeadline(): void
    {
        self::assertSame(
            20.0,
            (new \ReflectionClassConstant(
                \WlsEdgeGatewayController::class,
                'REGISTRATION_BUDGET_SECONDS',
            ))->getValue(),
        );
        $register = $this->methodSource('register');
        $withinDeadline = $this->methodSource('registerWithinDeadline');
        $snapshot = $this->methodSource('snapshotCertificate');

        self::assertStringContainsString(
            '$this->activeBrokerMutationDeadlineMonotonic = $registrationDeadline;',
            $register,
        );
        self::assertStringContainsString(
            '$this->activeBrokerMutationDeadlineMonotonic = $previousDeadline;',
            $register,
        );
        self::assertMatchesRegularExpression(
            '/snapshotCertificate\([\s\S]*?\$registrationDeadline,\s*\)/',
            $withinDeadline,
        );
        foreach ([
            'brokerSnapshotCertificateSource',
            'brokerSealCertificateSnapshot',
            'brokerAttestCertificateSnapshot',
        ] as $method) {
            self::assertStringContainsString(
                'brokerActionTimeoutForDeadline(',
                $this->methodSource($method),
                $method . ' reopened a per-action timeout.',
            );
        }
        self::assertGreaterThanOrEqual(
            4,
            \substr_count($snapshot, '$deadlineMonotonic,'),
            'Source reads and the final seal must receive the register deadline.',
        );
        foreach ([
            'brokerSnapshotUsage' => 'null',
            'brokerSnapshotInventory' => '$deadlineMonotonic',
            'brokerRemoveSnapshotCandidate' => 'null',
        ] as $method => $deadline) {
            self::assertStringContainsString(
                'brokerActionTimeoutForDeadline(' . $deadline . ',',
                $this->methodSource($method),
                $method . ' escaped the active register budget.',
            );
        }
    }

    public function testEnrollmentBrokerChainKeepsOneTwentySecondDeadline(): void
    {
        self::assertSame(
            20.0,
            (new \ReflectionClassConstant(
                \WlsEdgeGatewayController::class,
                'ENROLLMENT_BUDGET_SECONDS',
            ))->getValue(),
        );
        $enroll = $this->methodSource('enroll');
        $withinDeadline = $this->methodSource('enrollWithinDeadline');
        self::assertStringContainsString(
            '$this->activeBrokerMutationDeadlineMonotonic = $enrollmentDeadline;',
            $enroll,
        );
        self::assertStringContainsString(
            '$this->activeBrokerMutationDeadlineMonotonic = $previousDeadline;',
            $enroll,
        );
        self::assertGreaterThanOrEqual(
            5,
            \substr_count($withinDeadline, '$enrollmentDeadline,'),
            'Prepare, commit, bind and both abort paths must share one deadline.',
        );
        foreach ([
            'prepareBrokerCertificateRoots',
            'commitBrokerCertificateRoots',
            'bindBrokerEmergencyCredential',
            'abortBrokerCertificateRoots',
            'attestNativeSecurityState',
        ] as $method) {
            self::assertStringContainsString(
                'brokerActionTimeoutForDeadline($deadlineMonotonic)',
                $this->methodSource($method),
                $method . ' reopened a per-action timeout.',
            );
        }
    }

    public function testNginxAttestationAndPortProofKeepTheOuterDeadline(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
                . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php',
        );

        self::assertMatchesRegularExpression(
            '/private function nginxStatus\(\s*bool \$requireFreshNativeAttestation = false,\s*bool \$refreshFromBroker = true,\s*\?float \$deadlineMonotonic = null,/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/attestNginxProcess\(\s*\$brokerChannel,\s*\$pid,\s*\$expectedHash,\s*\$deadlineMonotonic,/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/processAttestationFenceContext\(\s*\$configDigest,\s*\$deadlineMonotonic,/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/TCP_PORT_PROBE[\s\S]*?\(string\)\$budgetMilliseconds[\s\S]*?brokerActionTimeoutForDeadline\(\s*\$deadlineMonotonic,/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/\$status = \$this->nginxStatus\(\s*true,\s*true,\s*\$attestationDeadline,/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/\$operationDeadline[\s\S]*?\$status = \$this->nginxStatus\(\s*false,\s*true,\s*\$operationDeadline,[\s\S]*?verifyDataPlaneStartBoundary\(\s*\$status,\s*\$operationDeadline,/s',
            $source,
        );
    }

    public function testNativeNginxActionsNeverRaiseASubHundredMillisecondDeadline(): void
    {
        foreach ([
            'nativeNginxCandidateTest',
            'nativeNginxLifecycleAction',
        ] as $method) {
            $source = $this->methodSource($method);
            self::assertStringContainsString(
                '$actionDeadline = $this->resolveBrokerActionDeadline(',
                $source,
            );
            self::assertMatchesRegularExpression(
                '/brokerActionTimeoutForDeadline\(\s*\$actionDeadline,\s*'
                    . 'self::PROCESS_TREE_RETIRE_TIMEOUT_SECONDS,\s*\)/s',
                $source,
            );
            self::assertStringNotContainsString('max(0.1, $remaining)', $source);
        }
    }

    private function methodSource(string $method): string
    {
        $reflection = new \ReflectionMethod(\WlsEdgeGatewayController::class, $method);
        $lines = \file($reflection->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
