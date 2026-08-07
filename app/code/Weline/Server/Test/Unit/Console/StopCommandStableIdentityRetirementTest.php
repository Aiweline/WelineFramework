<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;

final class StopCommandStableIdentityRetirementTest extends TestCase
{
    public function testResidualRetirementUsesProtectedBirthLeasesOnly(): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass(Stop::class))->getFileName());

        self::assertStringContainsString('MasterChildCredentialStore', $source);
        self::assertStringContainsString('MasterLeaseManager', $source);
        self::assertStringContainsString('terminateExactProcessIdentity(', $source);
        self::assertStringNotContainsString('Processer::dispatchBatchKillProcessTrees(', $source);
        self::assertStringNotContainsString('Processer::killProcessTreeByPid(', $source);
        self::assertStringNotContainsString('Processer::killByProcessNamePrefix(', $source);
        self::assertStringNotContainsString("'taskkill /F '", $source);
    }

    public function testPortAndPrefixFallbacksAreVerificationOnly(): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass(Stop::class))->getFileName());
        $portMethod = self::methodSource($source, 'killWlsProcessOnPort');
        $prefixMethod = self::methodSource($source, 'killRecoverableProcessPrefix');
        $rawPidMethod = self::methodSource($source, 'terminateResidualProcesses');
        $windowsTreeMethod = self::methodSource($source, 'queryKillManagedProcessTreeForStop');
        $windowsPidMethod = self::methodSource($source, 'killWindowsProcessForStop');
        $taskkillMethod = self::methodSource($source, 'executeWindowsTaskkillForStop');

        self::assertStringContainsString('return false;', $portMethod);
        self::assertStringNotContainsString('terminateWlsPortProcess(', $portMethod);
        self::assertStringContainsString('return 0;', $prefixMethod);
        self::assertStringNotContainsString('killByProcessNamePrefix(', $prefixMethod);
        self::assertStringContainsString('return 0;', $rawPidMethod);
        self::assertStringNotContainsString('kill', $rawPidMethod);
        self::assertStringContainsString('return false;', $windowsTreeMethod);
        self::assertStringContainsString('return false;', $windowsPidMethod);
        self::assertStringContainsString('return -1;', $taskkillMethod);
        self::assertStringNotContainsString('taskkill /', $taskkillMethod);
    }

    public function testInvalidInstanceIdentityFailsClosedBeforeReadingAnyLease(): void
    {
        $stop = new class extends Stop {
            public int $leaseReads = 0;

            public function retire(string $instance): array
            {
                return $this->retireExactInstanceGeneration($instance);
            }

            protected function retireCredentialBoundGeneration(string $instance): array
            {
                unset($instance);
                ++$this->leaseReads;

                return [];
            }
        };

        $result = $stop->retire('../foreign');

        self::assertSame(0, $stop->leaseReads);
        self::assertSame(0, $result['terminated']);
        self::assertSame(0, $result['released']);
        self::assertSame(1, $result['unreleased']);
        self::assertSame(['instance_identity_invalid'], $result['reasons']);
    }

    public function testExactRetirementUsesChildLedgerBeforeMasterBirthLease(): void
    {
        $birth = \str_repeat('a', 64);
        $boot = \str_repeat('b', 64);
        $stop = new class($birth, $boot) extends Stop {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(
                private readonly string $birth,
                private readonly string $boot,
            ) {
            }

            public function retire(string $instance): array
            {
                return $this->retireExactInstanceGeneration($instance);
            }

            protected function retireCredentialBoundGeneration(string $instance): array
            {
                $this->calls[] = 'children:' . $instance;
                return [[
                    'released' => true,
                    'terminated' => true,
                    'reason' => 'terminated',
                ]];
            }

            protected function readProtectedMasterLease(string $instance): ?array
            {
                $this->calls[] = 'lease:' . $instance;
                return [
                    'instance' => $instance,
                    'master_pid' => 4242,
                    'master_process_birth' => $this->birth,
                    'pid_namespace_id' => '',
                    'host_boot_id' => $this->boot,
                ];
            }

            protected function currentStopHostBootId(): string
            {
                return $this->boot;
            }

            protected function terminateProtectedProcessIdentity(
                int $pid,
                string $birth,
                string $pidNamespaceId,
                float $graceSeconds,
            ): array {
                $this->calls[] = 'master:' . $pid . ':' . $birth . ':' . $pidNamespaceId;
                return [
                    'released' => true,
                    'terminated' => true,
                    'reason' => 'terminated',
                ];
            }
        };

        $result = $stop->retire('default');

        self::assertSame(2, $result['terminated']);
        self::assertSame(2, $result['released']);
        self::assertSame(0, $result['unreleased']);
        self::assertSame([
            'children:default',
            'lease:default',
            'master:4242:' . $birth . ':',
        ], $stop->calls);
    }

    public function testCrossBootLeaseNeverSignalsCurrentNumericPid(): void
    {
        $stop = new class extends Stop {
            public int $terminationCalls = 0;

            public function retire(): array
            {
                return $this->retireExactInstanceGeneration('default');
            }

            protected function retireCredentialBoundGeneration(string $instance): array
            {
                unset($instance);
                return [];
            }

            protected function readProtectedMasterLease(string $instance): ?array
            {
                return [
                    'instance' => $instance,
                    'master_pid' => 5151,
                    'master_process_birth' => \str_repeat('c', 64),
                    'pid_namespace_id' => '',
                    'host_boot_id' => \str_repeat('d', 64),
                ];
            }

            protected function currentStopHostBootId(): string
            {
                return \str_repeat('e', 64);
            }

            protected function terminateProtectedProcessIdentity(
                int $pid,
                string $birth,
                string $pidNamespaceId,
                float $graceSeconds,
            ): array {
                unset($pid, $birth, $pidNamespaceId, $graceSeconds);
                ++$this->terminationCalls;
                return ['released' => true, 'terminated' => true, 'reason' => 'unexpected'];
            }
        };

        $result = $stop->retire();

        self::assertSame(0, $stop->terminationCalls);
        self::assertSame(0, $result['terminated']);
        self::assertSame(0, $result['unreleased']);
    }

    private static function methodSource(string $source, string $method): string
    {
        $needle = 'function ' . $method . '(';
        $start = \strpos($source, $needle);
        self::assertNotFalse($start, 'Method not found: ' . $method);
        $brace = \strpos($source, '{', (int)$start);
        self::assertNotFalse($brace, 'Method body not found: ' . $method);
        $depth = 0;
        $length = \strlen($source);
        for ($offset = (int)$brace; $offset < $length; ++$offset) {
            if ($source[$offset] === '{') {
                ++$depth;
            } elseif ($source[$offset] === '}' && --$depth === 0) {
                return \substr($source, (int)$start, $offset - (int)$start + 1);
            }
        }

        self::fail('Method body is not balanced: ' . $method);
    }
}
