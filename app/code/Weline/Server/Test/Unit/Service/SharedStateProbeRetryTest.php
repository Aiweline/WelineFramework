<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\SharedStateServiceManager;

/**
 * Pins the authenticated-probe retry policy.
 *
 * A single missed probe used to be read as "a foreign process owns this port"
 * and aborted the whole worker reload. Inside the Master the probe shares one
 * control loop with reload work, so a busy-but-healthy sidecar can miss one
 * 250 ms window. The guard must still be fatal for a genuinely foreign
 * occupant, which fails every attempt.
 */
final class SharedStateProbeRetryTest extends TestCase
{
    public function testAuthenticatedProbeIsRetriedBeforeDeclaringForeignOccupant(): void
    {
        $manager = $this->manager(alwaysHealthyOnAttempt: 0);

        try {
            $manager->ensure(ControlMessage::ROLE_SESSION_SERVER, [], self::sessionPortEnv(), 'consumer-retry');
            self::fail('A port that never answers the authenticated probe must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('failed authenticated PING', $exception->getMessage());
        }

        self::assertSame(3, $manager->probeCalls, 'The probe must be retried before the guard fails.');
        self::assertSame([], $manager->launchCalls, 'A foreign occupant must never be launched over.');
        self::assertSame([], $manager->stopCalls, 'A foreign occupant must never be stopped.');
    }

    public function testTransientProbeMissStillReusesTheLiveSidecar(): void
    {
        $manager = $this->manager(alwaysHealthyOnAttempt: 2);

        $result = $manager->ensure(ControlMessage::ROLE_SESSION_SERVER, [], self::sessionPortEnv(), 'consumer-retry');

        // Reaching this line at all proves the guard did not fire: a missed
        // first probe no longer aborts on a busy-but-live sidecar.
        self::assertNotSame([], $result);
        self::assertSame(2, $manager->probeCalls, 'The retry must stop as soon as the probe answers.');
        self::assertSame([], $manager->launchCalls);
        self::assertSame([], $manager->stopCalls);
    }

    /**
     * @param int $alwaysHealthyOnAttempt 1-based attempt that starts answering; 0 never answers.
     */
    private function manager(int $alwaysHealthyOnAttempt): SharedStateServiceManager
    {
        return new class ($alwaysHealthyOnAttempt) extends SharedStateServiceManager {
            /** @var array<string,array<string,mixed>> */
            public array $runtimeFiles = [];
            /** @var list<array{0:string,1:string}> */
            public array $launchCalls = [];
            /** @var list<array{0:string,1:int}> */
            public array $stopCalls = [];
            public int $probeCalls = 0;

            public function __construct(private readonly int $healthyFromAttempt) {}

            protected function readRuntimeFile(string $role): array
            {
                return $this->runtimeFiles[$role] ?? [];
            }

            protected function writeRuntimeFile(string $role, array $runtime): void
            {
                $this->runtimeFiles[$role] = $runtime;
            }

            protected function removeRuntimeFile(string $role): void
            {
                unset($this->runtimeFiles[$role]);
            }

            protected function loadEnvConfig(): array
            {
                return [
                    'session' => ['server_port' => 19970],
                    'wls' => [
                        'session' => [
                            'port' => 19970,
                            'token_file_name' => 'session_server.token',
                            'wls_server' => [
                                'port' => 19970,
                                'token_file_name' => 'session_server.token',
                            ],
                        ],
                        'memory_service' => [
                            'enabled' => false,
                        ],
                    ],
                ];
            }

            protected function isPortOccupied(int $port): bool
            {
                return true;
            }

            /**
             * Keep the success path hermetic: the real implementation registers
             * this process as a consumer in the project-wide shared registry,
             * which would touch the live sidecar state from a unit test.
             */
            protected function finalizeSharedRuntime(string $role, array $runtime, string $requesterInstanceName): array
            {
                $runtime['reuse_existing'] = true;
                $runtime['shared_service'] = true;

                return $runtime;
            }

            protected function inspectRunningSharedService(array $definition, string $expectedTokenFileName): array
            {
                return [
                    'reusable' => true,
                    'pid' => 9876,
                    'port' => (int) $definition['port'],
                    'role' => (string) $definition['role'],
                    'token_file_name' => $expectedTokenFileName,
                    'process_name' => (string) $definition['process_name'],
                    'instance_name' => (string) $definition['service_instance_name'],
                ];
            }

            protected function probeRunningSharedService(array $definition, string $tokenFileName): bool
            {
                $this->probeCalls++;

                return $this->healthyFromAttempt > 0 && $this->probeCalls >= $this->healthyFromAttempt;
            }

            protected function forceStopReusedService(array $definition, array $runtime): bool
            {
                $this->stopCalls[] = [(string) $definition['role'], (int) ($runtime['pid'] ?? 0)];
                $this->removeRuntimeFile((string) $definition['role']);

                return true;
            }

            protected function launchSharedServiceProcess(array $definition, string $requesterInstanceName, bool $frontend = false): int
            {
                $this->launchCalls[] = [(string) $definition['role'], $requesterInstanceName];

                return 1;
            }

            protected function waitUntilSharedServicesReadyBatch(array $definitions): array
            {
                $done = [];
                foreach ($definitions as $definition) {
                    $role = (string) $definition['role'];
                    $runtime = [
                        'role' => $role,
                        'host' => (string) $definition['host'],
                        'port' => (int) $definition['port'],
                        'token_file_name' => (string) $definition['token_file_name'],
                        'pid' => 6543,
                        'process_name' => (string) $definition['process_name'],
                        'instance_name' => (string) $definition['service_instance_name'],
                        'started_at' => '2026-03-26T09:00:00+08:00',
                        'healthy_at' => '2026-03-26T09:00:01+08:00',
                        'created_now' => true,
                        'shared_service' => true,
                    ];
                    $this->writeRuntimeFile($role, $runtime);
                    $done[$role] = $runtime;
                }

                return $done;
            }
        };
    }

    private static function sessionPortEnv(): array
    {
        return [
            'session' => ['server_port' => 19970],
            'wls' => [
                'session' => [
                    'port' => 19970,
                    'token_file_name' => 'session_server.token',
                    'wls_server' => [
                        'port' => 19970,
                        'token_file_name' => 'session_server.token',
                    ],
                ],
                'memory_service' => [
                    'enabled' => false,
                ],
            ],
        ];
    }
}
