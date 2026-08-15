<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;

final class MasterLeaseRuntimeIdentityWindowsAccessTest extends TestCase
{
    public function testArm64X64WindowsUsesStableCscriptEvidenceBeforeAnyFfiInspection(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 7)
            . '/app/code/Weline/Server/Service/MasterLeaseRuntimeIdentity.php',
        );
        $start = \strpos($source, 'private function inspectWindowsProcess(');
        $end = \strpos($source, 'private function windowsProcessHandleIsActive(', (int)$start);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $inspection = \substr($source, $start, $end - $start);
        $emulationGuard = \strpos(
            $inspection,
            'PhpRuntimeSafetyProfile::requiresNativeExtensionIsolation()',
        );
        $externalProbe = \strpos($inspection, 'inspectWindowsProcessWithCscript(');
        $ffi = \strpos($inspection, '\\FFI::cdef(');

        self::assertIsInt($emulationGuard);
        self::assertIsInt($externalProbe);
        self::assertIsInt($ffi);
        self::assertLessThan($ffi, $emulationGuard);
        self::assertLessThan($ffi, $externalProbe);

        $terminationStart = \strpos($source, 'private function terminateWindowsProcessIdentity(');
        $terminationEnd = \strpos($source, 'private function terminationOutcome(', (int)$terminationStart);
        self::assertIsInt($terminationStart);
        self::assertIsInt($terminationEnd);
        $termination = \substr($source, $terminationStart, $terminationEnd - $terminationStart);
        self::assertStringContainsString(
            'PhpRuntimeSafetyProfile::requiresNativeExtensionIsolation()',
            $termination,
        );

        self::assertStringContainsString(
            'consumeWindowsIsolatedLaunchCommitGrace()',
            $inspection,
        );
        $processer = (string)\file_get_contents(
            \dirname(__DIR__, 7)
            . '/app/code/Weline/Framework/System/Process/Processer.php',
        );
        self::assertStringContainsString(
            "\$isolatedEnvironment['WLS_WINDOWS_ISOLATED_BATCH_COMMIT_GRACE'] = '1';",
            $processer,
        );
    }

    public function testCscriptProcessEvidenceParserRequiresOneExactStableWindowsIdentity(): void
    {
        $identity = new MasterLeaseRuntimeIdentity();
        $method = new ReflectionMethod(
            MasterLeaseRuntimeIdentity::class,
            'parseWindowsCscriptProcessProbe',
        );
        $pid = 4321;
        $creation = '20260815093951.099091+480';
        $path = 'C:\\Tools\\PHP84\\php.exe';

        self::assertSame([
            'exists' => true,
            'pid' => $pid,
            'name' => 'php.exe',
            'command' => $path,
            'start_time' => 'windows-wmi-creation:' . $creation,
            'start_identity' => 'windows-wmi-creation:' . $creation,
        ], $method->invoke(
            $identity,
            "WLS_PROCESS\t{$pid}\t{$creation}\t{$path}\r\n",
            $pid,
            true,
        ));
        self::assertSame([
            'exists' => true,
            'pid' => $pid,
            'start_time' => 'windows-wmi-creation:' . $creation,
            'start_identity' => 'windows-wmi-creation:' . $creation,
        ], $method->invoke(
            $identity,
            "WLS_PROCESS\t{$pid}\t{$creation}\t\r\n",
            $pid,
            false,
        ));
        self::assertSame(
            ['exists' => false],
            $method->invoke($identity, "WLS_MISSING\t{$pid}\r\n", $pid, false),
        );

        foreach ([
            "WLS_PROCESS\t{$pid}\t{$creation}\t{$path}\r\nextra\r\n",
            "WLS_PROCESS\t9999\t{$creation}\t{$path}\r\n",
            "WLS_PROCESS\t{$pid}\t20260815093951+480\t{$path}\r\n",
            "WLS_PROCESS\t{$pid}\t{$creation}\tC:\\bad\tpath\\php.exe\r\n",
            "WLS_MISSING\t9999\r\n",
        ] as $malformed) {
            self::assertSame([], $method->invoke($identity, $malformed, $pid, true));
        }
    }

    public function testInspectionHandleIsQueryableAndSynchronizableButCannotTerminate(): void
    {
        $reflection = new ReflectionClass(MasterLeaseRuntimeIdentity::class);
        $access = $reflection->getConstant('WINDOWS_PROCESS_INSPECTION_ACCESS');

        self::assertSame(0x00101000, $access);
        self::assertSame(0x00001000, $access & 0x00001000, 'PROCESS_QUERY_LIMITED_INFORMATION is required.');
        self::assertSame(0x00100000, $access & 0x00100000, 'WaitForSingleObject requires SYNCHRONIZE.');
        self::assertSame(0, $access & 0x00000001, 'Inspection must not grant PROCESS_TERMINATE.');
    }

    public function testWindowsImageBufferIsDecodedWithoutRawFfiStringDereference(): void
    {
        $path = 'C:\\Tools\\PHP84\\php.exe';
        $utf16 = \iconv('UTF-8', 'UTF-16LE', $path);
        self::assertIsString($utf16);
        $units = [];
        foreach (\unpack('v*', $utf16) ?: [] as $unit) {
            $units[] = (int)$unit;
        }

        $method = new ReflectionMethod(
            MasterLeaseRuntimeIdentity::class,
            'windowsWideCharacterBufferToUtf8',
        );
        $decoded = $method->invoke(null, $units, \count($units));

        self::assertSame($path, $decoded);
        self::assertNull($method->invoke(null, $units, 0));

        $source = (string)\file_get_contents(
            \dirname(__DIR__, 7)
            . '/app/code/Weline/Server/Service/MasterLeaseRuntimeIdentity.php',
        );
        self::assertStringContainsString('windowsWideCharacterBufferToUtf8(', $source);
        self::assertStringNotContainsString("\$ffi->cast('char *', \$buffer)", $source);
    }

    public function testManagedBirthUsesStableCreationTimeWithoutRequiringWindowsImageMetadata(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 7)
            . '/app/code/Weline/Server/Service/MasterLeaseRuntimeIdentity.php',
        );
        $birthStart = \strpos($source, 'private function processBirth(');
        $inspectionStart = \strpos($source, 'private function inspectWindowsProcess(');
        $inspectionEnd = \strpos($source, 'private function windowsProcessHandleIsActive(', (int)$inspectionStart);

        self::assertIsInt($birthStart);
        self::assertIsInt($inspectionStart);
        self::assertIsInt($inspectionEnd);

        $birth = \substr($source, $birthStart, $inspectionStart - $birthStart);
        $inspection = \substr($source, $inspectionStart, $inspectionEnd - $inspectionStart);
        self::assertStringContainsString("PHP_OS_FAMILY === 'Windows'", $birth);
        self::assertStringContainsString('$this->inspectWindowsProcess($pid, false)', $birth);
        self::assertStringContainsString('bool $includeImageMetadata = true', $inspection);

        $birthOnlyBranch = \strpos($inspection, 'if (!$includeImageMetadata)');
        $imageQuery = \strpos($inspection, '$buffer = $ffi->new');
        self::assertIsInt($birthOnlyBranch);
        self::assertIsInt($imageQuery);
        self::assertLessThan($imageQuery, $birthOnlyBranch);

        $birthOnly = \substr($inspection, $birthOnlyBranch, $imageQuery - $birthOnlyBranch);
        self::assertStringContainsString('windowsProcessCreationTicks($ffi, $handle)', $birthOnly);
        self::assertStringContainsString('windowsProcessHandleIsActive($ffi, $handle)', $birthOnly);
        self::assertStringContainsString("'start_ticks' => \$creationAfter", $birthOnly);
        self::assertStringNotContainsString('$ffi->QueryFullProcessImageNameW', $birthOnly);
    }

    public function testMissingWindowsTerminationHandleIsClassifiedWithoutPassingNullToFfi(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 7)
            . '/app/code/Weline/Server/Service/MasterLeaseRuntimeIdentity.php',
        );
        $start = \strpos($source, 'private function terminateWindowsProcessIdentity(');
        $end = \strpos($source, 'private function terminationOutcome(', (int)$start);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $termination = \substr($source, $start, $end - $start);
        self::assertStringContainsString('$handle = null;', $termination);
        self::assertStringContainsString(
            'if ($handle === null || \\FFI::isNull($handle))',
            $termination,
        );
    }

    public function testMissingWindowsInspectionHandleIsClassifiedWithoutPassingNullToFfi(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 7)
            . '/app/code/Weline/Server/Service/MasterLeaseRuntimeIdentity.php',
        );
        $start = \strpos($source, 'private function inspectWindowsProcess(');
        $end = \strpos($source, 'private function windowsProcessHandleIsActive(', (int)$start);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $inspection = \substr($source, $start, $end - $start);
        self::assertStringContainsString('$handle = null;', $inspection);
        self::assertStringContainsString(
            'if ($handle !== null && !\\FFI::isNull($handle))',
            $inspection,
        );
        self::assertStringContainsString(
            'return $lastError === 87 ? [\'exists\' => false] : [];',
            $inspection,
        );
    }
}
