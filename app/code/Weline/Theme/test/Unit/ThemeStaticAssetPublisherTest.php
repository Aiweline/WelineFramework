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

    public function testSkipsAssetsThatAlreadyExistInModuleThemeDirectory(): void
    {
        $theme = $this->buildTheme(990002, 'motor', 'WeShop/motor');

        $this->assertNull(
            $this->publisher->publishForRequestPath(
                '/Weline/Theme/view/theme/frontend/assets/css/theme.css',
                $theme
            )
        );
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
}
