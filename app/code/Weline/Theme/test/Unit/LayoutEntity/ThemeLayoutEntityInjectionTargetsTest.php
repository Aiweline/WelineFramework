<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityInjectionTargets;

final class ThemeLayoutEntityInjectionTargetsTest extends TestCase
{
    public function testUnresolvedVersionsAppearInReviewableReport(): void
    {
        $target = ['theme_id' => 7, 'scope' => 'shop.default.default', 'layout_type' => 'homepage', 'release_id' => 10, 'version_id' => 0, 'version_resolved' => false, 'nodes' => ['private_config' => []]];
        $report = (new ThemeLayoutEntityInjectionTargets())->reportForTargets([$target]);
        self::assertSame(1, $report['target_count']);
        self::assertSame('layout_version_unresolved', $report['unresolved'][0]['reason']);
        self::assertArrayNotHasKey('nodes', $report['unresolved'][0]);
    }

    public function testHistoricalReleaseNeverBorrowsTheCurrentVersion(): void
    {
        $context = new class {
            public int $themeId = 7;
            public string $layoutType = 'homepage';
            public string $layoutOption = 'default';
            public string $targetType = 'global';
            public int $targetId = 0;
            public object $scope;
            public function __construct() { $this->scope = (object)['storageScope' => 'shop.default.default']; }
            public function identityHash(): string { return str_repeat('a', 64); }
        };
        $versions = [['version_id' => 50, 'theme_id' => 7, 'page_type' => 'homepage', 'scope' => 'shop.default.default', 'is_published' => true, 'snapshot_data' => []]];
        $method = new ReflectionMethod(ThemeLayoutEntityInjectionTargets::class, 'target');
        $service = new ThemeLayoutEntityInjectionTargets();
        $historical = $method->invoke($service, $context, ['nodes' => []], true, 10, 0, $versions, false);
        self::assertFalse($historical['version_resolved']);
        self::assertSame(0, $historical['version_id']);
        $current = $method->invoke($service, $context, ['nodes' => []], true, 11, 0, $versions, true);
        self::assertTrue($current['version_resolved']);
        self::assertSame(50, $current['version_id']);
        self::assertSame([], $current['nodes'], 'An empty layout remains a valid injection target.');
    }

    public function testOldAndNewTargetsAreSelectedButUnrelatedLayoutsAreNot(): void
    {
        $changes = [['widget_identity' => ['area' => 'frontend'], 'before' => [['layout_type' => 'homepage', 'slot' => 'hero']], 'after' => [['layout_type' => 'product', 'slot' => 'purchase']]]];
        self::assertTrue(ThemeLayoutEntityInjectionTargets::affects($changes, 'homepage'));
        self::assertTrue(ThemeLayoutEntityInjectionTargets::affects($changes, 'product'));
        self::assertFalse(ThemeLayoutEntityInjectionTargets::affects($changes, 'cart'));
    }

    public function testSharedChromeWildcardAndLayoutOptions(): void
    {
        $changes = [['before' => [], 'after' => [['layout_type' => 'homepage', 'slot' => 'footer-links', 'layout_option' => 'default']]]];
        self::assertTrue(ThemeLayoutEntityInjectionTargets::affects($changes, 'product'));
        $changes[0]['after'] = [['layout_type' => '*', 'slot' => 'content', 'layout_option' => 'wide']];
        self::assertTrue(ThemeLayoutEntityInjectionTargets::affects($changes, 'custom/page', 'wide'));
        self::assertFalse(ThemeLayoutEntityInjectionTargets::affects($changes, 'custom/page', 'default'));
    }
}
