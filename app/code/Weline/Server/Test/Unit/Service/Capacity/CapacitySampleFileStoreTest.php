<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Capacity;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Capacity\CapacitySampleStore;

final class CapacitySampleFileStoreTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/weline-capacity-samples-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*.jsonl') ?: [] as $path) {
            if (\is_string($path)) {
                @\unlink($path);
            }
        }
        @\rmdir($this->directory);
    }

    public function testAppendSeriesAndCleanupStayOnTheFile(): void
    {
        $store = new CapacitySampleStore($this->directory);
        $fresh = [
            'sampled_at' => \date('Y-m-d H:i:s'),
            'host_pressure' => 0.42,
            'worker_pressure_avg' => 0.3,
            'worker_pressure_max' => 0.5,
            'connections_sum' => 4,
            'compact_delta' => 1,
            'advice_codes' => ['raise_worker_count'],
            'config_soft_pressure' => 0.7,
        ];
        self::assertTrue($store->appendIfNew('default', $fresh));
        self::assertFalse($store->appendIfNew('default', $fresh));

        $series = $store->series('default', 3600);
        self::assertSame([$fresh['sampled_at']], $series['labels']);
        self::assertSame([0.42], $series['host_pressure']);
        self::assertSame(['raise_worker_count'], $series['latest_advice']);

        $stale = $fresh;
        $stale['sampled_at'] = \date('Y-m-d H:i:s', \time() - (20 * 86400));
        self::assertTrue($store->appendIfNew('default', $stale));
        self::assertSame(1, $store->cleanup(14));
        self::assertSame([$fresh['sampled_at']], $store->series('default', 3600)['labels']);
    }
}
