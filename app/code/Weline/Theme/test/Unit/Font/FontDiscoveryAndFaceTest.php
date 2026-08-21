<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Font;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Font\FontDiscovery;
use Weline\Theme\Font\FontFaceService;
use Weline\Theme\Font\FontSubsetService;
use Weline\Theme\Font\LanguageCharsetResolver;

final class FontDiscoveryAndFaceTest extends TestCase
{
    private string $moduleRoot;

    private string $cacheDir;

    private string $fixtureFont;

    protected function setUp(): void
    {
        $this->fixtureFont = dirname(__DIR__, 3) . '/Font/test/fixtures/ahem.ttf';
        self::assertFileExists($this->fixtureFont);

        $this->moduleRoot = sys_get_temp_dir() . '/weline-font-mod-' . bin2hex(random_bytes(4));
        $fontsDir = $this->moduleRoot . '/view/fonts/brand';
        mkdir($fontsDir, 0775, true);
        copy($this->fixtureFont, $fontsDir . '/Demo.ttf');

        $this->cacheDir = sys_get_temp_dir() . '/weline-font-face-cache-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->moduleRoot);
        $this->removeTree($this->cacheDir);
    }

    public function testDiscoverFindsModuleViewFonts(): void
    {
        $discovery = new FontDiscovery();
        $found = $discovery->discover([
            'Acme_Demo' => [
                'status' => 1,
                'base_path' => $this->moduleRoot . '/',
            ],
        ]);

        self::assertCount(1, $found);
        self::assertSame('Acme_Demo', $found[0]['module']);
        self::assertSame('brand/Demo.ttf', $found[0]['relative']);
        self::assertSame('Acme_Demo::brand/Demo.ttf', $found[0]['source']);
        self::assertFileExists($found[0]['path']);
    }

    public function testResolveSourceAndRenderCss(): void
    {
        $discovery = new FontDiscovery();
        $absolute = $discovery->resolveSource('Acme_Demo::brand/Demo.ttf', [
            'Acme_Demo' => [
                'status' => 1,
                'base_path' => $this->moduleRoot . '/',
            ],
        ]);
        self::assertNotNull($absolute);

        $subset = new FontSubsetService(new LanguageCharsetResolver(), $this->cacheDir);
        $face = new FontFaceService($subset, $discovery);

        // FontFaceService resolves via Env modules; inject path via absolute src instead.
        $css = $face->renderCss([
            'src' => $absolute,
            'family' => 'Demo',
            'lang' => 'en',
            'weight' => '400',
        ]);
        self::assertStringContainsString('@font-face', $css);
        self::assertStringContainsString("font-family:'Demo'", $css);
        self::assertStringContainsString('/pub/media/font-subset/', $css);

        $path = $subset->getSubsetPath($absolute, 'en');
        self::assertStringStartsWith('/pub/media/font-subset/', $subset->pathToUrl($path));
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
