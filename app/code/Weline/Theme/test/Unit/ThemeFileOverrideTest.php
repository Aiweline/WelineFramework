<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Helper\AssetMerger;
use Weline\Theme\Helper\Interface\ThemePathResolverInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Observer\TemplateFetchFile;

class ThemeFileOverrideTest extends TestCore
{
    private WelineTheme $themeModel;
    private AssetMerger $assetMerger;
    private TemplateFetchFile $templateFetchFile;
    private ThemePathResolverInterface $themePathResolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->themeModel = ObjectManager::getInstance(WelineTheme::class);
        $this->assetMerger = ObjectManager::getInstance(AssetMerger::class);
        $this->templateFetchFile = ObjectManager::getInstance(TemplateFetchFile::class);
        $this->themePathResolver = ObjectManager::getInstance(ThemePathResolverInterface::class);
    }

    public function testTemplateFileOverrideMechanism(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $modulePath = APP_CODE_PATH . 'Weline' . DS . 'Theme' . DS . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'partials' . DS . 'header' . DS . 'default.phtml';
        if (!is_file($modulePath)) {
            $this->markTestSkipped('Theme partial fixture is missing.');
        }

        $resolvedPath = $this->themePathResolver->resolveThemeFile($modulePath, $activeTheme);
        $this->assertTrue(is_file($resolvedPath));

        $expectedThemePath = $activeTheme->getPath() . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'partials' . DS . 'header' . DS . 'default.phtml';
        if (is_file($expectedThemePath)) {
            $this->assertEquals($expectedThemePath, $resolvedPath);
        }
    }

    public function testJsModuleFileOverrideMechanism(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        $this->assertIsArray($jsAssets);
        $this->assertNoDuplicateBasenames($jsAssets);

        $activeThemeSearchJs = $activeTheme->getPath() . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . 'search.js';
        if (!is_file($activeThemeSearchJs)) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertContains($activeThemeSearchJs, $jsAssets);

        $parentTheme = $this->loadParentTheme($activeTheme);
        if ($parentTheme === null) {
            $this->addToAssertionCount(1);
            return;
        }

        $parentSearchJs = $parentTheme->getPath() . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . 'search.js';
        if (is_file($parentSearchJs)) {
            $this->assertNotContains($parentSearchJs, $jsAssets);
        } else {
            $this->addToAssertionCount(1);
        }
    }

    public function testCssFileOverrideMechanism(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $cssAssets = $this->assetMerger->mergeAssets('css', 'frontend', $activeTheme);
        $this->assertIsArray($cssAssets);
        $this->assertNoDuplicateBasenames($cssAssets);
    }

    public function testThemeChainFileLookupOrder(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $themeChain = $activeTheme->getThemeChain();
        $this->assertIsArray($themeChain);
        $this->assertGreaterThanOrEqual(1, count($themeChain));

        $lastTheme = end($themeChain);
        $this->assertInstanceOf(WelineTheme::class, $lastTheme);
        $this->assertEquals($activeTheme->getId(), $lastTheme->getId());
    }

    public function testSameNameFileOverrideRule(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $parentTheme = $this->loadParentTheme($activeTheme);
        if ($parentTheme === null) {
            $this->markTestSkipped('Active theme has no parent theme.');
        }

        $testFileName = 'search.js';
        $activeThemeFile = $activeTheme->getPath() . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . $testFileName;
        $parentThemeFile = $parentTheme->getPath() . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . $testFileName;

        if (!is_file($activeThemeFile) && !is_file($parentThemeFile)) {
            $this->addToAssertionCount(1);
            return;
        }

        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        $matches = array_values(array_filter(
            $jsAssets,
            static fn(string $asset): bool => basename($asset) === $testFileName
        ));

        $this->assertLessThanOrEqual(1, count($matches));

        if (is_file($activeThemeFile)) {
            $this->assertContains($activeThemeFile, $matches);
            return;
        }

        $this->assertContains($parentThemeFile, $matches);
    }

    public function testAssetMergerDeduplication(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        $this->assertNoDuplicateBasenames($jsAssets);
    }

    private function loadParentTheme(WelineTheme $activeTheme): ?WelineTheme
    {
        $parentId = $activeTheme->getParentId();
        if (!$parentId) {
            return null;
        }

        /** @var WelineTheme $parentTheme */
        $parentTheme = ObjectManager::make(WelineTheme::class);
        $parentTheme->load($parentId);

        return $parentTheme->getId() ? $parentTheme : null;
    }

    private function assertNoDuplicateBasenames(array $assets): void
    {
        $seen = [];

        foreach ($assets as $asset) {
            $fileName = basename($asset);
            $this->assertArrayNotHasKey($fileName, $seen, "Duplicate asset basename detected: {$fileName}");
            $seen[$fileName] = $asset;
        }

        $this->assertCount(count($seen), $assets);
    }
}
