<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemeLayoutLocator;

final class ThemeLayoutLocatorTest extends TestCase
{
    private string $designArea = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->designArea = sys_get_temp_dir() . '/weline-layout-locator-' . bin2hex(random_bytes(6)) . '/frontend';
        $this->writeLayout('site-hub/default.phtml', "<?php\n/**\n * @meta.name {default=\"站点枢纽\",name=\"站点枢纽\"}\n */\n");
        $this->writeLayout('shop/default.phtml', "<?php\n");
    }

    protected function tearDown(): void
    {
        $root = dirname($this->designArea);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
        parent::tearDown();
    }

    public function testDesignOnlyLayoutIsThemeDeclaredWithItsMetaTitle(): void
    {
        $locator = $this->locator();

        self::assertSame($this->designArea . '/layouts/site-hub/default.phtml', $locator->themeDeclaredFile('site-hub'));
        self::assertSame('站点枢纽', $locator->themeDeclaredTitle('site-hub'));
        self::assertNull($locator->moduleDefaultFile('site-hub'));
    }

    public function testModuleShippedTypeStaysModuleOwnedEvenWhenThemeOverridesIt(): void
    {
        $locator = $this->locator();

        self::assertNotNull($locator->moduleDefaultFile('shop'));
        self::assertNull($locator->themeDeclaredFile('shop'));
        self::assertSame('', $locator->themeDeclaredTitle('shop'));
    }

    public function testModuleTreeShipsNoSiteSpecificPageTypes(): void
    {
        $locator = $this->locator();

        foreach (['sanctuary', 'lounge', 'journal', 'journal-article', 'readingnote', 'article'] as $type) {
            self::assertNull($locator->moduleDefaultFile($type), $type);
        }
    }

    public function testUnknownOrMalformedTypesAreNotLayouts(): void
    {
        $locator = $this->locator();

        self::assertNull($locator->themeDeclaredFile('missing'));
        self::assertNull($locator->themeDeclaredFile('../site-hub'));
        self::assertNull($locator->themeDeclaredFile('site-hub', '../default'));
    }

    public function testNonThemeLayersAreIgnored(): void
    {
        $directories = $this->createMock(ThemeDirectoryResolver::class);
        $directories->method('getAreaDirectories')->willReturn([
            ['path' => $this->designArea, 'layer_type' => 'module'],
        ]);

        self::assertNull((new ThemeLayoutLocator($directories))->themeDeclaredFile('site-hub'));
    }

    private function locator(): ThemeLayoutLocator
    {
        $directories = $this->createMock(ThemeDirectoryResolver::class);
        $directories->method('getAreaDirectories')->willReturn([
            ['path' => $this->designArea, 'layer_type' => 'theme'],
        ]);

        return new ThemeLayoutLocator($directories);
    }

    private function writeLayout(string $relative, string $contents): void
    {
        $file = $this->designArea . '/layouts/' . $relative;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }
}
