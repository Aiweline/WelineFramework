<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Container;

use PHPUnit\Framework\TestCase;

final class CompiledContainerFallbackPolicyTest extends TestCase
{
    public function testFreshInstallWithoutDeployModeBootsBeforeFirstCompile(): void
    {
        self::assertSame('fallback', $this->probe('unset'));
    }

    public function testDevAllowsObjectManagerBridge(): void
    {
        self::assertSame('fallback', $this->probe('dev'));
    }

    public function testConstantsNotYetDefinedAllowsBridge(): void
    {
        self::assertSame('fallback', $this->probe('missing_constants'));
    }

    public function testProdFailsClosedWithoutRegistry(): void
    {
        self::assertSame('strict', $this->probe('prod'));
    }

    public function testWlsFailsClosedWithoutRegistry(): void
    {
        self::assertSame('strict', $this->probe('wls'));
    }

    private function probe(string $mode): string
    {
        $fixture = __DIR__ . '/fixtures/compiled-container-missing-registry.php';
        $output = [];
        $code = 0;
        \exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($fixture) . ' ' . \escapeshellarg($mode) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, \implode("\n", $output));

        return \trim(\implode("\n", $output));
    }
}
