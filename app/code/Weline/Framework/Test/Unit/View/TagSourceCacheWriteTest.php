<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class TagSourceCacheWriteTest extends TestCase
{
    public function testWarmDevHookPathsStillResolveWithoutRepeatedCacheWrites(): void
    {
        $result = $this->probe('dev', 'warm');
        self::assertSame(62, $result['gets']);
        self::assertSame(62, $result['resolutions']);
        self::assertSame(0, $result['sets']);
        self::assertSame(2, $result['keys']);
        self::assertTrue($result['correct_paths']);
    }

    public function testColdDevPathsWriteOnlyTheFirstValueForEachHook(): void
    {
        $result = $this->probe('dev', 'cold');
        self::assertSame(62, $result['resolutions']);
        self::assertSame(2, $result['sets']);
        self::assertTrue($result['correct_paths']);
    }

    public function testDevThemeAndHookPathsPublishChangedResolution(): void
    {
        foreach (['hooks', 'theme'] as $type) {
            $result = $this->probe('dev', 'changed', $type);
            self::assertSame(3, $result['resolutions']);
            self::assertSame(1, $result['sets']);
            self::assertTrue($result['correct_paths']);
        }
    }

    public function testProductionWarmPathsKeepTheirImmediateReturn(): void
    {
        $result = $this->probe('prod', 'warm');
        self::assertSame(62, $result['gets']);
        self::assertSame(0, $result['resolutions']);
        self::assertSame(0, $result['sets']);
        self::assertTrue($result['correct_paths']);
    }

    private function probe(string $mode, string $scenario, string $type = 'hooks'): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__DIR__ . '/fixtures/tag-source-cache-write.php') . ' '
            . escapeshellarg($mode) . ' ' . escapeshellarg($scenario) . ' ' . escapeshellarg($type);
        exec($command . ' 2>&1', $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));
        return json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
    }
}
