<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Script;

use PHPUnit\Framework\TestCase;

final class HanfuTestCatalogCleanupScriptContractTest extends TestCase
{
    public function testHelpDescribesGuardedModesAndExitCodesAsJson(): void
    {
        $process = $this->runScript(['--help']);

        self::assertSame(0, $process['exit_code'], $process['stderr']);
        self::assertSame('', $process['stderr']);
        self::assertJson($process['stdout']);
        self::assertStringContainsString(
            '"exit_codes":{"0":"success","1":"runtime_error","2":"usage_error"}',
            $process['stdout'],
        );

        /** @var array<string,mixed> $payload */
        $payload = json_decode($process['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hanfu-test-catalog-cleanup-cli.v1', $payload['contract'] ?? null);
        self::assertSame(
            ['--dry-run', '--apply=<digest>', '--verify=<digest>'],
            $payload['modes'] ?? null,
        );
        self::assertSame(
            ['0' => 'success', '1' => 'runtime_error', '2' => 'usage_error'],
            $payload['exit_codes'] ?? null,
        );
    }

    public function testScriptRejectsNonZeroWebsiteBeforeBootstrappingApplication(): void
    {
        $process = $this->runScript(['--website=1', '--dry-run', '--run-id=cleanup-test']);

        self::assertSame(2, $process['exit_code']);
        self::assertSame('', $process['stdout']);
        self::assertStringContainsString('website_id_zero_required', $process['stderr']);
        $this->assertNoCredentialLeak($process['stderr']);
    }

    public function testScriptRejectsConflictingModesWithoutPartialSuccess(): void
    {
        $process = $this->runScript([
            '--website=0',
            '--dry-run',
            '--apply=' . str_repeat('a', 64),
            '--run-id=cleanup-test',
        ]);

        self::assertSame(2, $process['exit_code']);
        self::assertSame('', $process['stdout']);
        self::assertStringContainsString('hanfu_cleanup_mode_conflict', $process['stderr']);
        $this->assertNoCredentialLeak($process['stderr']);
    }

    public function testScriptRejectsInvalidRunIdBeforeBootstrappingApplication(): void
    {
        $process = $this->runScript(['--website=0', '--dry-run', '--run-id=NO']);

        self::assertSame(2, $process['exit_code']);
        self::assertSame('', $process['stdout']);
        self::assertStringContainsString('hanfu_cleanup_run_id_invalid', $process['stderr']);
        $this->assertNoCredentialLeak($process['stderr']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runScript(array $arguments): array
    {
        $script = dirname(__DIR__, 3) . '/scripts/cleanup-hanfu-test-catalog.php';
        $command = [PHP_BINARY, $script, ...$arguments];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 7));
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => trim((string)$stdout),
            'stderr' => trim((string)$stderr),
        ];
    }

    private function assertNoCredentialLeak(string $output): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/(?:password|passwd|secret|access[_-]?token|authorization|cookie|session(?:_?id)?)\s*[:=]/i',
            $output,
        );
    }
}
