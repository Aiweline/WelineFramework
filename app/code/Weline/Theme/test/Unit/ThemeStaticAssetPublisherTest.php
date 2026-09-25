<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\Http\Request;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\ThemeStaticAssetPublisher;

class ThemeStaticAssetPublisherTest extends TestCore
{
    /**
     * 本用例调用真实发布器（ThemeStaticAssetPublisher → ThemeResourceGateway），
     * 而该链路把发布根硬编码为 `BP . '/pub/static'`，因此夹具主题会被写进**项目真实**的
     * Web 根目录。若用例结束不回收，`WeShop/*`、`Codex/demo-theme` 等夹具命名空间会长期
     * 残留（并可能被静态发布/扫描流程再次读到）。
     *
     * 下面只列出本用例确实会发布出的文件本身：逐个 unlink，再逐级回收变空的目录，
     * 绝不整棵删除目录，避免误伤同名命名空间下的其它内容。
     */
    private const FIXTURE_PUBLISHED_FILES = [
        'WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/motor.css',
        'WeShop/motor/Weline/Theme/view/theme/frontend/variables/_colors.css',
        'WeShop/default/Weline/Theme/view/theme/frontend/variables/_colors.css',
        '__preview/token_pv_preview_namespace/WeShop/motor/Weline/Theme/view/theme/frontend/assets/css/motor.css',
        'Codex/demo-theme/Weline/Theme/view/statics/ui/weline-foundation.css',
        'Codex/demo-theme/Weline/Theme/view/theme/backend/assets/css/theme.css',
        'Weline/Backend/js/weline-api.js',
        'Weline/Backend/js/weline-api-worker.js',
    ];

    private ThemeStaticAssetPublisher $publisher;

    public function tearDown(): void
    {
        foreach (self::FIXTURE_PUBLISHED_FILES as $relativePath) {
            $this->removePublishedFixtureFile($relativePath);
        }

        parent::tearDown();
    }

    /**
     * 删除本用例发布出的夹具文件，并逐级回收因此变空的目录。
     * 目标必须解析到 pub/static 之内，否则跳过。
     */
    private function removePublishedFixtureFile(string $relativePath): void
    {
        $staticRoot = realpath(rtrim(BP, '\\/') . DS . 'pub' . DS . 'static');
        if ($staticRoot === false) {
            return;
        }

        $resolved = realpath($staticRoot . DS . str_replace('/', DS, $relativePath));
        if ($resolved === false || !is_file($resolved) || !str_starts_with($resolved, $staticRoot . DS)) {
            return;
        }

        @unlink($resolved);

        $dir = dirname($resolved);
        while (str_starts_with($dir, $staticRoot . DS)) {
            if (!@rmdir($dir)) {
                break;
            }
            $dir = dirname($dir);
        }
    }

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
        $request->setServer('REQUEST_URI', '/theme/frontend/theme-preview/gateway?frontend_theme_id=990003&shell=preview');
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

    public function testPublishesModuleStaticsResourceFromProductionThemePath(): void
    {
        $theme = $this->buildTheme(990006, 'demo-theme', 'Codex/demo-theme');
        $publishedPath = $this->publisher->publishForRequestPath(
            '/static/Codex/demo-theme/Weline/Theme/view/statics/ui/weline-foundation.css',
            $theme
        );

        $this->assertSame(
            '/pub/static/Codex/demo-theme/Weline/Theme/view/statics/ui/weline-foundation.css',
            $publishedPath
        );

        $basePath = rtrim(BP, '\\/') . DS;
        $sourceFile = $basePath . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'statics' . DS . 'ui' . DS . 'weline-foundation.css';
        $publishedFile = $basePath . 'pub' . DS . 'static' . DS . 'Codex' . DS . 'demo-theme' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'statics' . DS . 'ui' . DS . 'weline-foundation.css';

        $this->assertFileExists($publishedFile);
        $this->assertSame(file_get_contents($sourceFile), file_get_contents($publishedFile));
    }

    public function testPublishesModuleDefaultThemeAssetFromProductionThemePath(): void
    {
        $theme = $this->buildTheme(990007, 'demo-theme', 'Codex/demo-theme');
        $publishedPath = $this->publisher->publishForRequestPath(
            '/static/Codex/demo-theme/Weline/Theme/view/theme/backend/assets/css/theme.css',
            $theme
        );

        $this->assertSame(
            '/pub/static/Codex/demo-theme/Weline/Theme/view/theme/backend/assets/css/theme.css',
            $publishedPath
        );

        $basePath = rtrim(BP, '\\/') . DS;
        $sourceFile = $basePath . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . 'backend' . DS . 'assets' . DS . 'css' . DS . 'theme.css';
        $publishedFile = $basePath . 'pub' . DS . 'static' . DS . 'Codex' . DS . 'demo-theme' . DS . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . 'backend' . DS . 'assets' . DS . 'css' . DS . 'theme.css';

        $this->assertFileExists($publishedFile);
        $this->assertSame(file_get_contents($sourceFile), file_get_contents($publishedFile));
    }

    public function testPublishesFlatModuleStaticsResource(): void
    {
        $publishedPath = $this->publisher->publishForRequestPath('/static/Weline/Backend/js/weline-api.js');

        $this->assertSame('/pub/static/Weline/Backend/js/weline-api.js', $publishedPath);

        $basePath = rtrim(BP, '\\/') . DS;
        $sourceFile = $basePath . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Backend' . DS . 'view' . DS . 'statics' . DS . 'js' . DS . 'weline-api.js';
        $publishedFile = $basePath . 'pub' . DS . 'static' . DS . 'Weline' . DS . 'Backend' . DS . 'js' . DS . 'weline-api.js';

        $this->assertFileExists($publishedFile);
        $this->assertSame(file_get_contents($sourceFile), file_get_contents($publishedFile));
    }

    public function testPublishesFlatModuleWorkerResource(): void
    {
        $publishedPath = $this->publisher->publishForRequestPath('/static/Weline/Backend/js/weline-api-worker.js');

        $this->assertSame('/pub/static/Weline/Backend/js/weline-api-worker.js', $publishedPath);

        $basePath = rtrim(BP, '\\/') . DS;
        $sourceFile = $basePath . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Backend' . DS . 'view' . DS . 'statics' . DS . 'js' . DS . 'weline-api-worker.js';
        $publishedFile = $basePath . 'pub' . DS . 'static' . DS . 'Weline' . DS . 'Backend' . DS . 'js' . DS . 'weline-api-worker.js';

        $this->assertFileExists($publishedFile);
        $this->assertSame(file_get_contents($sourceFile), file_get_contents($publishedFile));
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
