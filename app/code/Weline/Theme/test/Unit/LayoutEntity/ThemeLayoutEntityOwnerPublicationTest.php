<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBatchPublisher;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityOwnerLock;

final class ThemeLayoutEntityOwnerPublicationTest extends TestCase
{
    public function testOwnerWriteIsReentrantButAnotherFiberWaitsUntilPublicationFinishes(): void
    {
        self::assertTrue(class_exists(ThemeLayoutEntityOwnerLock::class), 'Owner lock must isolate concurrent saves.');
        $identity = $this->identity();
        $events = [];
        SchedulerSystem::enableScheduler();
        SchedulerSystem::setWaitDispatcher(static function (): void {});
        try {
            $first = new \Fiber(static function () use ($identity, &$events): void {
                ThemeLayoutEntityOwnerLock::write($identity, static function () use ($identity, &$events): void {
                    ThemeLayoutEntityOwnerLock::read($identity, static function () use (&$events): void { $events[] = 'nested'; });
                    $events[] = 'old-start';
                    \Fiber::suspend();
                    $events[] = 'old-end';
                });
            });
            $second = new \Fiber(static function () use ($identity, &$events): void {
                ThemeLayoutEntityOwnerLock::write($identity, static function () use (&$events): void { $events[] = 'new'; });
            });
            $first->start();
            $second->start();
            self::assertSame(['nested', 'old-start'], $events);
            $first->resume();
            $second->resume();
            self::assertSame(['nested', 'old-start', 'old-end', 'new'], $events);
        } finally {
            SchedulerSystem::disableScheduler();
        }
    }

    public function testBatchPublishesEveryCandidateAndHonoursExplicitDeletion(): void
    {
        self::assertTrue(class_exists(ThemeLayoutEntityBatchPublisher::class), 'Publication must accept a complete candidate batch.');
        $directory = $this->directory();
        try {
            file_put_contents($directory . '/old.phtml', 'old');
            (new ThemeLayoutEntityBatchPublisher())->publish($this->identity(), [
                $directory . '/page.phtml' => '<main>new</main>',
                $directory . '/header.phtml' => '<header>new</header>',
                $directory . '/old.phtml' => null,
            ]);
            self::assertSame('<main>new</main>', file_get_contents($directory . '/page.phtml'));
            self::assertSame('<header>new</header>', file_get_contents($directory . '/header.phtml'));
            self::assertFileDoesNotExist($directory . '/old.phtml');
            self::assertCount(2, glob($directory . '/*'));
        } finally { $this->removeDirectory($directory); }
    }

    public function testLaterPromotionFailureRestoresEarlierFilesAndDeletions(): void
    {
        self::assertTrue(class_exists(ThemeLayoutEntityBatchPublisher::class), 'Publication must compensate a partial replacement.');
        $directory = $this->directory();
        file_put_contents($directory . '/page.phtml', 'page-before');
        file_put_contents($directory . '/remove.phtml', 'remove-before');
        file_put_contents($directory . '/header.phtml', 'header-before');
        $publisher = new class extends ThemeLayoutEntityBatchPublisher {
            protected function replace(string $temporary, string $target): void
            {
                if (str_ends_with($target, '/header.phtml') && file_get_contents($temporary) === 'header-after') {
                    throw new \RuntimeException('simulated_device_failure');
                }
                parent::replace($temporary, $target);
            }
        };
        try {
            try {
                $publisher->publish($this->identity(), [
                    $directory . '/page.phtml' => 'page-after',
                    $directory . '/remove.phtml' => null,
                    $directory . '/header.phtml' => 'header-after',
                ]);
                self::fail('The failed device write must be reported.');
            } catch (\RuntimeException $error) {
                self::assertSame('simulated_device_failure', $error->getMessage());
            }
            self::assertSame('page-before', file_get_contents($directory . '/page.phtml'));
            self::assertSame('remove-before', file_get_contents($directory . '/remove.phtml'));
            self::assertSame('header-before', file_get_contents($directory . '/header.phtml'));
            self::assertCount(3, glob($directory . '/*'));
            self::assertSame([], glob($directory . '/.*.phtml'));
        } finally { $this->removeDirectory($directory); }
    }

    public function testIdenticalCandidatesDoNotReplaceFilesOrTouchTheirModificationTime(): void
    {
        $directory = $this->directory();
        try {
            $path = $directory . '/page.phtml';
            file_put_contents($path, 'same');
            touch($path, 1234567890);
            clearstatcache(true, $path);
            $inode = fileinode($path);
            (new ThemeLayoutEntityBatchPublisher())->publish($this->identity(), [$path => 'same', $directory . '/missing.phtml' => null]);
            self::assertSame(1234567890, filemtime($path));
            self::assertSame($inode, fileinode($path));
        } finally { $this->removeDirectory($directory); }
    }

    public function testChangingPartialOptionRemovesPreviousOptionInTheSameBatch(): void
    {
        $directory = $this->directory();
        $partials = $directory . '/theme/partials/header';
        mkdir($partials, 0775, true);
        file_put_contents($partials . '/default.phtml', 'old option');
        try {
            (new ThemeLayoutEntityBatchPublisher())->publish($this->identity(), [$partials . '/compact.phtml' => 'new option']);
            self::assertFileDoesNotExist($partials . '/default.phtml');
            self::assertSame('new option', file_get_contents($partials . '/compact.phtml'));
        } finally {
            foreach (glob($partials . '/*') ?: [] as $file) { unlink($file); }
            rmdir($partials); rmdir(dirname($partials)); rmdir(dirname(dirname($partials))); rmdir($directory);
        }
    }

    public function testFailedUnmodifiedTargetDoesNotTurnAnOrdinaryFailureIntoRestoreFailure(): void
    {
        $directory = $this->directory();
        file_put_contents($directory . '/page.phtml', 'before');
        file_put_contents($directory . '/header.phtml', 'header-before');
        $publisher = new class extends ThemeLayoutEntityBatchPublisher {
            protected function replace(string $temporary, string $target): void
            {
                if (str_ends_with($target, '/header.phtml')) { throw new \RuntimeException('read_only_directory'); }
                parent::replace($temporary, $target);
            }
        };
        try {
            try { $publisher->publish($this->identity(), [$directory . '/page.phtml' => 'after', $directory . '/header.phtml' => 'header-after']); }
            catch (\RuntimeException $error) { self::assertSame('read_only_directory', $error->getMessage()); }
            self::assertSame('before', file_get_contents($directory . '/page.phtml'));
            self::assertSame('header-before', file_get_contents($directory . '/header.phtml'));
        } finally { $this->removeDirectory($directory); }
    }

    private function identity(): ThemeVersionIdentity
    {
        return new ThemeVersionIdentity(999998, 'default_default_default', 'normal', 'frontend', 1, 'draft', 1);
    }

    private function directory(): string
    {
        $directory = sys_get_temp_dir() . '/theme-owner-publication-' . bin2hex(random_bytes(8));
        mkdir($directory);
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_merge(glob($directory . '/*') ?: [], glob($directory . '/.*.phtml') ?: []) as $path) { unlink($path); }
        rmdir($directory);
    }
}
