<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\Process\Driver;

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\Process\Driver\LinuxProcessDriver;

final class LinuxProcessDriverGetProcessInfoUnixTest extends TestCase
{
    public function testUnixPsFallbackKeepsLstartWhenCommWouldContainSpaces(): void
    {
        $driver = new class extends LinuxProcessDriver {
            /** @var array<string, list<string>> */
            public array $responses = [];

            protected function executeCommand(
                string $command,
                array &$output = [],
                int &$exitCode = 0,
            ): bool {
                $output = [];
                foreach ($this->responses as $needle => $lines) {
                    if (\str_contains($command, $needle)) {
                        $output = $lines;
                        $exitCode = 0;

                        return true;
                    }
                }
                $exitCode = 1;

                return false;
            }
        };

        $driver->responses = [
            'pid=,%mem=,%cpu=,lstart=' => [
                '44989  0.0  0.0 Tue Aug  4 18:59:13 2026',
            ],
            'comm=' => [
                'nginx: master process',
            ],
            'args=' => [
                'nginx: master process /tmp/nginx -p /tmp/run -c /tmp/nginx.conf',
            ],
        ];

        $info = $driver->getProcessInfo(44989);

        self::assertTrue($info['exists']);
        self::assertSame('nginx: master process', $info['name']);
        self::assertSame('0.0%', $info['memory']);
        self::assertSame('0.0%', $info['cpu']);
        self::assertSame('Tue Aug 4 18:59:13 2026', $info['start_time']);
        self::assertStringContainsString('/tmp/nginx', $info['command']);
    }
}
