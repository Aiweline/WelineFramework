<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeDirectoryResolver;

class LayoutPathResolverTest extends TestCore
{
    public function setUp(): void
    {
        parent::setUp();
        ObjectManager::getInstance(ThemeDirectoryResolver::class)->clearCache();
    }

    public function testResolveLayoutTemplateKeepsDefaultThemeSeparateFromMotor(): void
    {
        $theme = $this->loadTheme(10);

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 10 not found.');
        }

        $layoutModulePath = LayoutPathResolver::resolveLayoutTemplate(
            'theme' . DS . 'frontend' . DS . 'layouts' . DS . 'homepage' . DS . 'default.phtml',
            $theme,
            'frontend'
        );

        $this->assertSame('Weline_Theme::theme/frontend/layouts/homepage/default.phtml', $layoutModulePath);
        $this->assertSame(
            BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'layouts' . DS . 'homepage' . DS . 'default.phtml',
            LayoutPathResolver::getLayoutFilePath((string)$layoutModulePath, $theme, 'frontend')
        );
    }

    public function testResolveLayoutTemplateUsesThemeDesignPath(): void
    {
        $theme = $this->loadTheme(11);

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        $layoutModulePath = LayoutPathResolver::resolveLayoutTemplate(
            'theme' . DS . 'frontend' . DS . 'layouts' . DS . 'homepage' . DS . 'default.phtml',
            $theme,
            'frontend'
        );

        $this->assertSame('Weline_Theme::theme/frontend/layouts/homepage/default.phtml', $layoutModulePath);
        $this->assertSame(
            BP . 'app' . DS . 'design' . DS . 'WeShop' . DS . 'motor' . DS . 'frontend' . DS . 'layouts' . DS . 'homepage' . DS . 'default.phtml',
            LayoutPathResolver::getLayoutFilePath((string)$layoutModulePath, $theme, 'frontend')
        );
    }

    public function testResolveLayoutTemplateAllowsThemeOnlyLayoutType(): void
    {
        $theme = $this->loadTheme(11);

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        $layoutModulePath = LayoutPathResolver::resolveLayoutTemplate(
            'theme' . DS . 'frontend' . DS . 'layouts' . DS . 'about' . DS . 'default.phtml',
            $theme,
            'frontend'
        );

        $this->assertSame('Weline_Theme::theme/frontend/layouts/about/default.phtml', $layoutModulePath);
        $resolved = LayoutPathResolver::getLayoutFilePath((string)$layoutModulePath, $theme, 'frontend');
        $this->assertNotNull($resolved);
        $this->assertFileExists((string)$resolved);
    }

    public function testResolveLayoutTemplateFindsDefaultQaLayout(): void
    {
        $theme = $this->loadTheme(6);

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 6 not found.');
        }

        $layoutModulePath = LayoutPathResolver::resolveLayoutTemplate(
            'theme' . DS . 'frontend' . DS . 'layouts' . DS . 'qa' . DS . 'default.phtml',
            $theme,
            'frontend'
        );

        $this->assertSame('Weline_Theme::theme/frontend/layouts/qa/default.phtml', $layoutModulePath);
        $this->assertSame(
            BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'layouts' . DS . 'qa' . DS . 'default.phtml',
            LayoutPathResolver::getLayoutFilePath((string)$layoutModulePath, $theme, 'frontend')
        );
    }

    private function loadTheme(int $themeId): WelineTheme
    {
        /** @var WelineTheme $theme */
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->load($themeId);

        return $theme;
    }
}
