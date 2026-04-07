<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Extends\Module\Weline_Websites\WebsiteThemeSource;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Extends\Module\Weline_Framework\Query\ThemeQueryProvider;
use Weline\Theme\Extends\Module\Weline_Websites\WebsiteThemeSource\WelineThemeSource;

class WelineThemeSourceTest extends TestCase
{
    private WelineThemeSource $source;
    private ThemeQueryProvider $mockQueryProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockQueryProvider = $this->createMock(ThemeQueryProvider::class);
        $this->source = new WelineThemeSource($this->mockQueryProvider);
    }

    public function testGetCodeReturnsWelineTheme(): void
    {
        $this->assertSame('weline_theme', $this->source->getCode());
    }

    public function testGetNameIsNotEmpty(): void
    {
        $this->assertNotEmpty($this->source->getName());
    }

    public function testGetDescriptionIsNotEmpty(): void
    {
        $this->assertNotEmpty($this->source->getDescription());
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->source->isEnabled());
    }

    public function testGetSortOrderReturnsPositiveInteger(): void
    {
        $this->assertGreaterThan(0, $this->source->getSortOrder());
    }

    public function testListThemesReturnsArrayStructure(): void
    {
        $this->mockQueryProvider
            ->expects($this->once())
            ->method('execute')
            ->willReturn([]);

        $result = $this->source->listThemes();

        $this->assertIsArray($result);
    }

    public function testListThemesFiltersInvalidThemeIds(): void
    {
        $this->mockQueryProvider
            ->expects($this->once())
            ->method('execute')
            ->willReturn([
                ['theme_id' => 1, 'theme_name' => 'Valid Theme', 'theme_path' => '/valid', 'layout_count' => 5],
                ['theme_id' => 0, 'theme_name' => 'Invalid Theme'],
                ['theme_id' => -1, 'theme_name' => 'Another Invalid'],
            ]);

        $result = $this->source->listThemes();

        $this->assertCount(1, $result);
        $this->assertSame('Valid Theme', $result[0]['theme_name']);
    }

    public function testListPageTypesReturnsExpectedPageTypes(): void
    {
        $result = $this->source->listPageTypes();

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, \count($result));

        $homePage = null;
        foreach ($result as $page) {
            if ($page['code'] === 'home_page') {
                $homePage = $page;
                break;
            }
        }

        $this->assertNotNull($homePage);
        $this->assertArrayHasKey('label', $homePage);
        $this->assertArrayHasKey('description', $homePage);
        $this->assertArrayHasKey('is_default', $homePage);
        $this->assertTrue($homePage['is_default']);
    }

    public function testListPageTypesIncludesDefaultPages(): void
    {
        $result = $this->source->listPageTypes();
        $codes = \array_column($result, 'code');

        $this->assertContains('home_page', $codes);
        $this->assertContains('about_page', $codes);
        $this->assertContains('contact_page', $codes);
    }

    public function testGetLayoutsForPageTypeReturnsArray(): void
    {
        $this->mockQueryProvider
            ->expects($this->once())
            ->method('execute')
            ->willReturn([]);

        $result = $this->source->getLayoutsForPageType('home_page');

        $this->assertIsArray($result);
    }

    public function testListThemesReturnsExpectedKeys(): void
    {
        $this->mockQueryProvider
            ->expects($this->once())
            ->method('execute')
            ->willReturn([
                [
                    'theme_id' => 5,
                    'theme_name' => 'Test Theme',
                    'theme_path' => '/test/path',
                    'layout_count' => 3,
                    'layout_types' => ['home_page', 'about_page'],
                ],
            ]);

        $result = $this->source->listThemes();

        $this->assertCount(1, $result);
        $theme = $result[0];
        $this->assertArrayHasKey('theme_id', $theme);
        $this->assertArrayHasKey('theme_name', $theme);
        $this->assertArrayHasKey('theme_path', $theme);
        $this->assertArrayHasKey('layout_count', $theme);
        $this->assertArrayHasKey('source', $theme);
        $this->assertSame('weline_theme_library', $theme['source']);
    }
}
