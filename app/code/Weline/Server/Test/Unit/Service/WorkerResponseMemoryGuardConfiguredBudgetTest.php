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
        // 真实运行sample数值注入生产决策函数；不声称这里发生真实allocator退役。
        foreach ([[98, 20.8], [80, 23.6], [86, 34]] as [$real, $used]) {
            self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet((int) ($real * self::MIB), (int) ($used * self::MIB), null, true));
        }
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(150 * self::MIB, 80 * self::MIB, null, true));
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(150 * self::MIB, 135 * self::MIB, 100 * self::MIB, true));
        self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(200 * self::MIB, 190 * self::MIB, null, true));
        self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(200 * self::MIB, 140 * self::MIB, null, false));
        $boundary = (int) \ceil(256 * self::MIB * .55);
        self::assertFalse(WorkerResponseMemoryGuard::shouldDrainForZendRatchet($boundary - 1, 60 * self::MIB, null, true));
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet($boundary, 60 * self::MIB, null, true));

        \ini_set('memory_limit', '1G');
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(200 * self::MIB, 140 * self::MIB, null, true));
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
        self::assertTrue(WorkerResponseMemoryGuard::shouldDrainForZendRatchet(98 * self::MIB, 20 * self::MIB, null, true));
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
