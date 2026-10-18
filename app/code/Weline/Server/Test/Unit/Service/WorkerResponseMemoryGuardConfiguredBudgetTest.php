<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Runtime\WlsConcurrency;
use Weline\Framework\View\TemplateCacheManager;
use Weline\Server\Service\WorkerResponseMemoryGuard;

final class WorkerResponseMemoryGuardConfiguredBudgetTest extends TestCase
{
    private string $previousLimit;
    private const MIB = 1048576;

    protected function setUp(): void
    {
        $this->previousLimit = (string) \ini_get('memory_limit');
        WorkerResponseMemoryGuard::resetThresholdCache();
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        WlsConcurrency::setOtherSuspendedFiberCountProvider(null);
        $this->write(WorkerResponseMemoryGuard::class, 'runtimeCacheThresholds', ['soft' => .55, 'hard' => .70]);
    }

    protected function tearDown(): void
    {
        FullPageCacheCoordinator::clearProcessCache();
        TemplateCacheManager::clearProcessMemoryCache();
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        WorkerResponseMemoryGuard::resetThresholdCache();
        WlsConcurrency::setOtherSuspendedFiberCountProvider(null);
        \ini_set('memory_limit', $this->previousLimit);
    }

    public function testActualHeapAboveAbsoluteFloorKeepsFpcAndReceiptsWithConfiguredHeadroom(): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * self::MIB);
        $allocation = \str_repeat('x', 48 * self::MIB);
        $this->seed();
        $real = \memory_get_usage(true);
        self::assertGreaterThanOrEqual(64 * self::MIB, $real);
        self::assertLessThan(.55, $real / (256 * self::MIB));
        self::assertLessThan(256 * self::MIB, \memory_get_peak_usage(true));
        $before = [$this->read(FullPageCacheCoordinator::class, 'processFpcPayloadCache'),
            $this->read(FullPageCacheCoordinator::class, 'processLocalizedHomepageReceipts')];

        $result = WorkerResponseMemoryGuard::compactAfterRequestFiberReleased(1024);

        self::assertIsArray($result);
        self::assertFalse($result['cycle_collection_skipped']);
        self::assertSame($before, [$this->read(FullPageCacheCoordinator::class, 'processFpcPayloadCache'),
            $this->read(FullPageCacheCoordinator::class, 'processLocalizedHomepageReceipts')], 'Actual allocated bytes=' . $real . ', configured budget=' . (256 * self::MIB));
        self::assertSame(0, $result['runtime_cache_compactions']['cleared_process_caches']);
        $this->assertFpcPresent();
        self::assertNotSame([], $this->read(TemplateCacheManager::class, 'memoryCache'));
        self::assertFalse($result['drain_requested']);
        self::assertSame(48 * self::MIB, \strlen($allocation));
    }

    public function testLargeReleasedResponseAloneDoesNotClearWarmFpcBelowSoftPressure(): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * self::MIB);
        $this->seed();
        self::assertLessThan(.55, \memory_get_usage(true) / (256 * self::MIB));
        $result = WorkerResponseMemoryGuard::compactAfterRequestFiberReleased(WorkerResponseMemoryGuard::LARGE_RESPONSE_BYTES);
        self::assertIsArray($result);
        self::assertFalse($result['cycle_collection_skipped']);
        self::assertSame(0, $result['runtime_cache_compactions']['cleared_process_caches']);
        $this->assertFpcPresent();
        self::assertFalse($result['drain_requested']);
    }

    public function testAutomaticSoftAndHardPressureKeepTheirExistingCacheReclaimLevels(): void
    {
        foreach ([[.60, false], [.75, true]] as [$ratio, $hard]) {
            $this->seed();
            WorkerResponseMemoryGuard::setPressureMemoryLimitBytes((int) (\memory_get_usage(true) / $ratio));
            $result = WorkerResponseMemoryGuard::compactAfterRequestFiberReleased(WorkerResponseMemoryGuard::LARGE_RESPONSE_BYTES);
            self::assertIsArray($result);
            self::assertSame([], $this->read(TemplateCacheManager::class, 'memoryCache'));
            self::assertSame($hard, $result['cycle_collection_skipped']);
            if ($hard) {
                self::assertSame([], $this->read(FullPageCacheCoordinator::class, 'processFpcPayloadCache'));
                self::assertSame([], $this->read(FullPageCacheCoordinator::class, 'processLocalizedHomepageReceipts'));
            } else {
                $this->assertFpcPresent();
            }
        }
    }

    public function testRatchetSamplesRespectConfiguredLimitWithoutLosingEitherCandidate(): void
    {
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(256 * self::MIB);
        self::assertSame(48 * self::MIB, WorkerResponseMemoryGuard::zendShellDrainBytesForLimit(256 * self::MIB));
        // shell≥48MiB（256M 缩放仍为 floor）→ drain true（不再被 soft 比短路）
        foreach ([[98, 20.8], [80, 23.6], [86, 34]] as [$real, $used]) {
            self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet((int) ($real * self::MIB), (int) ($used * self::MIB), null, true));
        }
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(150 * self::MIB, 80 * self::MIB, null, true));
        // shell=15 < 48; baseline overrun；本测 soft=0.55 → 150/256≥0.55 → true
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(150 * self::MIB, 135 * self::MIB, 100 * self::MIB, true));
        // shell=10 < 48; baseline overrun but ratio 130/256 < soft(0.55) → false
        self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(130 * self::MIB, 120 * self::MIB, 100 * self::MIB, true));
        // shell=10 < 48, no baseline → false
        self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(200 * self::MIB, 190 * self::MIB, null, true));
        self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(200 * self::MIB, 140 * self::MIB, null, false));
        // shell=60 ≥ 48 → true（与 soft 比无关）
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(200 * self::MIB, 140 * self::MIB, null, true));

        // 压力限额 1G：空壳线≈153MiB；shell=60 不再退役，shell=160 仍退役
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(1024 * self::MIB);
        self::assertSame((int) \floor(1024 * self::MIB * 0.15), WorkerResponseMemoryGuard::zendShellDrainBytesForLimit(1024 * self::MIB));
        self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(200 * self::MIB, 140 * self::MIB, null, true));
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(220 * self::MIB, 60 * self::MIB, null, true));
        // ini 1G 但压力限额已设时仍以压力限额为准（上已覆盖）；清限额后 ini 生效
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(0);
        \ini_set('memory_limit', '1G');
        self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(200 * self::MIB, 140 * self::MIB, null, true));
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(128 * self::MIB);
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(98 * self::MIB, 20 * self::MIB, null, true));
    }

    public function testUnknownLimitKeepsAbsoluteAggressiveReclaimAndRatchetProtection(): void
    {
        \ini_set('memory_limit', '-1');
        WorkerResponseMemoryGuard::setPressureMemoryLimitBytes(0);
        $this->seed();
        $result = WorkerResponseMemoryGuard::compactAfterRequestFiberReleased(WorkerResponseMemoryGuard::LARGE_RESPONSE_BYTES);
        self::assertIsArray($result);
        self::assertSame([], $this->read(FullPageCacheCoordinator::class, 'processFpcPayloadCache'));
        self::assertSame([], $this->read(FullPageCacheCoordinator::class, 'processLocalizedHomepageReceipts'));
        // 未知 limit：空壳线 128MiB；shell≈78 < 128 → 不退役；shell≥128 → 退役
        self::assertSame(128 * self::MIB, WorkerResponseMemoryGuard::zendShellDrainBytesForLimit(null));
        self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(98 * self::MIB, 20 * self::MIB, null, true));
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(180 * self::MIB, 40 * self::MIB, null, true));
    }

    private function seed(): void
    {
        $this->write(TemplateCacheManager::class, 'memoryCache', ['guard-budget' => ['compiled_at' => \time()]]);
        $this->write(FullPageCacheCoordinator::class, 'processFpcPayloadCache', ['guard-budget' => ['body' => '<html>budget</html>']]);
        $this->write(FullPageCacheCoordinator::class, 'processLocalizedHomepageReceipts', ['guard-budget' => [
            'version' => 2, 'full_uri' => 'https://example.test/', 'method' => 'GET', 'cookie_header' => '',
            'identity_digest' => \str_repeat('a', 64), 'cache_key' => 'guard-budget', 'scope_identity' => [], 'namespace_fingerprint' => 'guard-budget',
        ]]);
    }

    private function assertFpcPresent(): void
    {
        self::assertNotSame([], $this->read(FullPageCacheCoordinator::class, 'processFpcPayloadCache'));
        self::assertNotSame([], $this->read(FullPageCacheCoordinator::class, 'processLocalizedHomepageReceipts'));
    }

    private function read(string $class, string $property): mixed
    {
        return (new \ReflectionProperty($class, $property))->getValue();
    }

    private function write(string $class, string $property, mixed $value): void
    {
        (new \ReflectionProperty($class, $property))->setValue(null, $value);
    }
}
