<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Helper\LayoutPathResolver;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemePreviewContentRenderer;

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

    public function testPreviewContentRendererBuildsHomepageFragments(): void
    {
        $theme = $this->loadTheme(11);

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        /** @var ThemePreviewContentRenderer $renderer */
        $renderer = ObjectManager::getInstance(ThemePreviewContentRenderer::class);
        $payload = $renderer->build(11, 'homepage', 'draft');

        $this->assertSame('homepage', $payload['page_type']);
        $this->assertContains('banner', array_keys($payload['meta']));
        $this->assertContains('deals', array_keys($payload['meta']));
        $this->assertContains('categories', array_keys($payload['meta']));
        $this->assertNotSame('', trim((string)$payload['content']));
    }

    public function testPreviewContentRendererBuildsDefaultThemeHomepageContent(): void
    {
        $theme = $this->loadTheme(10);

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 10 not found.');
        }

        /** @var ThemePreviewContentRenderer $renderer */
        $renderer = ObjectManager::getInstance(ThemePreviewContentRenderer::class);
        $payload = $renderer->build(10, 'homepage', 'draft');

        $this->assertSame('homepage', $payload['page_type']);
        $this->assertContains('banner', array_keys($payload['meta']));
        $this->assertContains('deals', array_keys($payload['meta']));
        $this->assertNotSame('', trim((string)$payload['content']));
    }

    private function loadTheme(int $themeId): WelineTheme
    {
        /** @var WelineTheme $theme */
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->load($themeId);

        return $theme;
    }
}
