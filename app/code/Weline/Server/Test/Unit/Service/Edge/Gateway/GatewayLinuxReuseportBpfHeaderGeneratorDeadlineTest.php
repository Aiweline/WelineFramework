<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GatewayLinuxReuseportBpfHeaderGeneratorDeadlineTest extends TestCase
{
    private string $root = '';
    private string $generator = '';

    protected function setUp(): void
    {
        $this->generator = \dirname(__DIR__, 5)
            . '/Service/Edge/Gateway/Native/posix/'
            . 'wls_linux_reuseport_bpf_header_generator.php';
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-bpf-generator-' . \bin2hex(\random_bytes(8));
        self::assertFileExists($this->generator);
        self::assertTrue(\mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testClangInspectionHasOneBoundedNonBlockingDeadlineContract(): void
    {
        $generator = (string)\file_get_contents($this->generator);
        $cmake = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/Native/CMakeLists.txt',
        );

        self::assertSame(1, \substr_count($generator, '$absoluteDeadline ='));
        self::assertStringContainsString('WLS_BPF_CLANG_TIMEOUT_NANOSECONDS', $generator);
        self::assertStringContainsString('WLS_BPF_CLANG_MAX_OUTPUT_BYTES', $generator);
        self::assertStringContainsString('WLS_BPF_CLANG_TERM_GRACE_NANOSECONDS', $generator);
        self::assertStringContainsString('WLS_BPF_CLANG_KILL_REAP_NANOSECONDS', $generator);
        self::assertStringContainsString('\\hrtime(true)', $generator);
        self::assertStringContainsString('\\stream_set_blocking($pipe, false)', $generator);
        self::assertStringContainsString('\\stream_select(', $generator);
        self::assertStringContainsString('\\proc_terminate($process, 15)', $generator);
        self::assertStringContainsString('\\proc_terminate($process, 9)', $generator);
        self::assertStringContainsString(
            "if (\$observedExitBeforeDeadline && \\hrtime(true) < \$absoluteDeadline) {\n"
                . '        $closeStatus = \\proc_close($process);',
            $generator,
        );
        self::assertStringNotContainsString('stream_get_contents', $generator);
        self::assertStringNotContainsString('$terminateDeadline', $generator);
        self::assertStringNotContainsString('$killDeadline', $generator);
        self::assertStringNotContainsString('execute_process(', $cmake);
    }

    public function testValidClangVersionCompletesBeforeObjectValidation(): void
    {
        $this->requirePosixShell();
        $clang = $this->writeClang(
            "#!/bin/sh\nprintf 'clang version 18.1.2\\n'\n",
        );

        $result = $this->runGenerator($clang);

        self::assertSame(1, $result['code'], $result['error']);
        self::assertStringContainsString(
            'object is not a little-endian ELF64 EM_BPF relocatable file.',
            $result['error'],
        );
        self::assertStringNotContainsString('provenance check failed', $result['error']);
        self::assertLessThan(2.0, $result['elapsed']);
    }

    public function testCombinedClangOutputBeyondLimitFailsWithoutPipeDeadlock(): void
    {
        $this->requirePosixShell();
        $clang = $this->writeClang(
            "#!/bin/sh\n"
                . "index=0\n"
                . "while [ \"\$index\" -lt 512 ]; do\n"
                . "  printf '0123456789abcdef'\n"
                . "  index=\$((index + 1))\n"
                . "done\n"
                . "index=0\n"
                . "while [ \"\$index\" -lt 512 ]; do\n"
                . "  printf 'fedcba9876543210' >&2\n"
                . "  index=\$((index + 1))\n"
                . "done\n"
                . "printf '\\nclang version 18.1.2\\n' >&2\n",
        );

        $result = $this->runGenerator($clang);

        self::assertSame(1, $result['code'], $result['error']);
        self::assertStringContainsString(
            'provenance output exceeded its bounded limit.',
            $result['error'],
        );
        self::assertLessThan(2.0, $result['elapsed']);
    }

    public function testClangIgnoringTermIsKilledWithinTheOriginalDeadline(): void
    {
        $this->requirePosixShell();
        $clang = $this->writeClang(
            "#!/bin/sh\ntrap '' TERM INT\nwhile :; do :; done\n",
        );

        $result = $this->runGenerator($clang, 8.0);

        self::assertSame(1, $result['code'], $result['error']);
        self::assertStringContainsString(
            'provenance check exhausted its deadline.',
            $result['error'],
        );
        self::assertGreaterThan(4.0, $result['elapsed']);
        self::assertLessThan(6.5, $result['elapsed']);
    }

    private function requirePosixShell(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\is_executable('/bin/sh')) {
            self::markTestSkipped('The Linux BPF generator behavior requires a POSIX shell.');
        }
    }

    private function writeClang(string $script): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'clang-18';
        self::assertSame(\strlen($script), \file_put_contents($path, $script));
        self::assertTrue(\chmod($path, 0700));
        return $path;
    }

    /** @return array{code:int,error:string,elapsed:float} */
    private function runGenerator(string $clang, float $outerTimeout = 3.0): array
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'wls_linux_reuseport_bpf.c';
        $object = $this->root . DIRECTORY_SEPARATOR . 'wls_linux_reuseport_bpf.o';
        $output = $this->root . DIRECTORY_SEPARATOR . 'wls_linux_reuseport_bpf_generated.h';
        self::assertSame(8, \file_put_contents($source, "int x;\n\n"));
        self::assertSame(10, \file_put_contents($object, 'not-an-elf'));

        $process = new Process([
            PHP_BINARY,
            $this->generator,
            '--object=' . $object,
            '--source=' . $source,
            '--clang=' . $clang,
            '--output=' . $output,
        ]);
        $process->setTimeout($outerTimeout);
        $started = \hrtime(true);
        $process->run();

        return [
            'code' => $process->getExitCode() ?? -1,
            'error' => $process->getErrorOutput() . $process->getOutput(),
            'elapsed' => (\hrtime(true) - $started) / 1_000_000_000,
        ];
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || (!\file_exists($path) && !\is_link($path))) {
            return;
        }
        if (\is_dir($path) && !\is_link($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            @\rmdir($path);
            return;
        }
        @\unlink($path);
    }
}
