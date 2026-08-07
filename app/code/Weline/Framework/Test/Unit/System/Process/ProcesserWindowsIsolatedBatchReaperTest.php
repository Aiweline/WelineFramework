<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Processer;

final class ProcesserWindowsIsolatedBatchReaperTest extends TestCase
{
    public function testIsolatedBrokerReaperNeverTerminatesByRawPid(): void
    {
        $source = $this->methodSource('reapWindowsDetachedBatchHelpers');

        self::assertStringContainsString(
            'self::terminateWindowsIsolatedBatchBrokerByHandle($helper)',
            $source,
        );
        self::assertStringNotContainsString('self::killByPid(', $source);
        self::assertStringNotContainsString('self::isWindowsIsolatedBatchBrokerRunning(', $source);
    }

    public function testIsolatedBrokerTerminationUsesOneStableHandleForIdentityAndSignal(): void
    {
        $source = $this->methodSource('terminateWindowsIsolatedBatchBrokerByHandle');

        $open = \strpos($source, '$processHandle = $ffi->OpenProcess(');
        $image = \strpos($source, 'self::windowsProcessImagePathFromHandle($ffi, $processHandle)');
        $command = \strpos($source, 'self::windowsProcessCommandLineFromHandle($ffi, $processHandle)');
        $alive = \strpos($source, '$ffi->WaitForSingleObject($processHandle, 0)');
        $terminate = \strpos($source, '$ffi->TerminateProcess($processHandle, 1)');

        self::assertIsInt($open);
        self::assertIsInt($image);
        self::assertIsInt($command);
        self::assertIsInt($alive);
        self::assertIsInt($terminate);
        self::assertLessThan($image, $open);
        self::assertLessThan($command, $image);
        self::assertLessThan($alive, $command);
        self::assertLessThan($terminate, $alive);
        self::assertStringContainsString('$ffi->CloseHandle($processHandle)', $source);
        self::assertStringNotContainsString('self::killByPid(', $source);
        self::assertStringNotContainsString('taskkill', \strtolower($source));
        self::assertStringNotContainsString('self::getProcessCommandLine(', $source);
        self::assertStringNotContainsString('self::isRunningByPid(', $source);
    }

    public function testCommandLineIsReadFromTheAlreadyOpenedProcessHandle(): void
    {
        $source = $this->methodSource('windowsProcessCommandLineFromHandle');

        self::assertStringContainsString('NtQueryInformationProcess', $source);
        self::assertStringContainsString('$processHandle', $source);
        self::assertStringNotContainsString('OpenProcess(', $source);
        self::assertStringNotContainsString('getProcessCommandLine(', $source);
    }

    public function testAmbiguousBrokerDiscoveryUsesTheExactIdentityMatcher(): void
    {
        $source = $this->methodSource('findWindowsIsolatedBatchBrokerPid');

        self::assertStringContainsString(
            'self::windowsIsolatedBatchBrokerCommandMatches(',
            $source,
        );
        self::assertStringNotContainsString('\\str_contains(', $source);
    }

    public function testBrokerCommandIdentityRequiresExactScriptBatchAndMarkerTokens(): void
    {
        $batchId = '0123456789abcdef0123456789abcdef';
        $scriptPath = 'C:\\Temp\\Weline Batch.ps1';
        $command = '"C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe"'
            . ' -NoProfile -File "' . $scriptPath . '"'
            . ' --weline-isolated-child --weline-isolated-batch=' . $batchId;

        self::assertTrue($this->commandMatches($command, $batchId, $scriptPath));
        self::assertTrue($this->commandMatches(
            $command,
            $batchId,
            'c:/temp/weline batch.ps1',
        ));
        self::assertFalse($this->commandMatches(
            $command,
            'fedcba9876543210fedcba9876543210',
            $scriptPath,
        ));
        self::assertFalse($this->commandMatches($command, $batchId, $scriptPath . '.evil'));
        self::assertFalse($this->commandMatches(
            \str_replace(' --weline-isolated-child', '', $command),
            $batchId,
            $scriptPath,
        ));
        self::assertFalse($this->commandMatches($command . ' --unexpected', $batchId, $scriptPath));

        $uncScriptPath = '\\\\server\\share\\Weline Batch.ps1';
        $uncCommand = 'powershell.exe -File "' . $uncScriptPath . '"'
            . ' --weline-isolated-child --weline-isolated-batch=' . $batchId;
        self::assertTrue($this->commandMatches($uncCommand, $batchId, $uncScriptPath));
    }

    private function commandMatches(string $command, string $batchId, string $scriptPath): bool
    {
        $reflection = new \ReflectionMethod(Processer::class, 'windowsIsolatedBatchBrokerCommandMatches');
        $reflection->setAccessible(true);

        return (bool)$reflection->invoke(null, $command, $batchId, $scriptPath);
    }

    private function methodSource(string $method): string
    {
        $reflection = new \ReflectionMethod(Processer::class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = \file($file);
        self::assertIsArray($lines);

        return \implode('', \array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
