<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Deploy\StaticCarryOver;

final class StaticCarryOverTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/weline-static-carry-' . \bin2hex(\random_bytes(6));
        self::assertTrue(\mkdir($this->root, 0775, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCarryForwardKeepsOldHashedBytesAndNewStagingWins(): void
    {
        $live = $this->root . '/live';
        $staging = $this->root . '/staging';
        $manifest = $this->root . '/var/deploy/static-carry.json';
        $this->write($live . '/theme/Weline/Theme/theme-head/old.css', 'old-css');
        $this->write($live . '/theme/Weline/Theme/theme-head/shared.css', 'old-shared');
        $this->write($staging . '/theme/Weline/Theme/theme-head/new.css', 'new-css');
        $this->write($staging . '/theme/Weline/Theme/theme-head/shared.css', 'new-shared');

        $carry = new StaticCarryOver($manifest, 400 * 86400);
        $result = $carry->carryForward($live, $staging);

        self::assertSame(1, $result['carried']);
        self::assertFileExists($staging . '/theme/Weline/Theme/theme-head/old.css');
        self::assertSame('old-css', \file_get_contents($staging . '/theme/Weline/Theme/theme-head/old.css'));
        self::assertSame('new-css', \file_get_contents($staging . '/theme/Weline/Theme/theme-head/new.css'));
        self::assertSame('new-shared', \file_get_contents($staging . '/theme/Weline/Theme/theme-head/shared.css'));
        self::assertSame(
            \fileinode($live . '/theme/Weline/Theme/theme-head/old.css'),
            \fileinode($staging . '/theme/Weline/Theme/theme-head/old.css'),
            'Carry must hardlink when live and staging share a filesystem.',
        );

        $decoded = \json_decode((string)\file_get_contents($manifest), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('theme/Weline/Theme/theme-head/old.css', $decoded['entries']);
        self::assertArrayNotHasKey('theme/Weline/Theme/theme-head/new.css', $decoded['entries']);
        self::assertArrayNotHasKey('theme/Weline/Theme/theme-head/shared.css', $decoded['entries']);
    }

    public function testCarryForwardSkipsNonServableAndPublishExcludedPaths(): void
    {
        $live = $this->root . '/live';
        $staging = $this->root . '/staging';
        \mkdir($staging, 0775, true);
        $this->write($live . '/theme/a.css', 'a');
        $this->write($live . '/theme/a.js', 'a');
        $this->write($live . '/theme/a.scss', 'a');
        $this->write($live . '/Weline/Framework/view/statics/php/connector.php', '<?php');
        $this->write($live . '/.DS_Store', 'x');

        $carry = new StaticCarryOver($this->root . '/manifest.json', 86400);
        $carry->carryForward($live, $staging);

        self::assertFileExists($staging . '/theme/a.css');
        self::assertFileExists($staging . '/theme/a.js');
        self::assertFileDoesNotExist($staging . '/theme/a.scss');
        self::assertFileDoesNotExist($staging . '/Weline/Framework/view/statics/php/connector.php');
        self::assertFileDoesNotExist($staging . '/.DS_Store');
    }

    public function testRecarryDoesNotExtendRetentionClock(): void
    {
        $live = $this->root . '/live';
        $staging = $this->root . '/staging';
        $manifest = $this->root . '/manifest.json';
        $this->write($live . '/theme/old.css', 'old');
        \mkdir($staging, 0775, true);
        $stat = \stat($live . '/theme/old.css');
        self::assertIsArray($stat);
        $firstCarriedAt = \time() - 100;
        $this->write($manifest, (string)\json_encode([
            'version' => 1,
            'entries' => [
                'theme/old.css' => [
                    'carried_at' => $firstCarriedAt,
                    'mtime' => (int)$stat['mtime'],
                    'size' => (int)$stat['size'],
                    'dev' => (int)$stat['dev'],
                    'ino' => (int)$stat['ino'],
                ],
            ],
        ]));

        $carry = new StaticCarryOver($manifest, 400 * 86400);
        $carry->carryForward($live, $staging);

        $decoded = \json_decode((string)\file_get_contents($manifest), true);
        self::assertIsArray($decoded);
        self::assertSame($firstCarriedAt, $decoded['entries']['theme/old.css']['carried_at']);
    }

    public function testPruneExpiredRemovesOnlyRecordedTombstones(): void
    {
        $live = $this->root . '/live';
        $staging = $this->root . '/staging';
        $manifest = $this->root . '/manifest.json';
        $this->write($live . '/theme/old.css', 'old');
        $this->write($live . '/theme/native.css', 'native');
        $this->write($staging . '/theme/native.css', 'native-v2');

        $carry = new StaticCarryOver($manifest, 86400);
        $carry->carryForward($live, $staging);
        self::assertFileExists($live . '/theme/old.css');

        $result = $carry->pruneExpired($live, \time() + 2 * 86400);

        self::assertSame(1, $result['pruned']);
        self::assertFileDoesNotExist($live . '/theme/old.css');
        self::assertFileExists($live . '/theme/native.css');
        self::assertSame('native', \file_get_contents($live . '/theme/native.css'));
    }

    public function testPruneDoesNotDeleteTombstoneReplacedByNativePublish(): void
    {
        $live = $this->root . '/live';
        $staging = $this->root . '/staging';
        $manifest = $this->root . '/manifest.json';
        $this->write($live . '/theme/old.css', 'old');
        \mkdir($staging, 0775, true);

        $carry = new StaticCarryOver($manifest, 86400);
        $carry->carryForward($live, $staging);
        \unlink($live . '/theme/old.css');
        $this->write($live . '/theme/old.css', 'new-native');

        $result = $carry->pruneExpired($live, \time() + 2 * 86400);

        self::assertSame(0, $result['pruned']);
        self::assertSame(1, $result['replaced']);
        self::assertFileExists($live . '/theme/old.css');
        self::assertSame('new-native', \file_get_contents($live . '/theme/old.css'));
    }

    public function testRetentionZeroDisablesCarryAndPrune(): void
    {
        $live = $this->root . '/live';
        $staging = $this->root . '/staging';
        $this->write($live . '/theme/old.css', 'old');
        \mkdir($staging, 0775, true);

        $carry = new StaticCarryOver($this->root . '/manifest.json', 0);
        $result = $carry->carryForward($live, $staging);

        self::assertSame(0, $result['carried']);
        self::assertFileDoesNotExist($staging . '/theme/old.css');
        self::assertFileExists($live . '/theme/old.css');
    }

    private function write(string $path, string $contents): void
    {
        $directory = \dirname($path);
        if (!\is_dir($directory)) {
            self::assertTrue(\mkdir($directory, 0775, true));
        }
        self::assertNotFalse(\file_put_contents($path, $contents));
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if ($item->isDir()) {
                @\rmdir($itemPath);
            } else {
                @\unlink($itemPath);
            }
        }
        @\rmdir($path);
    }
}
