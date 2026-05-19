<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutCriticalCssExtractor;

final class LayoutCriticalCssExtractorTest extends TestCase
{
    private LayoutCriticalCssExtractor $extractor;
    private string $root;
    /** @var string[] */
    private array $sources = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new LayoutCriticalCssExtractor();
        $this->root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'weline-layout-critical-css-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->sources as $source) {
            $metadata = $this->metadataPathFor($source);
            if (is_file($metadata)) {
                @unlink($metadata);
            }
            $lock = dirname($metadata) . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . basename($metadata) . '.lock';
            if (is_file($lock)) {
                @unlink($lock);
            }
        }

        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testExtractsOrderedStaticStyleBlocksAndRemovesThem(): void
    {
        $content = <<<'PHTML'
<html>
<head>
    <style media="screen">
        .layout-a { color: red; }
    </style>
    <style>
        .layout-b { color: blue; }
    </style>
</head>
<body>content</body>
</html>
PHTML;
        $source = $this->writeLayoutSource($content);

        $result = $this->extractor->extractAndPersist($content, $source);
        $metadata = $this->extractor->loadMetadata($source);

        self::assertStringNotContainsString('<style', $result);
        self::assertCount(2, $metadata['css']);
        self::assertSame('media="screen"', $metadata['css'][0]['attrs']);
        self::assertStringContainsString('.layout-a', $metadata['css'][0]['css']);
        self::assertStringContainsString('.layout-b', $metadata['css'][1]['css']);
        self::assertTrue($this->extractor->isMetadataFresh($source));
    }

    public function testNonLayoutTemplateIsNotExtracted(): void
    {
        $content = '<style>.page { color: red; }</style>';
        $source = $this->writeSource('view' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'page.phtml', $content);

        self::assertSame($content, $this->extractor->extractAndPersist($content, $source));
        self::assertFalse($this->extractor->shouldHandleSource($source));
        self::assertFalse(is_file($this->metadataPathFor($source)));
    }

    public function testLayoutWithoutStyleWritesEmptyFreshMetadata(): void
    {
        $content = '<main class="layout">content</main>';
        $source = $this->writeLayoutSource($content);

        self::assertSame($content, $this->extractor->extractAndPersist($content, $source));

        $metadata = $this->extractor->loadMetadata($source);
        self::assertSame([], $metadata['css']);
        self::assertTrue($this->extractor->isMetadataFresh($source));
    }

    public function testDynamicPhpInsideStyleFailsCompilation(): void
    {
        $content = <<<'PHTML'
<style>
    .layout { color: <?= $color ?>; }
</style>
PHTML;
        $source = $this->writeLayoutSource($content);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dynamic CSS is not allowed');

        $this->extractor->extractAndPersist($content, $source);
    }

    public function testRequestStateInsideStyleFailsCompilation(): void
    {
        $content = <<<'PHTML'
<style>
    .layout { background: $_SESSION["color"]; }
</style>
PHTML;
        $source = $this->writeLayoutSource($content);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dynamic CSS is not allowed');

        $this->extractor->extractAndPersist($content, $source);
    }

    public function testFingerprintChangeMarksMetadataStaleAndForcesRecompile(): void
    {
        $content = '<style>.layout { color: red; }</style>';
        $source = $this->writeLayoutSource($content);

        $this->extractor->extractAndPersist($content, $source);
        self::assertTrue($this->extractor->isMetadataFresh($source));

        usleep(1000);
        file_put_contents($source, '<style>.layout { color: green; }</style>');
        clearstatcache(true, $source);

        self::assertFalse($this->extractor->isMetadataFresh($source));
        self::assertTrue($this->extractor->shouldForceRecompile($source));
    }

    private function writeLayoutSource(string $content): string
    {
        return $this->writeSource(
            'view'
            . DIRECTORY_SEPARATOR
            . 'theme'
            . DIRECTORY_SEPARATOR
            . 'frontend'
            . DIRECTORY_SEPARATOR
            . 'layouts'
            . DIRECTORY_SEPARATOR
            . 'default'
            . DIRECTORY_SEPARATOR
            . bin2hex(random_bytes(4)) . '.phtml',
            $content
        );
    }

    private function writeSource(string $relativePath, string $content): string
    {
        $source = $this->root . DIRECTORY_SEPARATOR . $relativePath;
        $directory = dirname($source);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($source, $content);
        $this->sources[] = $source;

        return $source;
    }

    private function metadataPathFor(string $source): string
    {
        $basePath = defined('BP') ? BP : dirname(__DIR__, 6);

        return rtrim($basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'var'
            . DIRECTORY_SEPARATOR
            . 'cache'
            . DIRECTORY_SEPARATOR
            . 'theme_layout_critical'
            . DIRECTORY_SEPARATOR
            . $this->extractor->getSourceHash($source)
            . '.php';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
