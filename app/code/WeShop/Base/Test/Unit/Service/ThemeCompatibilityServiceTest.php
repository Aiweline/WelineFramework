<?php

declare(strict_types=1);

namespace WeShop\Base\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Base\Service\ThemeCompatibilityManifestProvider;
use WeShop\Base\Service\ThemeCompatibilityService;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

class ThemeCompatibilityServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weshop-theme-compat-' . uniqid('', true);
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tmpDir);
    }

    public function testInspectThemeReportsMissingHostsFromDirectDesignLayout(): void
    {
        $layoutFile = $this->createLayoutFile(
            $this->tmpDir . DIRECTORY_SEPARATOR . 'child',
            'frontend/layouts/homepage/e_commerce_home_page_1.phtml',
            '<w:hook>WeShop_Promotion::homepage::deals_before</w:hook>'
        );

        $theme = $this->createThemeMock(1, 'child-theme', $this->tmpDir . DIRECTORY_SEPARATOR . 'child');
        $service = $this->createService([
            'frontend' => [
                'homepage' => [
                    'WeShop_Promotion' => [
                        'hosts' => [
                            ['type' => 'hook', 'name' => 'WeShop_Promotion::homepage::deals_before'],
                            ['type' => 'hook', 'name' => 'WeShop_Promotion::homepage::deals_content'],
                            ['type' => 'hook', 'name' => 'WeShop_Promotion::homepage::deals_after'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->inspectTheme($theme, 'frontend', 'homepage', 'e_commerce_home_page_1');

        $this->assertTrue($result['has_missing_hosts']);
        $this->assertSame($layoutFile, $result['layout_file']);
        $this->assertSame(2, $result['missing_count']);
        $this->assertSame('WeShop_Promotion', $result['missing_modules'][0]['module']);
        $this->assertSame('WeShop_Promotion::homepage::deals_content', $result['missing_hosts'][0]['name']);
        $this->assertNotSame('', (string) $result['warning_message']);
        $this->assertStringContainsString('child-theme', (string) $result['warning_message']);
    }

    public function testInspectThemeFallsBackToParentThemeViewThemeLayout(): void
    {
        $parentBase = $this->tmpDir . DIRECTORY_SEPARATOR . 'parent';
        $childBase = $this->tmpDir . DIRECTORY_SEPARATOR . 'child';

        $layoutFile = $this->createLayoutFile(
            $parentBase,
            'view/theme/frontend/layouts/checkout/default.phtml',
            '<w:hook>WeShop_Shipping::frontend::layouts::checkout::methods</w:hook>'
        );

        $parentTheme = $this->createThemeMock(2, 'parent-theme', $parentBase);
        $childTheme = $this->createThemeMock(3, 'child-theme', $childBase, [$parentTheme]);
        $childTheme->method('getThemeChain')->willReturn([$parentTheme, $childTheme]);

        $service = $this->createService([
            'frontend' => [
                'checkout' => [
                    'WeShop_Shipping' => [
                        'hosts' => [
                            ['type' => 'hook', 'name' => 'WeShop_Shipping::frontend::layouts::checkout::methods'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->inspectTheme($childTheme, 'frontend', 'checkout', 'default');

        $this->assertFalse($result['has_missing_hosts']);
        $this->assertSame($layoutFile, $result['layout_file']);
        $this->assertSame([], $result['missing_hosts']);
    }

    public function testInspectThemeReportsMissingHostsFromDirectDesignPageTemplate(): void
    {
        $pageFile = $this->createLayoutFile(
            $this->tmpDir . DIRECTORY_SEPARATOR . 'child',
            'frontend/pages/customer/index.phtml',
            '<w:hook name="WeShop_Customer::frontend::account::quick-links::before"/>'
        );

        $theme = $this->createThemeMock(4, 'child-theme', $this->tmpDir . DIRECTORY_SEPARATOR . 'child');
        $service = $this->createService([
            'frontend' => [
                'account' => [
                    '_template' => ['kind' => 'page', 'path' => 'customer/index.phtml'],
                    'WeShop_Customer' => [
                        'hosts' => [
                            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::quick-links::before'],
                            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::orders::cards'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->inspectTheme($theme, 'frontend', 'account', 'default');

        $this->assertTrue($result['has_missing_hosts']);
        $this->assertSame($pageFile, $result['layout_file']);
        $this->assertSame(1, $result['missing_count']);
        $this->assertSame('WeShop_Customer::frontend::account::orders::cards', $result['missing_hosts'][0]['name']);
    }

    public function testInspectThemeFallsBackToParentThemeViewThemePage(): void
    {
        $parentBase = $this->tmpDir . DIRECTORY_SEPARATOR . 'parent';
        $childBase = $this->tmpDir . DIRECTORY_SEPARATOR . 'child';

        $pageFile = $this->createLayoutFile(
            $parentBase,
            'view/theme/frontend/pages/b2b/index.phtml',
            '<w:hook name="WeShop_B2B::frontend::layouts::business::page-before"/><w:hook name="WeShop_B2B::frontend::partials::company::list-after"/>'
        );

        $parentTheme = $this->createThemeMock(5, 'parent-theme', $parentBase);
        $childTheme = $this->createThemeMock(6, 'child-theme', $childBase, [$parentTheme]);
        $childTheme->method('getThemeChain')->willReturn([$parentTheme, $childTheme]);

        $service = $this->createService([
            'frontend' => [
                'b2b' => [
                    '_template' => ['kind' => 'page', 'path' => 'b2b/index.phtml'],
                    'WeShop_B2B' => [
                        'hosts' => [
                            ['type' => 'hook', 'name' => 'WeShop_B2B::frontend::layouts::business::page-before'],
                            ['type' => 'hook', 'name' => 'WeShop_B2B::frontend::partials::company::list-after'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $service->inspectTheme($childTheme, 'frontend', 'b2b', 'default');

        $this->assertFalse($result['has_missing_hosts']);
        $this->assertSame($pageFile, $result['layout_file']);
        $this->assertSame([], $result['missing_hosts']);
    }

    public function testInjectPreviewBannerPrependsCompatibilityNotice(): void
    {
        $service = $this->createService([]);
        $compatibility = [
            'has_missing_hosts' => true,
            'warning_message' => 'Theme compatibility warning',
            'missing_hosts' => [
                ['type' => 'hook', 'name' => 'WeShop_Checkout::frontend::layouts::checkout::payment-content'],
            ],
        ];

        $html = '<html><body><main>Preview</main></body></html>';
        $decorated = $service->injectPreviewBanner($html, $compatibility);

        $this->assertStringContainsString('weshop-theme-compatibility-banner', $decorated);
        $this->assertStringContainsString('Theme compatibility warning', $decorated);
        $this->assertStringContainsString('WeShop_Checkout::frontend::layouts::checkout::payment-content', $decorated);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function createService(array $manifest): ThemeCompatibilityService
    {
        $manifestProvider = $this->createMock(ThemeCompatibilityManifestProvider::class);
        $manifestProvider->method('getManifest')->willReturn($manifest);

        $themeModel = $this->getMockBuilder(WelineTheme::class)
            ->disableOriginalConstructor()
            ->getMock();

        $themeContextService = $this->getMockBuilder(ThemeContextService::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new class($manifestProvider, $themeModel, $themeContextService) extends ThemeCompatibilityService {
            protected function isModuleEnabled(string $module): bool
            {
                return true;
            }
        };
    }

    /**
     * @param WelineTheme[] $themeChain
     */
    private function createThemeMock(int $id, string $name, string $path, array $themeChain = []): WelineTheme
    {
        $theme = $this->getMockBuilder(WelineTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getName', 'getPath', 'getThemeChain'])
            ->getMock();
        $theme->method('getId')->willReturn($id);
        $theme->method('getName')->willReturn($name);
        $theme->method('getPath')->willReturn(rtrim($path, '/\\'));
        $theme->method('getThemeChain')->willReturn($themeChain ?: [$theme]);

        return $theme;
    }

    private function createLayoutFile(string $basePath, string $relativePath, string $contents): string
    {
        $file = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($file, $contents);

        return $file;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
