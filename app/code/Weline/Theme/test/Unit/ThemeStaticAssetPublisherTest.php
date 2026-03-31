<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Http\Request;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeStaticAssetPublisher;

class ThemeStaticAssetPublisherTest extends TestCore
{
    private ThemeStaticAssetPublisher $publisher;

    public function setUp(): void
    {
        parent::setUp();
        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $request->setServer('REQUEST_URI', '/test');
        $request->setGet('frontend_theme_id', 0);
        $request->setGet('backend_theme_id', 0);
        $request->setGet('editor_area', '');
        $request->setGet('shell', '');
        $request->setGet('preview_mode', '');
        $request->setGet('status', '');
        $request->setGet(PreviewTokenService::TOKEN_KEY, '');
        PreviewTokenService::resetRequestState();
        ObjectManager::getInstance(ThemeDirectoryResolver::class)->clearCache();
        $this->publisher = ObjectManager::getInstance(ThemeStaticAssetPublisher::class);
    }

    public function testPublishesDesignThemeOverrideAsset(): void
    {
        $theme = $this->buildTheme(990001, 'motor', 'WeShop/motor');
        $publishedPath = $this->publisher->publishForRequestPath(
            '/Weline/Theme/view/theme/frontend/assets/css/motor.css',
            $theme
        );

        $this->assertSame(
            '/pub/static/WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/motor.css',
            $publishedPath
        );

        $basePath = rtrim(BP, '\\/') . DS;
        $sourceFile = $basePath . 'app' . DS . 'design' . DS . 'WeShop' . DS . 'motor' . DS . 'frontend' . DS . 'assets' . DS . 'css' . DS . 'motor.css';
        $publishedFile = $basePath . 'pub' . DS . 'static' . DS . 'WeShop' . DS . 'motor' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'css' . DS . 'motor.css';

        $this->assertFileExists($publishedFile);
        $this->assertSame(file_get_contents($sourceFile), file_get_contents($publishedFile));
    }

    public function testPublishesSamePathThemeOverrideEvenWhenModuleDefaultExists(): void
    {
        $theme = $this->buildTheme(990002, 'default', 'WeShop/default');
        $publishedPath = $this->publisher->publishForRequestPath(
            '/Weline/Theme/view/theme/frontend/variables/_colors.css',
            $theme
        );

        $this->assertSame(
            '/pub/static/WeShop/default/Weline/Theme/view/theme/frontend/variables/_colors.css',
            $publishedPath
        );

        $basePath = rtrim(BP, '\\/') . DS;
        $sourceFile = $basePath . 'app' . DS . 'design' . DS . 'WeShop' . DS . 'default' . DS . 'frontend' . DS . 'variables' . DS . '_colors.css';
        $publishedFile = $basePath . 'pub' . DS . 'static' . DS . 'WeShop' . DS . 'default' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'variables' . DS . '_colors.css';

        $this->assertFileExists($publishedFile);
        $this->assertSame(file_get_contents($sourceFile), file_get_contents($publishedFile));
    }

    public function testFallsBackToParentThemeOverrideBeforeModuleDefault(): void
    {
        $theme = $this->buildMockThemeChain(
            990004,
            'motor',
            'WeShop/motor',
            990005,
            'default',
            'WeShop/default'
        );

        $publishedPath = $this->publisher->publishForRequestPath(
            '/Weline/Theme/view/theme/frontend/variables/_colors.css',
            $theme
        );

        $this->assertSame(
            '/pub/static/WeShop/motor/Weline/Theme/view/theme/frontend/variables/_colors.css',
            $publishedPath
        );

        $basePath = rtrim(BP, '\\/') . DS;
        $sourceFile = $basePath . 'app' . DS . 'design' . DS . 'WeShop' . DS . 'default' . DS . 'frontend' . DS . 'variables' . DS . '_colors.css';
        $publishedFile = $basePath . 'pub' . DS . 'static' . DS . 'WeShop' . DS . 'motor' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'variables' . DS . '_colors.css';

        $this->assertFileExists($publishedFile);
        $this->assertSame(file_get_contents($sourceFile), file_get_contents($publishedFile));
    }

    public function testPublishesPreviewThemeOverrideAssetIntoPreviewNamespace(): void
    {
        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        $request->setServer('REQUEST_URI', '/theme/frontend/theme-preview/content');
        $request->setGet('frontend_theme_id', 990003);
        $request->setGet('editor_area', 'frontend');
        $request->setGet('shell', 'preview');
        $request->setGet('preview_mode', 'live');
        $request->setGet('status', 'draft');
        $request->setGet(PreviewTokenService::TOKEN_KEY, 'pv_preview_namespace');

        $theme = $this->buildTheme(990003, 'motor', 'WeShop/motor');
        $publishedPath = $this->publisher->publishForRequestPath(
            '/Weline/Theme/view/theme/frontend/assets/css/motor.css',
            $theme
        );

        $this->assertSame(
            '/pub/static/__preview/token_pv_preview_namespace/WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/motor.css',
            $publishedPath
        );

        $publishedFile = rtrim(BP, '\\/') . DS
            . 'pub' . DS
            . 'static' . DS
            . '__preview' . DS
            . 'token_pv_preview_namespace' . DS
            . 'WeShop' . DS
            . 'motor' . DS
            . 'Weline' . DS
            . 'Theme' . DS
            . 'view' . DS
            . 'theme' . DS
            . 'frontend' . DS
            . 'assets' . DS
            . 'css' . DS
            . 'motor.css';

        $this->assertFileExists($publishedFile);
    }

    private function buildTheme(int $id, string $name, string $path): WelineTheme
    {
        /** @var WelineTheme $theme */
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery();
        $theme->setData(WelineTheme::schema_fields_ID, $id);
        $theme->setData(WelineTheme::schema_fields_NAME, $name);
        $theme->setData(WelineTheme::schema_fields_PATH, $path);

        return $theme;
    }

    private function buildMockThemeChain(
        int $childId,
        string $childName,
        string $childPath,
        int $parentId,
        string $parentName,
        string $parentPath,
    ): WelineTheme {
        $parent = $this->createMock(WelineTheme::class);
        $parent->method('getId')->willReturn($parentId);
        $parent->method('getName')->willReturn($parentName);
        $parent->method('getOriginPath')->willReturn($parentPath);
        $parent->method('getPath')->willReturn($this->buildDesignPath($parentPath));
        $parent->method('getThemeChain')->willReturn([$parent]);

        $child = $this->createMock(WelineTheme::class);
        $child->method('getId')->willReturn($childId);
        $child->method('getName')->willReturn($childName);
        $child->method('getOriginPath')->willReturn($childPath);
        $child->method('getPath')->willReturn($this->buildDesignPath($childPath));
        $child->method('getThemeChain')->willReturn([$parent, $child]);

        return $child;
    }

    private function buildDesignPath(string $originPath): string
    {
        return rtrim(BP, '\\/')
            . DS . 'app'
            . DS . 'design'
            . DS . str_replace(['/', '\\'], DS, $originPath)
            . DS;
    }
}
