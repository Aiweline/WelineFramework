<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Theme;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityFormalLocaleCompileService;
use Weline\Theme\Service\Theme\LayoutCriticalCssMaterializer;

\defined('BP') || \define('BP', \dirname(__DIR__, 8) . \DIRECTORY_SEPARATOR);

final class LayoutCriticalCssMaterializerContractTest extends TestCase
{
    public function testFormalWarmWiresMaterializerBesideHeadPacks(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php'
        );
        self::assertStringContainsString(LayoutCriticalCssMaterializer::class, $src);
        self::assertStringContainsString('layoutCriticalMaterializer', $src);
        self::assertStringContainsString('materializeForTheme', $src);
        self::assertTrue(method_exists(ThemeLayoutEntityFormalLocaleCompileService::class, 'compileAfterPromote'));
    }

    public function testGeneratedMarkerConstantStable(): void
    {
        self::assertSame(
            'weline-layout-critical:generated',
            LayoutCriticalCssMaterializer::GENERATED_MARKER
        );
    }

    public function testBaseThemeShellIsNotDesign(): void
    {
        $theme = $this->themeStub(
            id: 1,
            module: 'Weline_Theme',
            path: BP . 'app/code/Weline/Theme/view/theme/'
        );
        $materializer = new LayoutCriticalCssMaterializer();
        self::assertFalse($materializer->isDesignTheme($theme));
        self::assertSame([], $materializer->materializeForTheme($theme, 'frontend'));
    }

    public function testDesignThemeMaterializesFrontendCriticalUnderOwnTree(): void
    {
        $designRoot = BP . 'app/design/Weline/hanfu';
        if (!is_dir($designRoot)) {
            self::markTestSkipped('hanfu design theme missing');
        }

        $theme = $this->themeStub(id: 3, module: 'Weline_Hanfu', path: $designRoot . '/');
        $materializer = new LayoutCriticalCssMaterializer();
        self::assertTrue($materializer->isDesignTheme($theme));

        $written = $materializer->materializeForTheme($theme, 'frontend');
        $target = $designRoot . '/frontend/assets/css/layout-critical.css';
        self::assertFileExists($target);
        $body = (string)file_get_contents($target);
        self::assertStringContainsString(LayoutCriticalCssMaterializer::GENERATED_MARKER, $body);
        self::assertStringContainsString('.w-frontend-shell', $body);
        self::assertTrue($written === [] || in_array($target, $written, true));

        // Second call with same baseline must be a no-op write.
        $again = $materializer->materializeForTheme($theme, 'frontend');
        self::assertSame([], $again);
    }

    private function themeStub(int $id, string $module, string $path): WelineTheme
    {
        $theme = $this->getMockBuilder(WelineTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getModuleName', 'getPath'])
            ->getMock();
        $theme->method('getId')->willReturn($id);
        $theme->method('getModuleName')->willReturn($module);
        $theme->method('getPath')->willReturn($path);

        return $theme;
    }
}
