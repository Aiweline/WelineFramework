<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GatewayControllerDestructiveActionFenceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
            \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
        }
        require_once \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php';
    }

    /** @return iterable<string,array{string,mixed}> */
    public static function destructiveTupleTampering(): iterable
    {
        yield 'schema' => ['schema_version', 2];
        yield 'pid' => ['pid', 4322];
        yield 'birth' => ['start_id', '99887767'];
        yield 'binary digest' => ['binary_digest', \str_repeat('e', 64)];
        yield 'runtime generation' => [
            'runtime_generation',
            \str_repeat('e', 64),
        ];
        yield 'config digest' => ['config_digest', \str_repeat('e', 64)];
        yield 'config path digest' => [
            'config_path_digest',
            \str_repeat('e', 64),
        ];
        yield 'config generation' => ['publication_generation', 43];
    }

    #[DataProvider('destructiveTupleTampering')]
    public function testDestructiveTupleRejectsEveryTamperedIdentityDimension(
        string $field,
        mixed $tamperedValue,
    ): void {
        $facts = $this->destructiveFacts();
        self::assertTrue($this->tupleMatches($facts));
        $facts['attestation'][$field] = $tamperedValue;
        self::assertFalse(
            $this->tupleMatches($facts),
            'Destructive authorization accepted a tampered ' . $field,
        );
    }

    public function testControllerDestructivePathsRequireFullNativeFence(): void
    {
        $source = $this->controllerSource();
        $retire = $this->between(
            $source,
            'private function retireAttestedNginxProcessTree(',
            'private function requestBrokerProcessTreeRetirement(',
        );
        $authorization = \strpos(
            $retire,
            '$this->destructiveNginxProcessAttestation(',
        );
        $brokerAction = \strpos(
            $retire,
            '$this->requestBrokerProcessTreeRetirement(',
        );
        self::assertIsInt($authorization);
        self::assertIsInt($brokerAction);
        self::assertLessThan($brokerAction, $authorization);

        foreach (['stopDataPlane', 'forceStopSecurityDataPlane'] as $method) {
            $body = $this->methodBody($source, $method);
            $freshStatus = \preg_match(
                '/\$this->nginxStatus\(\s*true,\s*true,/',
                $body,
                $match,
                PREG_OFFSET_CAPTURE,
            ) === 1 ? (int)$match[0][1] : false;
            $retirement = \strpos(
                $body,
                '$this->retireAttestedNginxProcessTree(',
            );
            self::assertIsInt($freshStatus, $method);
            self::assertIsInt($retirement, $method);
            self::assertLessThan($retirement, $freshStatus, $method);
        }

        $reload = $this->methodBody($source, 'reloadDataPlane');
        $freshStatus = \preg_match(
            '/\$this->nginxStatus\(\s*true,\s*true,/',
            $reload,
            $match,
            PREG_OFFSET_CAPTURE,
        ) === 1 ? (int)$match[0][1] : false;
        $nativeReload = \strpos(
            $reload,
            "\$this->nativeNginxLifecycleAction(\n                'NGINX_RELOAD'",
        );
        self::assertIsInt($freshStatus);
        self::assertIsInt($nativeReload);
        self::assertLessThan($nativeReload, $freshStatus);

        $runner = $this->methodBody($source, 'runNginxConfig');
        self::assertStringContainsString(
            "hash_equals('-s', (string)(\$arguments[0] ?? ''))",
            $runner,
        );
        self::assertStringContainsString(
            'Production Nginx lifecycle signals require the Native Broker.',
            $runner,
        );
    }

    public function testPendingRetirementRevalidatesManifestRuntimeAndConfig(): void
    {
        $source = $this->controllerSource();
        $validation = $this->between(
            $source,
            'private function validPendingServiceTreeRetirementIntent(',
            'private static function destructiveProcessAttestationTupleMatches(',
        );
        foreach ([
            '$manifestBinaryDigest',
            '$currentBinaryDigest',
            '$currentRuntimeGeneration',
            '$currentConfigDigest',
            "hash('sha256', \$this->configFile())",
            "'candidate_attestation_fence_digest'",
        ] as $requiredFence) {
            self::assertStringContainsString($requiredFence, $validation);
        }
    }

    public function testPlatformRestartDoesNotEraseFailClosedDiagnosis(): void
    {
        $source = $this->controllerSource();
        foreach ([
            'abortRoutingMutation',
            'failClosedSecurityMutation',
            'failClosedStartupIdentityFailureFence',
        ] as $method) {
            $body = $this->methodBody($source, $method);
            $restart = \strpos($body, '$this->requestServiceTreeRestart(');
            self::assertIsInt($restart, $method);
            self::assertStringContainsString(
                "'SECURITY_MUTATION_FAILED_CLOSED'",
                \substr($body, $restart),
                $method . ' must retain the irreversible cause after requesting recovery.',
            );
        }
    }

    public function testNativeReloadAndRetirementReopenExactProcessAuthority(): void
    {
        foreach (['posix', 'windows'] as $platform) {
            $source = (string)\file_get_contents(
                \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR
                    . 'Service/Edge/Gateway/Native/' . $platform
                    . '/wls_gateway_broker.c',
            );
            $pidIdentity = $platform === 'windows'
                ? 'wls_win_nginx_pid_identity('
                : 'wls_nginx_pid_identity(';
            $lifecycleStart = \strrpos(
                $source,
                $platform === 'windows'
                    ? 'static int wls_win_nginx_lifecycle_action_v2('
                    : 'static int wls_nginx_lifecycle_action_v2(',
            );
            self::assertIsInt($lifecycleStart, $platform);
            $lifecycle = \substr($source, $lifecycleStart, 16000);
            self::assertStringContainsString($pidIdentity, $lifecycle, $platform);
            self::assertStringContainsString(
                'process-attestation.receipt',
                $source,
                $platform,
            );
            foreach ([
                'binary_digest',
                'runtime_generation',
                'config_digest',
                'publication_generation',
            ] as $field) {
                self::assertStringContainsString($field, $lifecycle, $platform);
            }
        }
    }

    /** @return array<string,mixed> */
    private function destructiveFacts(): array
    {
        $attestation = [
            'schema_version' => 3,
            'pid' => 4321,
            'start_id' => '99887766',
            'binary_digest' => \str_repeat('a', 64),
            'runtime_generation' => \str_repeat('b', 64),
            'config_digest' => \str_repeat('c', 64),
            'config_path_digest' => \str_repeat('d', 64),
            'publication_generation' => 42,
        ];
        return [
            'attestation' => $attestation,
            'pid' => 4321,
            'start_id' => '99887766',
            'binary_digest' => \str_repeat('a', 64),
            'runtime_generation' => \str_repeat('b', 64),
            'config_digest' => \str_repeat('c', 64),
            'config_path_digest' => \str_repeat('d', 64),
            'publication_generation' => 42,
        ];
    }

    /** @param array<string,mixed> $facts */
    private function tupleMatches(array $facts): bool
    {
        return (bool)(new \ReflectionMethod(
            \WlsEdgeGatewayController::class,
            'destructiveProcessAttestationTupleMatches',
        ))->invokeArgs(null, [
            $facts['attestation'],
            $facts['pid'],
            $facts['start_id'],
            $facts['binary_digest'],
            $facts['runtime_generation'],
            $facts['config_digest'],
            $facts['config_path_digest'],
            $facts['publication_generation'],
        ]);
    }

    private function controllerSource(): string
    {
        return (string)\file_get_contents(
            \dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'bin'
                . DIRECTORY_SEPARATOR . 'wls_gateway_controller.php',
        );
    }

    private function methodBody(string $source, string $method): string
    {
        $start = \strpos($source, 'private function ' . $method . '(');
        self::assertIsInt($start, 'Missing method ' . $method);
        $next = \strpos($source, "\n    private function ", $start + 1);
        if (!\is_int($next)) {
            $next = \strlen($source);
        }
        return \substr($source, $start, $next - $start);
    }

    private function between(string $source, string $start, string $end): string
    {
        $offset = \strpos($source, $start);
        self::assertIsInt($offset, 'Missing source marker ' . $start);
        $limit = \strpos($source, $end, $offset + 1);
        self::assertIsInt($limit, 'Missing source marker ' . $end);
        return \substr($source, $offset, $limit - $offset);
    }
}
