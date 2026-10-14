<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\System\File;

\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('IS_WIN') || \define('IS_WIN', \DIRECTORY_SEPARATOR === '\\');
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', sys_get_temp_dir() . '/weline-scan-app-code/');
\defined('VENDOR_PATH') || \define('VENDOR_PATH', sys_get_temp_dir() . '/weline-scan-vendor/');

use PHPUnit\Framework\TestCase;
use Weline\Framework\System\File\Data\File;
use Weline\Framework\System\File\Scan;

/**
 * scanDirTree must use a per-walk context (like dirToArray/globFile), never
 * instance keepLevel/dirs/visited — DI singleton reuse + mid-walk exceptions
 * used to mix theme origins during bare theme:upgrade.
 */
final class ScanDirTreeIsolationContractTest extends TestCase
{
    public function testSequentialTopLevelScansDoNotMixOrigins(): void
    {
        $workspace = sys_get_temp_dir() . '/weline-scan-iso-' . bin2hex(random_bytes(6));
        $a = $workspace . '/theme-a';
        $b = $workspace . '/theme-b';
        self::assertTrue(mkdir($a . '/assets', 0775, true));
        self::assertTrue(mkdir($b . '/assets', 0775, true));
        self::assertNotFalse(file_put_contents($a . '/assets/a.css', '.a{}'));
        self::assertNotFalse(file_put_contents($b . '/assets/b.css', '.b{}'));

        try {
            $scan = new Scan();
            $first = $scan->scanDirTree($a);
            $second = $scan->scanDirTree($b);

            $firstOrigins = $this->origins($first);
            $secondOrigins = $this->origins($second);

            self::assertNotEmpty($firstOrigins);
            self::assertNotEmpty($secondOrigins);
            foreach ($firstOrigins as $origin) {
                self::assertStringContainsString('/theme-a/', str_replace('\\', '/', $origin));
                self::assertStringNotContainsString('/theme-b/', str_replace('\\', '/', $origin));
            }
            foreach ($secondOrigins as $origin) {
                self::assertStringContainsString('/theme-b/', str_replace('\\', '/', $origin));
                self::assertStringNotContainsString('/theme-a/', str_replace('\\', '/', $origin));
            }
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testMidWalkExceptionDoesNotPoisonNextTopLevelScan(): void
    {
        $workspace = sys_get_temp_dir() . '/weline-scan-boom-' . bin2hex(random_bytes(6));
        $boom = $workspace . '/boom-theme';
        $ok = $workspace . '/ok-theme';
        self::assertTrue(mkdir($boom . '/nested', 0775, true));
        self::assertTrue(mkdir($ok . '/assets', 0775, true));
        self::assertNotFalse(file_put_contents($boom . '/nested/x.css', '.x{}'));
        self::assertNotFalse(file_put_contents($ok . '/assets/ok.css', '.ok{}'));

        $scan = new class extends Scan {
            public bool $throwOnNested = true;

            protected function scanDirTreeInto(
                string $dirPath,
                int $level,
                int $walkDepth,
                array &$dirs,
                array &$visited,
            ): void {
                if ($this->throwOnNested && $walkDepth > 1) {
                    throw new \RuntimeException('mid-walk failure');
                }
                parent::scanDirTreeInto($dirPath, $level, $walkDepth, $dirs, $visited);
            }
        };

        try {
            try {
                $scan->scanDirTree($boom);
                self::fail('expected mid-walk exception');
            } catch (\RuntimeException $exception) {
                self::assertSame('mid-walk failure', $exception->getMessage());
            }

            $scan->throwOnNested = false;
            $origins = $this->origins($scan->scanDirTree($ok));
            self::assertNotEmpty($origins);
            foreach ($origins as $origin) {
                self::assertStringContainsString('/ok-theme/', str_replace('\\', '/', $origin));
                self::assertStringNotContainsString('/boom-theme/', str_replace('\\', '/', $origin));
            }
        } finally {
            $this->removeTree($workspace);
        }
    }

    public function testScanDirTreeUsesPerWalkContextNotInstanceKeepLevel(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 4) . '/System/File/Scan.php');
        self::assertStringContainsString('function scanDirTreeInto(', $src);
        self::assertStringContainsString('$dirs = [];', $src);
        self::assertStringContainsString('$visited = [];', $src);
        self::assertStringNotContainsString('private int $keepLevel', $src);
        self::assertStringNotContainsString('private array $visitedRealPaths', $src);
        self::assertStringNotContainsString('$this->keepLevel', $src);
        self::assertStringNotContainsString('$this->visitedRealPaths', $src);
        // Tree results must not write into the scanDir() instance buffer.
        self::assertDoesNotMatchRegularExpression(
            '/scanDirTreeInto[\s\S]*?\$this->dirs\[/',
            $src
        );
    }

    /**
     * @param array<string, list<File>> $tree
     * @return list<string>
     */
    private function origins(array $tree): array
    {
        $out = [];
        foreach ($tree as $group) {
            foreach ($group as $file) {
                if ($file instanceof File) {
                    $out[] = $file->getOrigin();
                }
            }
        }

        return $out;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
