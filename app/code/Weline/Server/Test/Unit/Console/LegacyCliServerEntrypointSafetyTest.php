<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class LegacyCliServerEntrypointSafetyTest extends TestCase
{
    public function testRetiredCliStartIsAMinimalFailClosedCommand(): void
    {
        $source = $this->source('Console/Console/Server/Start.php');

        self::assertStringContainsString('已退役', $source);
        self::assertStringContainsString('server:start', $source);
        self::assertLessThan(90, \substr_count($source, "\n"));

        foreach ([
            'Processer::',
            'ObjectManager::',
            'Server::instance(',
            'getProcessIdByPort(',
            'stopWelineServerOnPort(',
            'killWlsProcessOnPort(',
            'taskkill',
            'kill -9',
        ] as $unsafeFragment) {
            self::assertStringNotContainsString($unsafeFragment, $source);
        }
    }

    public function testActiveCliStopRequiresAnExactManagedGenerationLease(): void
    {
        $source = $this->source('Console/Console/Server/Stop.php');

        self::assertStringContainsString('getManagedProcessLeaseRecord(', $source);
        self::assertStringContainsString('terminateManagedProcessLease(', $source);
        self::assertStringContainsString("['name' => \$expectedProcessName, 'launch-id' => \$launchId]", $source);
        self::assertStringContainsString("\$lease['launch_id']", $source);
        self::assertStringContainsString('if (!$this->clearRuntimeConfig())', $source);
        self::assertStringNotContainsString('->save()', $source);

        foreach ([
            'getProcessIdByPort(',
            'getProcessCommandLine(',
            'isRunningByPid(',
            'getDriver()->kill',
            'killByPid(',
            'killProcessTreeByPid(',
            'stream_socket_client(',
            'fsockopen(',
            'taskkill',
            'kill -9',
        ] as $unsafeFragment) {
            self::assertStringNotContainsString($unsafeFragment, $source);
        }
    }

    public function testRetiredProtocolEdgeIsAMinimalFailClosedScript(): void
    {
        $source = $this->source('bin/protocol_edge.php');

        self::assertStringContainsString('throw new \\RuntimeException(', $source);
        self::assertStringContainsString('retired', $source);
        self::assertLessThan(15, \substr_count($source, "\n"));

        foreach ([
            'Processer::',
            'proc_terminate(',
            'killProcessTreeByPid(',
            'getProcessCommandLine(',
            'pid-file',
            'require_once',
            '$argv',
        ] as $unsafeFragment) {
            self::assertStringNotContainsString($unsafeFragment, $source);
        }
    }

    private function source(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR
            . \str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $source = @\file_get_contents($path);

        self::assertIsString($source, 'Source should be readable: ' . $relativePath);
        self::assertNotSame('', $source, 'Source should not be empty: ' . $relativePath);

        return $source;
    }
}
