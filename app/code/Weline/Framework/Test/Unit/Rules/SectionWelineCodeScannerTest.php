<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Rules;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Rules\Frontend\SectionWelineCodeScanner;

final class SectionWelineCodeScannerTest extends TestCase
{
    private SectionWelineCodeScanner $scanner;
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->scanner = new SectionWelineCodeScanner();
        $this->fixtureRoot = sys_get_temp_dir() . '/weline-section-weline-code-' . getmypid();
        $this->rimraf($this->fixtureRoot);
        mkdir($this->fixtureRoot . '/theme/frontend', 0777, true);
        mkdir($this->fixtureRoot . '/Backend', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rimraf($this->fixtureRoot);
    }

    public function testMissingLiteralSectionIsViolation(): void
    {
        $file = $this->write('theme/frontend/missing.phtml', "<section class=\"x\">\n</section>\n");
        $violations = $this->scanner->scanFile($file, 'theme/frontend/missing.phtml');
        self::assertCount(1, $violations);
        self::assertSame(SectionWelineCodeScanner::TYPE_LITERAL, $violations[0]['type']);
    }

    public function testEmptyLiteralCodeIsViolation(): void
    {
        $file = $this->write('theme/frontend/empty.phtml', "<section weline-code=\"\">\n</section>\n");
        $violations = $this->scanner->scanFile($file, 'theme/frontend/empty.phtml');
        self::assertCount(1, $violations);
        self::assertSame(SectionWelineCodeScanner::TYPE_LITERAL, $violations[0]['type']);
    }

    public function testPhpInterpolationIsAccepted(): void
    {
        $file = $this->write(
            'theme/frontend/dynamic.phtml',
            "<section weline-code=\"<?= \$code ?>\">\n</section>\n"
        );
        $violations = $this->scanner->scanFile($file, 'theme/frontend/dynamic.phtml');
        self::assertSame([], $violations);
    }

    public function testMultilineOpenTagIsDetected(): void
    {
        $file = $this->write(
            'theme/frontend/multi.phtml',
            "<section\n  class=\"x\"\n  weline-code=\"theme.demo.hero\"\n>\n</section>\n"
        );
        $violations = $this->scanner->scanFile($file, 'theme/frontend/multi.phtml');
        self::assertSame([], $violations);
    }

    public function testSlotSectionWithoutCodeIsViolation(): void
    {
        $file = $this->write(
            'theme/frontend/slot.phtml',
            "<w:slot id=\"hero\" wrapper=\"section\" class=\"x\"></w:slot>\n"
        );
        $violations = $this->scanner->scanFile($file, 'theme/frontend/slot.phtml');
        self::assertCount(1, $violations);
        self::assertSame(SectionWelineCodeScanner::TYPE_SLOT, $violations[0]['type']);
    }

    public function testDuplicateCodesAcrossLiteralAndSlot(): void
    {
        $file = $this->write(
            'theme/frontend/dup.phtml',
            "<section weline-code=\"theme.demo.hero\"></section>\n"
            . "<w:slot id=\"a\" wrapper=\"section\" weline-code=\"theme.demo.hero\"></w:slot>\n"
        );
        $violations = $this->scanner->scanFile($file, 'theme/frontend/dup.phtml');
        self::assertNotSame([], $violations);
        self::assertSame(SectionWelineCodeScanner::TYPE_DUPLICATE, $violations[0]['type']);
    }

    public function testBackendPathIsExcludedFromProjectScan(): void
    {
        $this->write('Backend/admin.phtml', "<section class=\"x\"></section>\n");
        $this->write('theme/frontend/ok.phtml', "<section weline-code=\"theme.demo.ok\"></section>\n");
        $violations = $this->scanner->scanProject($this->fixtureRoot);
        self::assertSame([], $violations);
    }

    private function write(string $relative, string $contents): string
    {
        $abs = $this->fixtureRoot . '/' . $relative;
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($abs, $contents);

        return $abs;
    }

    private function rimraf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
