<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Helper\AssetMerger;
use Weline\Theme\Model\WelineTheme;

class AssetMergerOverrideTest extends TestCore
{
    private AssetMerger $assetMerger;
    private WelineTheme $themeModel;

    public function setUp(): void
    {
        parent::setUp();
        $this->themeModel = ObjectManager::getInstance(WelineTheme::class);
        $this->assetMerger = ObjectManager::getInstance(AssetMerger::class);
    }

    public function testSameNameJsFileOverride(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        $this->assertIsArray($jsAssets);
        $this->assertNoDuplicateBasenames($jsAssets);
    }

    public function testSameNameCssFileOverride(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $cssAssets = $this->assetMerger->mergeAssets('css', 'frontend', $activeTheme);
        $this->assertIsArray($cssAssets);
        $this->assertNoDuplicateBasenames($cssAssets);
    }

    public function testAssetCollectionOrder(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        $activeThemeMainJs = $activeTheme->getPath() . 'view' . DS . 'theme' . DS . 'frontend' . DS . 'assets' . DS . 'js' . DS . 'main.js';

        if (is_file($activeThemeMainJs)) {
            $this->assertContains($activeThemeMainJs, $jsAssets);
            return;
        }

        $this->addToAssertionCount(1);
    }

    public function testThemeChainAssetCollection(): void
    {
        $activeTheme = $this->themeModel->getActiveTheme();
        if (!$activeTheme->getId()) {
            $this->markTestSkipped('No active theme configured.');
        }

        $themeChain = $activeTheme->getThemeChain();
        $this->assertIsArray($themeChain);
        $this->assertGreaterThanOrEqual(1, count($themeChain));

        $jsAssets = $this->assetMerger->mergeAssets('js', 'frontend', $activeTheme);
        $themePaths = array_map(static fn(WelineTheme $theme): string => $theme->getPath(), $themeChain);

        foreach ($jsAssets as $asset) {
            $fromTheme = false;
            foreach ($themePaths as $themePath) {
                if (str_starts_with($asset, $themePath)) {
                    $fromTheme = true;
                    break;
                }
            }

            $fromBase = str_starts_with($asset, APP_CODE_PATH . 'Weline' . DS . 'Theme');
            $this->assertTrue($fromTheme || $fromBase, "Unexpected asset source: {$asset}");
        }
    }

    public function testDeduplicationLogic(): void
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

        foreach ($assets as $assetPath) {
            $fileName = basename($assetPath);
            $this->assertArrayNotHasKey($fileName, $seen, "Duplicate asset basename detected: {$fileName}");
            $seen[$fileName] = $assetPath;
        }

        $this->assertCount(count($seen), $assets);
    }
}
