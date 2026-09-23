<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Contract: config-only chrome writes merge payload; carrier sync method exists.
 */
final class ThemeLayoutEntityChromePayloadMergeTest extends TestCase
{
    public function testConfigOnlyPathMergesChromePayloadInsteadOfReplace(): void
    {
        $class = new \ReflectionClass(\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator::class);
        $existing = ['a' => ['node_uid' => 'a', 'widget_module' => 'Weline_Theme', 'widget_code' => 'header', 'config' => ['title' => 'old']],
            'injected' => ['node_uid' => 'injected', 'source' => 'default_injection', 'widget_code' => 'navigation']];
        $result = $class->getMethod('mergeChromePayloadNodes')->invoke($class->newInstanceWithoutConstructor(),
            $existing, ['a' => ['config' => ['title' => 'new']]]);
        self::assertSame($existing['injected'], $result['injected']);
        self::assertSame('Weline_Theme', $result['a']['widget_module']);
        self::assertSame('header', $result['a']['widget_code']);
        self::assertSame(['title' => 'new'], $result['a']['config']);
    }

    public function testUpgradeReconcileChromePayloadMigrationPresent(): void
    {
        $path = \dirname(__DIR__, 3) . '/Setup/Upgrade.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('migratePublishActiveThemeCategoryFilters', $src);
        self::assertStringContainsString('bakeChromeFromNodes', $src);
        $start = strpos($src, 'function migratePublishActiveThemeCategoryFilters');
        $end = strpos($src, 'private function migrateProductListPageTypeToProducts');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertGreaterThan($start, $end);
        $body = substr($src, (int)$start, (int)$end - (int)$start);
        self::assertStringContainsString('PAGE_TYPE_PRODUCT_LIST', $body);
        self::assertStringContainsString("'is_active_frontend'", $body);
        self::assertStringContainsString('published_release_id', $body);
    }
}
