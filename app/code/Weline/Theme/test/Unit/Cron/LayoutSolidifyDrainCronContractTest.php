<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Theme\Cron\LayoutSolidifyDrain;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifyJobStore;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySolidifySerialKey;

final class LayoutSolidifyDrainCronContractTest extends TestCase
{
    public function testCronImplementsInterfaceAndSchedule(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Cron/LayoutSolidifyDrain.php');
        self::assertStringContainsString('implements CronTaskInterface', $src);
        self::assertStringContainsString("'theme_layout_solidify_drain'", $src);
        self::assertStringContainsString("'* * * * *'", $src);
        self::assertStringContainsString('drainPendingJobs', $src);
        self::assertTrue(is_a(LayoutSolidifyDrain::class, CronTaskInterface::class, true));
    }

    public function testJobStorePutCoalescesAndLists(): void
    {
        $root = sys_get_temp_dir() . '/weline-solidify-jobs-' . bin2hex(random_bytes(4));
        @mkdir($root, 0775, true);
        try {
            $paths = new ThemeLayoutEntityPaths($root . DIRECTORY_SEPARATOR);
            $store = new ThemeLayoutEntitySolidifyJobStore($paths);
            $key = ThemeLayoutEntitySolidifySerialKey::fromParts(
                7,
                'frontend',
                'grocery.__store__.__channel__',
                'normal',
                'homepage',
                'default',
                1385,
                2,
            );
            $fp = str_repeat('b', 64);
            $a = $store->put($key, $fp);
            $b = $store->put($key, $fp);
            self::assertTrue($a['enqueued']);
            self::assertFalse($a['coalesced']);
            self::assertFalse($b['enqueued']);
            self::assertTrue($b['coalesced']);
            self::assertTrue($store->has($key));
            $list = $store->listPending(8);
            self::assertCount(1, $list);
            self::assertSame($key->toString(), $list[0]['key']->toString());
            self::assertSame($fp, $list[0]['expected_fp']);
            $store->delete($key);
            self::assertFalse($store->has($key));
        } finally {
            $this->purge($root);
        }
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
