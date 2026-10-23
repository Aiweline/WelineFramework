<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc\Test;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;

final class FpcHtmlDiskPageGenerationSlotTest extends TestCase
{
    private const HOST = 'pg-slot.test';

    private const URL = 'https://pg-slot.test/fpc-pg-slot-page';

    protected function tearDown(): void
    {
        $this->removeHostTree();
        parent::tearDown();
    }

    public function testGenerationMismatchSkipsCacheAndKeepsOldSlot(): void
    {
        $published = FpcHtmlDiskStore::publish(self::URL, '<html><body>slot-a</body></html>');
        if ($published !== true) {
            self::markTestSkipped('html-disk publish did not accept this body');
        }

        $hit = FpcHtmlDiskStore::read(self::URL);
        self::assertIsArray($hit);
        $token = FpcHtmlDiskStore::pageGenerationToken();
        $oldLeaf = FpcHtmlDiskStore::liveRoot()
            . DIRECTORY_SEPARATOR . self::HOST
            . DIRECTORY_SEPARATOR . FpcHtmlDiskStore::PAGE_GEN_DIR
            . DIRECTORY_SEPARATOR . $token
            . DIRECTORY_SEPARATOR . 'fpc-pg-slot-page'
            . DIRECTORY_SEPARATOR . 'index.html.br';
        self::assertFileExists($oldLeaf);

        $other = 'bbbbbbbbbbbbbbbb';
        $point = new \ReflectionMethod(FpcHtmlDiskStore::class, 'pointHostSlot');
        $point->setAccessible(true);
        $point->invoke(null, FpcHtmlDiskStore::liveRoot(), self::HOST, $other);

        self::assertNull(FpcHtmlDiskStore::read(self::URL));
        self::assertFileExists($oldLeaf);

        $point->invoke(null, FpcHtmlDiskStore::liveRoot(), self::HOST, $token);
        $restored = FpcHtmlDiskStore::read(self::URL);
        self::assertIsArray($restored);
        self::assertFileExists($oldLeaf);
    }

    public function testNamedRequestReadsThatSlotEvenWhenCurrentPointsElsewhere(): void
    {
        $published = FpcHtmlDiskStore::publish(self::URL, '<html><body>slot-a</body></html>');
        if ($published !== true) {
            self::markTestSkipped('html-disk publish did not accept this body');
        }
        $token = FpcHtmlDiskStore::pageGenerationToken();
        $other = 'bbbbbbbbbbbbbbbb';
        $point = new \ReflectionMethod(FpcHtmlDiskStore::class, 'pointHostSlot');
        $point->setAccessible(true);
        $point->invoke(null, FpcHtmlDiskStore::liveRoot(), self::HOST, $other);

        self::assertNull(FpcHtmlDiskStore::read(self::URL));
        $named = FpcHtmlDiskStore::read(self::URL . '?w_pg=' . $token);
        self::assertIsArray($named);
        self::assertNull(FpcHtmlDiskStore::read(self::URL . '?w_pg=not-a-token'));
        $asked = FpcHtmlDiskStore::requestedPageGeneration(self::URL . '?w_pg=' . $token);
        self::assertTrue($asked['present']);
        self::assertSame($token, $asked['token']);
    }

    private function removeHostTree(): void
    {
        $dir = FpcHtmlDiskStore::liveRoot() . DIRECTORY_SEPARATOR . self::HOST;
        if (!\is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                @\unlink($item->getPathname());
                continue;
            }
            if ($item->isDir()) {
                @\rmdir($item->getPathname());
            }
        }
        @\rmdir($dir);
    }
}
