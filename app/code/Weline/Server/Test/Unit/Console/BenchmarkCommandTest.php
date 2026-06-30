<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Benchmark;

final class BenchmarkCommandTest extends TestCase
{
    public function testResolveBenchmarkPathDefaultsToHealthEndpoint(): void
    {
        $command = $this->createCommand();

        self::assertSame('/_wls/health', $command->resolvePath([]));
    }

    public function testResolveBenchmarkPathKeepsExplicitBusinessPath(): void
    {
        $command = $this->createCommand();

        self::assertSame('/', $command->resolvePath(['path' => '/']));
    }

    public function testResolveBenchmarkPathRepairsGitBashConvertedPath(): void
    {
        $command = $this->createCommand();

        self::assertSame('/_wls/health', $command->resolvePath(['path' => 'C:/Program Files/Git/_wls/health']));
    }

    public function testBenchmarkReportPathIncludesTargetSlugAndAvoidsOverwrite(): void
    {
        $command = $this->createCommand();
        $reportDir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline-benchmark-test-' . \bin2hex(\random_bytes(4));
        \mkdir($reportDir, 0777, true);

        try {
            $firstPath = $command->reportFilePath($reportDir, 'http://127.0.0.1:21400/__bench/framework', 1800000000.123456);
            \file_put_contents($firstPath, '{}');
            $secondPath = $command->reportFilePath($reportDir, 'http://127.0.0.1:21400/__bench/framework', 1800000000.123456);

            self::assertStringContainsString('_123456_bench-framework_pid', $firstPath);
            self::assertStringEndsWith('_1.json', $secondPath);
            self::assertNotSame($firstPath, $secondPath);
        } finally {
            foreach (\glob($reportDir . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @\unlink($file);
            }
            @\rmdir($reportDir);
        }
    }

    private function createCommand(): object
    {
        return new class extends Benchmark {
            public function __construct()
            {
                $this->__init();
            }

            public function resolvePath(array $args): string
            {
                return $this->resolveBenchmarkPath($args);
            }

            public function reportFilePath(string $reportDir, string $targetUrl, float $now): string
            {
                return $this->buildReportFilePath($reportDir, $targetUrl, $now);
            }
        };
    }
}
