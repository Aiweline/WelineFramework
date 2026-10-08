<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyLeaseStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyQueue;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifySerialKey;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyStampStore;

final class ThemeLayoutEntityRequestSolidifyGateContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/weline-solidify-gate-' . bin2hex(random_bytes(4));
        @mkdir($this->root, 0775, true);
        ThemeLayoutEntitySolidifyQueue::resetRequestEnqueued();
    }

    protected function tearDown(): void
    {
        ThemeLayoutEntitySolidifyQueue::resetRequestEnqueued();
        $this->purge($this->root);
        parent::tearDown();
    }

    public function testSerialKeyStableAndLayoutDimensionDiffers(): void
    {
        $a = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'default.default.default', 'normal', 'homepage', 'default', 1, 1);
        $b = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'default.default.default', 'normal', 'homepage', 'default', 1, 1);
        $c = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'default.default.default', 'normal', 'mini-cart', 'default', 1, 1);
        self::assertSame($a->toString(), $b->toString());
        self::assertSame($a->hash(), $b->hash());
        self::assertNotSame($a->layoutDimension(), $c->layoutDimension());
    }

    public function testLeaseSameKeyCoalescesSecondAcquire(): void
    {
        $paths = new ThemeLayoutEntityPaths($this->root . DIRECTORY_SEPARATOR);
        $leases = new ThemeLayoutEntitySolidifyLeaseStore($paths);
        $key = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'g.default.default', 'normal', 'homepage', 'default', 0, 0);
        self::assertTrue($leases->tryAcquire($key));
        self::assertTrue($leases->isPending($key));
        self::assertFalse($leases->tryAcquire($key));
        $leases->release($key);
        self::assertFalse($leases->isPending($key));
    }

    public function testStampRoundTrip(): void
    {
        $paths = new ThemeLayoutEntityPaths($this->root . DIRECTORY_SEPARATOR);
        $stamps = new ThemeLayoutEntitySolidifyStampStore($paths);
        $key = ThemeLayoutEntitySolidifySerialKey::fromParts(7, 'frontend', 'g.default.default', 'normal', 'homepage', 'default', 3, 2);
        self::assertNull($stamps->read($key));
        $stamps->write($key, str_repeat('a', 64));
        self::assertSame(str_repeat('a', 64), $stamps->read($key));
    }

    public function testQueueClassesAndGateExist(): void
    {
        $gate = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityRequestSolidifyGate.php';
        $queue = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySolidifyQueue.php';
        $observer = dirname(__DIR__, 3) . '/Observer/ControllerFetchFileBefore.php';
        self::assertFileExists($gate);
        self::assertFileExists($queue);
        $gateSrc = (string)file_get_contents($gate);
        $queueSrc = (string)file_get_contents($queue);
        $obsSrc = (string)file_get_contents($observer);
        self::assertStringContainsString('use_original_template', $gateSrc);
        self::assertStringContainsString('enqueued_fallback_original', $gateSrc);
        self::assertStringContainsString('stamp_fresh_source_template', $gateSrc);
        self::assertStringContainsString('stampFresh', $gateSrc);
        self::assertStringContainsString('($derivedMissing && !$stampFresh)', $gateSrc);
        self::assertStringContainsString('isFiltersCritical', $gateSrc);
        self::assertStringContainsString('filterInventoryBakeBroken', $gateSrc);
        self::assertStringContainsString('empty_filters_inventory_fallback_original', $gateSrc);
        self::assertStringContainsString("SharedResponseCachePolicy::forbid('theme_layout_solidify_fallback_original')", $gateSrc);
        self::assertStringNotContainsString("setHeader('CDN-Cache-Control'", $gateSrc);
        self::assertStringNotContainsString("setHeader('Cloudflare-CDN-Cache-Control'", $gateSrc);
        self::assertStringContainsString('ThemeLayoutEntitySolidifyJobStore', $queueSrc);
        self::assertStringContainsString('drainPendingJobs', $queueSrc);
        self::assertStringContainsString('Runtime::isPersistent()', $queueSrc);
        self::assertStringContainsString('PostResponseTaskQueue::enqueue', $queueSrc);
        self::assertStringContainsString('runDistinctLayoutJobsConcurrently', $queueSrc);
        self::assertStringContainsString('ThemeLayoutEntityRequestSolidifyGate', $obsSrc);
        self::assertStringContainsString('forceOriginalSolidifyGate', $obsSrc);
        $cron = dirname(__DIR__, 3) . '/Cron/LayoutSolidifyDrain.php';
        self::assertFileExists($cron);
        $cronSrc = (string)file_get_contents($cron);
        self::assertStringContainsString('theme_layout_solidify_drain', $cronSrc);
        self::assertStringContainsString('drainPendingJobs', $cronSrc);
    }

    private function purge(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
