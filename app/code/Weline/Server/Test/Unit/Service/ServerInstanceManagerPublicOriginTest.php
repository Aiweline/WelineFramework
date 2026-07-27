<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\ServerInstanceManager;

final class ServerInstanceManagerPublicOriginTest extends TestCase
{
    public function testEndpointFilterPreservesCanonicalHttpsPublicOrigin(): void
    {
        $filtered = $this->filter([
            'public_origin' => 'https://LOCALHOST:19738/',
        ]);

        self::assertSame('https://localhost:19738', $filtered['public_origin'] ?? null);
    }

    public function testEndpointFilterRejectsNonHttpsPublicOrigin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must use HTTPS');

        $this->filter([
            'public_origin' => 'http://localhost:19738',
        ]);
    }

    public function testMasterOnlyHandoffCarriesPersistedPublicOriginIntoRuntimeConfig(): void
    {
        $method = new \ReflectionMethod(Start::class, 'runMasterOnly');
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringContainsString("(string)(\$data['public_origin'] ?? '')", $source);
        self::assertStringContainsString("'public_origin' => \$publicOrigin", $source);
    }

    /** @return array<string,mixed> */
    private function filter(array $data): array
    {
        $method = new \ReflectionMethod(ServerInstanceManager::class, 'filterEndpointRecord');
        $result = $method->invoke(new ServerInstanceManager(), $data);
        self::assertIsArray($result);
        return $result;
    }
}
