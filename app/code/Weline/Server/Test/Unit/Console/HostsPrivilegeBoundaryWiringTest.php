<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class HostsPrivilegeBoundaryWiringTest extends TestCase
{
    public function testHostsCallersNeverRenderExecutablePrivilegeEscalationCommands(): void
    {
        foreach ([
            'Console/Server/Start.php',
            'Console/Server/Hosts/Add.php',
        ] as $relativePath) {
            $source = $this->serverSource($relativePath);

            self::assertStringNotContainsString("\$result['command']", $source, $relativePath);
            self::assertStringNotContainsString("\$result[\"command\"]", $source, $relativePath);
        }
    }

    public function testPublicHostsWriterContractDoesNotAdvertiseExecutablePrivilegeEscalation(): void
    {
        $source = $this->serverSource('Api/System/HostsWriter.php');

        self::assertStringNotContainsString('command?: string', $source);
        self::assertStringNotContainsString('elevated?: bool', $source);
        self::assertStringContainsString('status?: string', $source);
        self::assertStringContainsString('error_code?: string', $source);
    }

    private function serverSource(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3) . '/' . $relativePath;
        $source = \file_get_contents($path);
        self::assertIsString($source, 'Unable to read production source: ' . $path);

        return $source;
    }
}
