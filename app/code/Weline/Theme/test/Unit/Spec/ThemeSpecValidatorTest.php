<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Spec;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Spec\ThemeSpecValidator;
use Weline\Theme\Service\Spec\ThemeSpecViolationException;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * 主题规范门禁（S1–S10）自测：正向必须通过，反向必须报错。
 */
final class ThemeSpecValidatorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/weline-theme-spec-' . uniqid('', true);
        mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpRoot);
    }

    public function testBlockingStylesheetInFrontendHeadIsS2Violation(): void
    {
        $file = $this->write(
            'app/design/demo/frontend/partials/head/skin.phtml',
            '<link rel="stylesheet" href="/static/demo.css">'
        );

        $violations = (new ThemeSpecValidator())->validateFile($file);

        self::assertSame(['S2'], array_column($violations, 'rule'));
        self::assertSame('hard', $violations[0]['severity']);
    }

    public function testPreloadSwitchWithNoscriptFallbackIsCompliant(): void
    {
        $file = $this->write(
            'app/design/demo/frontend/partials/head/skin.phtml',
            '<link rel="preload" as="style" href="/static/demo.css" onload="this.onload=null;this.rel=\'stylesheet\'">'
            . '<noscript><link rel="stylesheet" data-weline-preload-fallback="1" href="/static/demo.css"></noscript>'
        );

        self::assertSame([], (new ThemeSpecValidator())->validateFile($file));
    }

    public function testMediaPrintTrickIsS1Violation(): void
    {
        $file = $this->write(
            'app/design/demo/frontend/partials/head/skin.phtml',
            '<link rel="stylesheet" href="/static/demo.css" media="print" onload="this.media=\'all\'">'
        );

        $rules = array_column((new ThemeSpecValidator())->validateFile($file), 'rule');
        self::assertContains('S1', $rules);
    }

    public function testFirstScreenImageWithoutDimensionsIsS5AndLazyIsS6(): void
    {
        $file = $this->write(
            'app/design/demo/frontend/partials/head/hero.phtml',
            '<img src="/hero.webp" class="homepage-hero" loading="lazy" alt="hero">'
        );

        $rules = array_column((new ThemeSpecValidator())->validateFile($file), 'rule');
        self::assertContains('S5', $rules);
        self::assertContains('S6', $rules);
    }

    public function testHandWrittenOpenGraphInHeadIsS8Violation(): void
    {
        $file = $this->write(
            'app/design/demo/frontend/layouts/home/default.phtml',
            "<html><head><meta property=\"og:title\" content=\"x\"></head><body></body></html>"
        );

        self::assertSame(['S8'], array_column((new ThemeSpecValidator())->validateFile($file), 'rule'));
    }

    public function testBodyLevelJsonLdFragmentIsAllowed(): void
    {
        $file = $this->write(
            'app/design/demo/frontend/layouts/guide/size-guide.phtml',
            "<article><script type=\"application/ld+json\">{\"@type\":\"FAQPage\"}</script></article>"
        );

        self::assertSame([], (new ThemeSpecValidator())->validateFile($file));
    }

    public function testOversizedLayoutCriticalCssIsS4Violation(): void
    {
        $file = $this->write(
            'app/design/demo/frontend/assets/css/layout-critical.css',
            str_repeat('.x{color:#000}', 1200)
        );

        self::assertSame(['S4'], array_column((new ThemeSpecValidator())->validateFile($file), 'rule'));
    }

    public function testDevCompileGateThrowsForHardViolation(): void
    {
        $file = $this->write(
            'app/design/demo/frontend/partials/head/skin.phtml',
            '<link rel="stylesheet" href="/static/demo.css">'
        );

        $this->expectException(ThemeSpecViolationException::class);
        ThemeSpecValidator::assertFileForDev($file);
    }

    public function testDevCompileGateIgnoresNonThemeTemplates(): void
    {
        $file = $this->write(
            'app/code/Weline/Demo/view/templates/panel.phtml',
            '<link rel="stylesheet" href="/static/demo.css">'
        );

        ThemeSpecValidator::assertFileForDev($file);
        $this->addToAssertionCount(1);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->tmpRoot . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
