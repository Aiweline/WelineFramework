<?php

declare(strict_types=1);

namespace Weline\CacheManager\Service;

use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupCapacityPlanner;
use Weline\Framework\Cache\Service\StorefrontWarmup\StorefrontWarmupProgressLedger;
use Weline\Framework\Test\TestCore;

class StorefrontWarmupAdminServiceTest extends TestCore
{
    private const NOW = 1762754400; // 2025-11-10T06:00:00Z

    private function service(): StorefrontWarmupAdminService
    {
        return new StorefrontWarmupAdminService();
    }

    /**
     * @return array<string, mixed>
     */
    private function state(array $overrides = []): array
    {
        return \array_replace([
            'phase' => StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS,
            'sitemap_doc_cursor' => 2,
            'extras_cursor' => 0,
            'loc_cursor' => 0,
            'priority_homes' => ['/en_GB/'],
            'priority_home_cursor' => 1,
            'last_url' => 'https://example.com/product/a',
            'last_status' => 'ok',
            'last_reason' => '',
            'ok' => 12,
            'skip' => 1,
            'fail' => 2,
            'sitemap_docs' => ['https://example.com/sitemap-1.xml', 'https://example.com/sitemap-2.xml', 'https://example.com/sitemap-3.xml', 'https://example.com/sitemap-4.xml'],
            'extras' => [],
            'locs' => [],
            'generation_authority_clock' => 42,
            'storefront_generation_fingerprint' => 'd77fb6563c6c8c3d7b1c978556fad8db6a1cc91345769c135673109da9fb2df6',
            'needs_rewarm' => false,
            'updated_at' => \gmdate('c', self::NOW - 60),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function quietSample(): array
    {
        return [
            'busy' => false,
            'ratio' => 0.12,
            'reason' => 'quiet',
            'start_threshold' => 0.4,
            'abort_threshold' => 0.7,
            'source' => 'proc_stat',
            'cores' => 10,
            'load1' => 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capacity(string $band = StorefrontWarmupCapacityPlanner::BAND_FULL): array
    {
        $yield = $band === StorefrontWarmupCapacityPlanner::BAND_YIELD;

        return [
            'band' => $band,
            'designed_concurrency' => 10,
            'designed_shard' => 160,
            'designed_wall_ms' => 55000,
            'effective_concurrency' => $yield ? 0 : 10,
            'effective_shard' => $yield ? 0 : 160,
            'effective_wall_ms' => $yield ? 0 : 55000,
            'ratio' => 0.12,
            'remaining' => 37,
            'scale_factor' => 1.0,
        ];
    }

    public function testBuildStatusReportsPhaseCursorAndCurrentGroup(): void
    {
        $status = $this->service()->buildStatus($this->state(), $this->quietSample(), $this->capacity(), true, self::NOW);

        $this->assertSame(StorefrontWarmupProgressLedger::PHASE_B_SITEMAP_DOCS, $status['phase']);
        $this->assertSame('B', $status['phases']['B']['key']);
        $this->assertSame(2, $status['phases']['B']['done']);
        $this->assertSame(4, $status['phases']['B']['total']);
        $this->assertSame(50.0, $status['phases']['B']['percent']);
        $this->assertTrue($status['phases']['B']['current']);
        $this->assertSame(1, $status['phases']['A']['done'], 'passed phases count as done');
        $this->assertSame(1, $status['phases']['A']['total']);
        $this->assertSame('https://example.com/sitemap-3.xml', $status['current_group']['item']);
        $this->assertStringContainsString('3', $status['current_group']['position']);
        $this->assertStringContainsString('4', $status['current_group']['position']);
        $this->assertSame(37, $status['progress']['remaining']);
        $this->assertSame(12, $status['totals']['ok']);
        $this->assertSame(2, $status['totals']['fail']);
        $this->assertSame('queued', $status['verdict']['state']);
        // phases 以相位字母为键：JSON 后是对象，前端必须按 Object.keys 遍历。
        $this->assertStringStartsWith('{', (string)\json_encode($status['phases']));
    }

    public function testVerdictRunningWhenLockIsHeld(): void
    {
        $status = $this->service()->buildStatus($this->state(), $this->quietSample(), $this->capacity(), false, self::NOW);

        $this->assertSame('running', $status['verdict']['state']);
        $this->assertNotSame('', $status['verdict']['label']);
    }

    public function testVerdictNeedsRewarmWinsOverQueued(): void
    {
        $status = $this->service()->buildStatus(
            $this->state(['needs_rewarm' => true]),
            $this->quietSample(),
            $this->capacity(),
            true,
            self::NOW
        );

        $this->assertSame('needs_rewarm', $status['verdict']['state']);
        $this->assertNotSame('', $status['verdict']['label']);
        $this->assertStringContainsString('A', $status['verdict']['detail']);
        $this->assertTrue($status['generation']['needs_rewarm']);
        $this->assertSame('d77fb656…2df6', $status['generation']['short']);
    }

    public function testVerdictStalledWhenLedgerIsOld(): void
    {
        $status = $this->service()->buildStatus(
            $this->state(['updated_at' => \gmdate('c', self::NOW - 3600)]),
            $this->quietSample(),
            $this->capacity(),
            true,
            self::NOW
        );

        $this->assertSame('stalled', $status['verdict']['state']);
        $this->assertNotSame('', $status['verdict']['label']);
        $this->assertSame(3600, $status['last']['age_seconds']);
    }

    public function testVerdictDoneWhenPhaseDoneAndNoRewarm(): void
    {
        $status = $this->service()->buildStatus(
            $this->state(['phase' => StorefrontWarmupProgressLedger::PHASE_DONE, 'sitemap_docs' => [], 'priority_homes' => []]),
            $this->quietSample(),
            $this->capacity(),
            true,
            self::NOW
        );

        $this->assertSame('done', $status['verdict']['state']);
        $this->assertNotSame('', $status['verdict']['label']);
        $this->assertSame(100.0, $status['progress']['percent']);
    }

    public function testVerdictNeverWhenLedgerIsEmpty(): void
    {
        $status = $this->service()->buildStatus([], $this->quietSample(), $this->capacity(), true, self::NOW);

        $this->assertSame('never', $status['verdict']['state']);
        $this->assertNull($status['last']['age_seconds']);
    }

    public function testLoadSnapshotExposesThresholdsAndBand(): void
    {
        $status = $this->service()->buildStatus(
            $this->state(),
            $this->quietSample(),
            $this->capacity(StorefrontWarmupCapacityPlanner::BAND_SCALE),
            true,
            self::NOW
        );

        $this->assertSame(12.0, $status['load']['ratio_percent']);
        $this->assertSame(40.0, $status['load']['start_percent']);
        $this->assertSame(70.0, $status['load']['abort_percent']);
        $this->assertSame(StorefrontWarmupCapacityPlanner::BAND_SCALE, $status['load']['band']);
        $this->assertNotSame('', $status['load']['band_label']);
    }

    public function testValidateRejectsNonIntegerAndOutOfRange(): void
    {
        $result = $this->service()->validate([
            'busy_start_pct' => '40.5',
            'concurrency' => 99,
            'wall_ms' => 1000,
        ]);

        $this->assertCount(3, $result['errors']);
        $this->assertSame([], $result['values']);
    }

    public function testValidateRejectsAbortBelowStart(): void
    {
        $result = $this->service()->validate([
            'busy_start_pct' => 60,
            'busy_abort_pct' => 40,
        ]);

        $this->assertCount(1, $result['errors']);
        $this->assertSame([], $result['values']);
    }

    public function testValidateAcceptsInRangeValuesAndSkipsEmpty(): void
    {
        $result = $this->service()->validate([
            'busy_start_pct' => '35',
            'busy_abort_pct' => '65',
            'concurrency' => '6',
            'shard' => '96',
            'wall_ms' => '40000',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([
            StorefrontWarmupAdminService::ENV_BUSY_START_PCT => 35,
            StorefrontWarmupAdminService::ENV_BUSY_ABORT_PCT => 65,
            StorefrontWarmupAdminService::ENV_CONCURRENCY => 6,
            StorefrontWarmupAdminService::ENV_SHARD => 96,
            StorefrontWarmupAdminService::ENV_WALL_MS => 40000,
        ], $result['values']);

        $empty = $this->service()->validate(['busy_start_pct' => '', 'concurrency' => null]);
        $this->assertSame([], $empty['errors']);
        $this->assertSame([], $empty['values']);
    }
}
