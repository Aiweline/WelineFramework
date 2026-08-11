<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayControllerColdBootRecoveryContractTest extends TestCase
{
    public function testMutableDataPlaneRecoveryWaitsForAuthenticatedBootstrap(): void
    {
        $run = self::controllerMethodSource('run');

        self::assertMatchesRegularExpression(
            '/if \(\$this->readOnlyRecoveryMode\) \{\s*'
                . '\$this->adoptOrRecoverDataPlane\(\);\s*'
                . '\} else \{\s*'
                . '\$this->startupDataPlaneRecoveryPending = true;\s*\}/s',
            $run,
        );
        self::assertLessThan(
            \strpos($run, '$this->controlServer = $this->openControlServer();'),
            \strpos($run, '$this->startupDataPlaneRecoveryPending = true;'),
            'Production startup must expose the control transport while recovery remains pending.',
        );
    }

    public function testBootstrapAttestsSecurityRootBeforeDataPlaneRecovery(): void
    {
        $bootstrap = self::controllerMethodSource('bootstrapRecovery');

        $attest = \strpos(
            $bootstrap,
            '$this->attestBrokerSecurityRoot(\'bootstrap\');',
        );
        $adopt = \strpos($bootstrap, '$this->adoptOrRecoverDataPlane();');
        self::assertIsInt($attest);
        self::assertIsInt($adopt);
        self::assertLessThan(
            $adopt,
            $attest,
            'The current Broker identity must be authenticated before snapshot-backed recovery.',
        );
    }

    private static function controllerMethodSource(string $method): string
    {
        if (!\class_exists('WlsEdgeGatewayController', false)) {
            if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
                \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
            }
            require \dirname(__DIR__, 5) . '/bin/wls_gateway_controller.php';
        }
        $reflection = new \ReflectionMethod(
            'WlsEdgeGatewayController',
            $method,
        );
        $lines = \file((string)$reflection->getFileName());
        self::assertIsArray($lines);
        return \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
