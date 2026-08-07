<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Gateway\Agent;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;

final class GatewayAgentDesiredStateLifecycleTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-agent-desired-lifecycle-' . \bin2hex(\random_bytes(8));
        self::assertTrue(@\mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach ((array)@\glob($this->directory . DIRECTORY_SEPARATOR . '*') as $file) {
            if (\is_string($file) && \is_file($file) && !\is_link($file)) {
                @\unlink($file);
            }
        }
        @\unlink($this->directory . DIRECTORY_SEPARATOR . '.desired-state.lock');
        @\rmdir($this->directory);
    }

    public function testInvalidProcessHandleRevokesLifecycleAndRemovesItsAfterImage(): void
    {
        $resultFile = $this->directory . DIRECTORY_SEPARATOR
            . 'job-' . \str_repeat('a', 32) . '.json';
        self::assertIsInt(@\file_put_contents($resultFile, "{}\n", LOCK_EX));
        $job = [
            'process' => null,
            'pipes' => [],
            'action' => 'build',
            'task_id' => '',
            'result_file' => $resultFile,
        ];
        $arguments = [&$job, 100.0];

        $result = $this->method('pollDesiredStateJob')->invokeArgs(
            $this->agent(),
            $arguments,
        );

        self::assertNull($job);
        self::assertIsArray($result);
        self::assertFalse($result['ok'] ?? true);
        self::assertFileDoesNotExist($resultFile);
    }

    public function testOrphanResultCollectionIsBoundedAndCannotPoisonFutureLaunches(): void
    {
        for ($index = 0; $index < 257; ++$index) {
            $file = $this->directory . DIRECTORY_SEPARATOR . 'job-'
                . \str_pad(\dechex($index), 32, '0', STR_PAD_LEFT) . '.json';
            if (@\file_put_contents($file, "{}\n", LOCK_EX) === false) {
                self::fail('Unable to create the desired-state after-image fixture.');
            }
        }
        $collector = $this->method('collectDesiredStateWorkFiles');
        $agent = $this->agent();

        self::assertFalse($collector->invoke($agent, $this->directory));
        self::assertSame(129, $this->resultCount());
        self::assertFalse($collector->invoke($agent, $this->directory));
        self::assertSame(1, $this->resultCount());
        self::assertTrue($collector->invoke($agent, $this->directory));
        self::assertSame(0, $this->resultCount());
    }

    public function testCrashOrphanedAtomicResultCandidateCannotPoisonFutureLaunches(): void
    {
        $orphan = $this->directory . DIRECTORY_SEPARATOR . 'job-'
            . \str_repeat('a', 32) . '.json.tmp-' . \str_repeat('b', 24);
        self::assertNotFalse(\file_put_contents($orphan, '{"partial":true}'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($orphan, 0600));
        }

        self::assertTrue($this->method('collectDesiredStateWorkFiles')->invoke(
            $this->agent(),
            $this->directory,
        ));

        self::assertFileDoesNotExist($orphan);
    }

    public function testUnobservableDeferredChildNeverThrowsIntoTheHeartbeatLoop(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX process termination semantics are required.');
        }
        $process = @\proc_open(
            [PHP_BINARY, '-r', 'sleep(30);'],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $agent = $this->agent();
        $now = \hrtime(true) / 1_000_000_000;
        $jobs = [[
            'process' => $process,
            'pipes' => [],
            'task_id' => '',
            'deferred_at' => $now - 61.0,
            'kill_requested_at' => $now - 61.0,
            'result_file' => '',
        ]];
        $property = new \ReflectionProperty(Agent::class, 'deferredDesiredStateReap');
        $property->setValue($agent, $jobs);

        try {
            $startedAt = \hrtime(true) / 1_000_000_000;
            $this->method('reapDeferredDesiredStateJobs')->invoke($agent);
            $elapsed = (\hrtime(true) / 1_000_000_000) - $startedAt;
            self::assertLessThan(1.0, $elapsed);
            self::assertLessThanOrEqual(1, \count((array)$property->getValue($agent)));
        } finally {
            @\proc_terminate($process, 9);
            for ($attempt = 0; $attempt < 50; ++$attempt) {
                $status = @\proc_get_status($process);
                if (\is_array($status) && ($status['running'] ?? false) !== true) {
                    break;
                }
                \usleep(10_000);
            }
            @\proc_close($process);
        }
    }

    public function testAcmePublicationCannotOutliveTheAgentTickDeadline(): void
    {
        $agentSource = (string)\file_get_contents(
            (string)(new \ReflectionClass(Agent::class))->getFileName(),
        );
        $method = new \ReflectionMethod(
            GatewayHostManager::class,
            'syncAcmeChallenges',
        );
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $hostSource = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            '?float $deadlineMonotonic = null',
            $hostSource,
        );
        self::assertMatchesRegularExpression(
            "/projectRequest\('acme-challenge-sync',[\\s\\S]*?"
                . '\$deadlineMonotonic,\s*\);/',
            $hostSource,
        );
        self::assertStringContainsString(
            'deadlineMonotonic: $deadlineMonotonic',
            $hostSource,
        );
        self::assertMatchesRegularExpression(
            '/->syncAcmeChallenges\([\s\S]*?'
                . '\$desiredChallenges\[\'digest\'\],\s*'
                . '\$tickDeadline,\s*\)/',
            $agentSource,
        );
        self::assertMatchesRegularExpression(
            '/\$acmeChallenges->desired\(\s*'
                . '\$this->acmeRouteDomains\(\$probeRegistration\),\s*'
                . '\$tickDeadline,\s*\)/',
            $agentSource,
        );
    }

    public function testHeartbeatAndStatusCannotOutliveTheAgentTickDeadline(): void
    {
        $agentSource = (string)\file_get_contents(
            (string)(new \ReflectionClass(Agent::class))->getFileName(),
        );
        $heartbeat = new \ReflectionMethod(GatewayHostManager::class, 'heartbeat');
        $lines = \file($heartbeat->getFileName());
        self::assertIsArray($lines);
        $hostSource = \implode('', \array_slice(
            $lines,
            $heartbeat->getStartLine() - 1,
            $heartbeat->getEndLine() - $heartbeat->getStartLine() + 1,
        ));

        self::assertStringContainsString(
            '?float $deadlineMonotonic = null',
            $hostSource,
        );
        self::assertMatchesRegularExpression(
            '/projectRequest\(\s*\'heartbeat\',\s*\$payload,\s*'
                . '\$deadlineMonotonic,\s*\)/',
            $hostSource,
        );
        self::assertStringContainsString(
            '$this->operationLockWaitTimeout($deadlineMonotonic, 5.0)',
            $hostSource,
        );
        self::assertMatchesRegularExpression(
            '/\$heartbeatObservation = \$gateway->heartbeat\([\s\S]*?'
                . '\$tickDeadline,\s*\);/',
            $agentSource,
        );
        self::assertStringContainsString(
            '$status = $gateway->status(0.0, $tickDeadline);',
            $agentSource,
        );
        self::assertMatchesRegularExpression(
            '/progressCallback: function \(\s*\?float \$deadlineMonotonic = null,'
                . '\s*\) use'
                . '[\s\S]*?->heartbeat\(\s*\$instanceName,\s*\[\],\s*'
                . '\$deadlineMonotonic,\s*\);/',
            $agentSource,
        );
    }

    public function testCertificateRenewWorkerPassesItsAbsoluteMutationDeadline(): void
    {
        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(Agent::class))->getFileName(),
        );

        self::assertStringContainsString(
            '$mutationDeadline = $this->monotonicNow()',
            $source,
        );
        self::assertStringContainsString(
            '$status = $gateway->status(5.0, $mutationDeadline);',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/->renew\(\s*\$instanceName,[\s\S]*?'
                . '\$plan\[\'expected_route_generations\'\],\s*'
                . '\$mutationDeadline,\s*\);/',
            $source,
        );
    }

    private function agent(): Agent
    {
        return (new \ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
    }

    private function method(string $name): \ReflectionMethod
    {
        return new \ReflectionMethod(Agent::class, $name);
    }

    private function resultCount(): int
    {
        $files = @\glob($this->directory . DIRECTORY_SEPARATOR . 'job-*.json');
        self::assertIsArray($files);
        return \count($files);
    }
}
