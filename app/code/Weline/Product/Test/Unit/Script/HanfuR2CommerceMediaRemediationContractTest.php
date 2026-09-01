<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Script;

use PHPUnit\Framework\TestCase;

final class HanfuR2CommerceMediaRemediationContractTest extends TestCase
{
    public function testCommerceRemediationRegistersEveryAssetWithBilingualProvenance(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/scripts/remediate-hanfu-commerce-media-r2.php',
        );

        foreach ([
            'FileAssetLibraryInterface',
            'ProductCategoryAttributeService',
            'MediaRepository',
            "'zh_Hans_CN'",
            "'en_US'",
            "'display_name'",
            "'default_alt'",
            "'description'",
            "'default_caption'",
            "'translation_state'",
            "'translation_origin'",
            "'source'",
            "'license'",
            "'purpose'",
            "'relations'",
            "'review'",
            'count($assets) !== 87',
            'count($categories) !== 28',
            'syncProductScope',
            'asset://',
            '--dry-run',
            '--apply',
            '--verify',
            '--cleanup',
        ] as $required) {
            self::assertStringContainsString($required, $script);
        }
    }

    public function testCleanupIsExactAndGatedByVerification(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/scripts/remediate-hanfu-commerce-media-r2.php',
        );

        $verifyPosition = strpos($script, '$verification = hanfuR2VerifyState(');
        $cleanupPosition = strpos($script, "if (\$mode === '--cleanup')");
        self::assertIsInt($verifyPosition);
        self::assertIsInt($cleanupPosition);
        self::assertLessThan($cleanupPosition, $verifyPosition);
        self::assertStringContainsString('count($cleanupKeys) !== 64', $script);
        self::assertStringContainsString('$library->deleteObject(', $script);
        self::assertStringNotContainsString('deleteDirectory(', $script);
        self::assertStringNotContainsString('rm -rf', $script);
    }

    public function testDefaultHomepageAndCategoryWidgetsUseHanfuVisuals(): void
    {
        $themeRoot = dirname(__DIR__, 4) . '/Theme/view/theme/frontend/widgets';
        $hero = (string)file_get_contents($themeRoot . '/banner/hero-slider/default.phtml');
        $category = (string)file_get_contents($themeRoot . '/category/category-grid/default.phtml');

        self::assertStringContainsString('/pub/media/catalog/hanfu/r2/homepage/', $hero);
        self::assertStringContainsString("'slug' => 'taoyuan-qingmeng'", $hero);
        self::assertStringContainsString("\$slug . '-mobile.webp'", $hero);
        self::assertStringContainsString('<picture>', $hero);
        self::assertStringContainsString('object-position: 75% center', $hero);
        self::assertStringNotContainsString('2026春季新品上市', $hero);
        self::assertStringNotContainsString('全场满300减50', $hero);
        self::assertStringNotContainsString('品质生活', $hero);

        self::assertStringContainsString('/pub/media/catalog/hanfu/r2/categories/icons/', $category);
        self::assertStringContainsString("'url' => '/category/' . \$code", $category);
        foreach (['women', 'men', 'kids', 'accessories', 'sets'] as $code) {
            self::assertStringContainsString("'code' => '" . $code . "'", $category);
        }
        self::assertStringNotContainsString('ThemeDemoCatalog', $category);
        self::assertStringNotContainsString('电子产品', $category);
        self::assertStringContainsString('$showCount && (int)($category[\'count\'] ?? 0) > 0', $category);
    }

    public function testCategorySeedPointsToReviewedR2WebpPaths(): void
    {
        $seed = (string)file_get_contents(dirname(__DIR__, 3) . '/data/seed-hanfu-categories.php');

        self::assertStringContainsString(
            "const MEDIA_BASE = '/pub/media/catalog/hanfu/r2/categories';",
            $seed,
        );
        self::assertStringContainsString("'/icons/' . \$code . '.webp'", $seed);
        self::assertStringContainsString("'/banners/' . \$code . '.webp'", $seed);
        self::assertStringNotContainsString("'/icons/' . \$code . '.svg'", $seed);
    }
}
