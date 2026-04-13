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
        };
    }
}
