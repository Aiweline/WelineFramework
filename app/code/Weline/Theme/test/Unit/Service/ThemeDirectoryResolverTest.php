<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeDirectoryResolver;

class ThemeDirectoryResolverTest extends TestCore
{
    private ThemeDirectoryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = ObjectManager::getInstance(ThemeDirectoryResolver::class);
        $this->resolver->clearCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->resolver->clearCache();
    }

    /**
     * 加载主题
     */
    private function loadTheme(int $themeId): WelineTheme
    {
        /** @var WelineTheme $theme */
        $theme = clone ObjectManager::getInstance(WelineTheme::class);
        $theme->clearData()->clearQuery()->load($themeId);

        return $theme;
    }

    /**
     * 测试 extractAreaRelativePath 提取模块覆写路径
     */
    public function testExtractAreaRelativePathModuleOverride(): void
    {
        $path = 'app/code/Weline/Customer/view/templates/frontend/account/login.phtml';
        $result = $this->resolver->extractAreaRelativePath($path);

        $this->assertNotNull($result);
        $this->assertSame('frontend', $result['area']);
        $this->assertSame('account' . DS . 'login.phtml', $result['relative_path']);
    }

    /**
     * 测试 extractAreaRelativePath 提取标准主题路径
     */
    public function testExtractAreaRelativePathStandardTheme(): void
    {
        $path = 'app/code/Weline/Theme/view/theme/frontend/layouts/base.phtml';
        $result = $this->resolver->extractAreaRelativePath($path);

        $this->assertNotNull($result);
        $this->assertSame('frontend', $result['area']);
        $this->assertSame('layouts' . DS . 'base.phtml', $result['relative_path']);
    }

    /**
     * 测试 extractAreaRelativePath 提取 design 主题路径
     */
    public function testExtractAreaRelativePathDesignTheme(): void
    {
        $path = 'app/design/WeShop/motor/frontend/layouts/account_auth/default.phtml';
        $result = $this->resolver->extractAreaRelativePath($path);

        $this->assertNotNull($result);
        $this->assertSame('frontend', $result['area']);
        $this->assertSame('layouts' . DS . 'account_auth' . DS . 'default.phtml', $result['relative_path']);
    }

    /**
     * 测试 extractAreaRelativePath 提取 design 模块覆写路径
     */
    public function testExtractAreaRelativePathDesignModuleOverride(): void
    {
        $path = 'app/design/WeShop/motor/Weline/Customer/view/templates/frontend/account/login.phtml';
        $result = $this->resolver->extractAreaRelativePath($path);

        $this->assertNotNull($result);
        $this->assertSame('frontend', $result['area']);
        $this->assertSame('account' . DS . 'login.phtml', $result['relative_path']);
    }

    /**
     * 测试 extractAreaRelativePath 提取 partials 路径
     */
    public function testExtractAreaRelativePathPartials(): void
    {
        $path = 'app/code/Weline/Theme/view/theme/frontend/partials/head/default.phtml';
        $result = $this->resolver->extractAreaRelativePath($path);

        $this->assertNotNull($result);
        $this->assertSame('frontend', $result['area']);
        $this->assertSame('partials' . DS . 'head' . DS . 'default.phtml', $result['relative_path']);
    }

    /**
     * 测试 extractAreaRelativePath 处理无效路径
     */
    public function testExtractAreaRelativePathInvalidPath(): void
    {
        $path = 'invalid/path/without/area';
        $result = $this->resolver->extractAreaRelativePath($path);

        $this->assertNull($result);
    }

    /**
     * 测试 getAreaDirectories 返回 frontend 目录列表
     */
    public function testGetAreaDirectoriesFrontend(): void
    {
        $theme = $this->loadTheme(10); // Weline_Theme 默认主题

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 10 not found.');
        }

        $directories = $this->resolver->getAreaDirectories('frontend', $theme);

        $this->assertIsArray($directories);
        $this->assertNotEmpty($directories);

        // 验证返回的目录结构
        foreach ($directories as $dir) {
            $this->assertArrayHasKey('path', $dir);
            $this->assertArrayHasKey('area', $dir);
            $this->assertArrayHasKey('layer_key', $dir);
            $this->assertArrayHasKey('layer_type', $dir);
            $this->assertSame('frontend', $dir['area']);
        }
    }

    /**
     * 测试 getAreaDirectories 返回 backend 目录列表
     */
    public function testGetAreaDirectoriesBackend(): void
    {
        $theme = $this->loadTheme(10);

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 10 not found.');
        }

        $directories = $this->resolver->getAreaDirectories('backend', $theme);

        $this->assertIsArray($directories);
        // Backend 目录可能为空，但不应该是 null
    }

    /**
     * 测试 getAreaDirectories 支持主题继承链
     */
    public function testGetAreaDirectoriesWithThemeChain(): void
    {
        $theme = $this->loadTheme(11); // motor 主题

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        $directories = $this->resolver->getAreaDirectories('frontend', $theme);

        $this->assertIsArray($directories);

        // 验证主题继承链：motor 应该能找到 Weline_Theme 的目录
        $themePaths = array_column($directories, 'path');
        $hasMotorDir = false;
        $hasWelineThemeDir = false;

        foreach ($themePaths as $path) {
            if (str_contains($path, 'WeShop' . DS . 'motor')) {
                $hasMotorDir = true;
            }
            if (str_contains($path, 'Weline' . DS . 'Theme')) {
                $hasWelineThemeDir = true;
            }
        }

        $this->assertTrue($hasMotorDir, 'Should contain motor theme directories');
        $this->assertTrue($hasWelineThemeDir, 'Should contain Weline_Theme fallback directories');
    }

    /**
     * 测试 resolveThemeTemplatePath 解析模块模板到主题覆写
     */
    public function testResolveThemeTemplatePath(): void
    {
        $theme = $this->loadTheme(11); // motor 主题

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        // 测试 Weline_Customer 登录模板
        $modulePath = 'Weline_Customer::templates/frontend/account/login.phtml';
        $resolvedPath = $this->resolver->resolveThemeTemplatePath($modulePath, $theme);

        // 应该解析到 motor 主题的覆写路径
        $this->assertStringContainsString('WeShop' . DS . 'motor', $resolvedPath);
        $this->assertStringContainsString('Weline' . DS . 'Customer', $resolvedPath);
        $this->assertStringContainsString('login.phtml', $resolvedPath);
    }

    /**
     * 测试 resolveThemeTemplatePath 处理默认主题
     */
    public function testResolveThemeTemplatePathDefaultTheme(): void
    {
        $theme = $this->loadTheme(10); // Weline_Theme 默认主题

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 10 not found.');
        }

        $modulePath = 'Weline_Customer::templates/frontend/account/login.phtml';
        $resolvedPath = $this->resolver->resolveThemeTemplatePath($modulePath, $theme);

        // 默认主题应该回退到 Weline_Theme 模块路径
        $this->assertStringContainsString('Weline_Theme', $resolvedPath);
    }

    /**
     * 测试 resolveThemePartialsPath 解析 partials 路径（支持主题继承链）
     */
    public function testResolveThemePartialsPath(): void
    {
        $theme = $this->loadTheme(11); // motor 主题

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        // 测试 head partial
        $resolvedPath = $this->resolver->resolveThemePartialsPath('head', 'default', 'frontend', $theme);

        // 应该解析到 motor 主题的 partials
        $this->assertNotNull($resolvedPath);
        $this->assertStringContainsString('partials', $resolvedPath);
        $this->assertStringContainsString('head', $resolvedPath);
    }

    /**
     * 测试 resolveThemePartialsPath 处理回退到 default.phtml
     */
    public function testResolveThemePartialsPathFallbackToDefault(): void
    {
        $theme = $this->loadTheme(11); // motor 主题

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        // 测试不存在的选项，回退到 default
        $resolvedPath = $this->resolver->resolveThemePartialsPath('head', 'nonexistent', 'frontend', $theme);

        // 应该回退到 default.phtml
        $this->assertNotNull($resolvedPath);
        $this->assertStringContainsString('default.phtml', $resolvedPath);
    }

    /**
     * 测试 resolveThemePartialsPath 支持任意深度的 partials 路径
     */
    public function testResolveThemePartialsPathArbitraryDepth(): void
    {
        $theme = $this->loadTheme(11); // motor 主题

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        // 测试更深层次的 partials 路径
        $resolvedPath = $this->resolver->resolveThemePartialsPath('header', 'default', 'frontend', $theme);

        $this->assertNotNull($resolvedPath);
        $this->assertStringContainsString('partials', $resolvedPath);
        $this->assertStringContainsString('header', $resolvedPath);
    }

    /**
     * 测试 getAreaDirectories 缓存机制
     */
    public function testGetAreaDirectoriesCaching(): void
    {
        $theme = $this->loadTheme(10);

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 10 not found.');
        }

        // 第一次调用
        $directories1 = $this->resolver->getAreaDirectories('frontend', $theme);

        // 第二次调用应该使用缓存
        $directories2 = $this->resolver->getAreaDirectories('frontend', $theme);

        $this->assertSame($directories1, $directories2);
    }

    /**
     * 测试 clearCache 清除缓存
     */
    public function testClearCache(): void
    {
        $theme = $this->loadTheme(10);

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 10 not found.');
        }

        // 填充缓存
        $this->resolver->getAreaDirectories('frontend', $theme);

        // 清除缓存
        $this->resolver->clearCache();

        // 重新获取应该重新计算（通过验证目录存在即可）
        $directories = $this->resolver->getAreaDirectories('frontend', $theme);
        $this->assertIsArray($directories);
    }

    /**
     * 测试主题继承链顺序
     */
    public function testThemeChainOrder(): void
    {
        $theme = $this->loadTheme(11); // motor 主题

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        $directories = $this->resolver->getAreaDirectories('frontend', $theme);

        // motor 主题的目录应该在 Weline_Theme 目录之前
        $motorIndex = null;
        $welineThemeIndex = null;

        foreach ($directories as $index => $dir) {
            if (str_contains($dir['path'], 'WeShop' . DS . 'motor')) {
                $motorIndex = $index;
            }
            if (str_contains($dir['path'], 'Weline' . DS . 'Theme')) {
                $welineThemeIndex = $index;
            }
        }

        if ($motorIndex !== null && $welineThemeIndex !== null) {
            $this->assertLessThan(
                $welineThemeIndex,
                $motorIndex,
                'Motor theme directories should come before Weline_Theme directories'
            );
        }
    }

    /**
     * 测试 resolveThemeTemplatePath 处理 layouts 路径
     */
    public function testResolveThemeTemplatePathLayouts(): void
    {
        $theme = $this->loadTheme(11); // motor 主题

        if (!$theme->getId()) {
            $this->markTestSkipped('Theme 11 not found.');
        }

        $modulePath = 'Weline_Theme::theme/frontend/layouts/account_auth/default.phtml';
        $resolvedPath = $this->resolver->resolveThemeTemplatePath($modulePath, $theme);

        // 应该解析到 motor 主题的 layouts
        $this->assertStringContainsString('WeShop' . DS . 'motor', $resolvedPath);
        $this->assertStringContainsString('layouts', $resolvedPath);
        $this->assertStringContainsString('account_auth', $resolvedPath);
    }
}
