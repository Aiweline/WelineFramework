<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Weline\Meta\Service\ParamDefinitionNormalizer;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllStoreMusicWidgetContractsPass().
 */
final class StoreMusicWidgetContractsTest extends TestCase
{
    public function testAllStoreMusicWidgetContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'StoreMusicDefaultInjection::testWidgetDeclaresRequiredHomepageFloatStartInjection' => function () {
            $this->assert_StoreMusicDefaultInjection_testWidgetDeclaresRequiredHomepageFloatStartInjection();
        },
            'StoreMusicDefaultInjection::testWidgetPrefetchesWidgetI18nLabelsBeforePerStringLabel' => function () {
            $this->assert_StoreMusicDefaultInjection_testWidgetPrefetchesWidgetI18nLabelsBeforePerStringLabel();
        },
            'StoreMusicDefaultInjection::testWidgetDeclaresComponentConfigParams' => function () {
            $this->assert_StoreMusicDefaultInjection_testWidgetDeclaresComponentConfigParams();
        },
            'StoreMusicDefaultInjection::testFloatSlotHookRemoved' => function () {
            $this->assert_StoreMusicDefaultInjection_testFloatSlotHookRemoved();
        },
            'StoreMusicDefaultInjection::testLayoutPresenceHelperExists' => function () {
            $this->assert_StoreMusicDefaultInjection_testLayoutPresenceHelperExists();
        },
            'StoreMusicDefaultInjection::testInactiveAndDuplicateRendersEmitPresenceStub' => function () {
            $this->assert_StoreMusicDefaultInjection_testInactiveAndDuplicateRendersEmitPresenceStub();
        },
            'StoreMusicDefaultInjection::testRenderGateClaimsOnce' => function () {
            $this->assert_StoreMusicDefaultInjection_testRenderGateClaimsOnce();
        },
            'StoreMusicDefaultInjection::testRenderGateResetAllowsSecondClaim' => function () {
            $this->assert_StoreMusicDefaultInjection_testRenderGateResetAllowsSecondClaim();
        },
        ];
    }

private function assert_StoreMusicDefaultInjection_testWidgetDeclaresRequiredHomepageFloatStartInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
    $tpl = 'Weline_StoreMusic::templates/frontend/widgets/store-music.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/view/templates/frontend/widgets/store-music.phtml');
    self::assertStringContainsString('@widget.code {store-music}', $src);
    self::assertStringContainsString('layout-storefront-float-start', $src);
    self::assertStringContainsString('"layout_type":"*"', $src);
    self::assertStringContainsString('"slot":"storefront-float-start"', $src);
    self::assertStringContainsString('"area":"footer"', $src);
    self::assertStringContainsString('"required":true', $src);
    self::assertStringContainsString('"enabled":true', $src);
    self::assertStringContainsString('"tracks":[]', $src);
    self::assertStringContainsString('"try_autoplay":false', $src);
    self::assertStringContainsString('"waveform_default":true', $src);
}

private function assert_StoreMusicDefaultInjection_testWidgetPrefetchesWidgetI18nLabelsBeforePerStringLabel(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/view/templates/frontend/widgets/store-music.phtml');
    $prefetch = strpos($src, 'WidgetI18n::prefetchLabels');
    $labelFn = strpos($src, '$t = static fn');
    self::assertNotFalse($prefetch);
    self::assertNotFalse($labelFn);
    self::assertLessThan($labelFn, $prefetch);
    self::assertStringContainsString("'进店音乐'", $src);
}

private function assert_StoreMusicDefaultInjection_testWidgetDeclaresComponentConfigParams(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/view/templates/frontend/widgets/store-music.phtml');
    $params = (new ParamDefinitionNormalizer())->extractParamAnnotations($src);
    foreach (['enabled', 'tracks', 'delay_seconds', 'try_autoplay', 'loop', 'default_volume', 'avatar_spin', 'waveform_default'] as $key) {
        self::assertArrayHasKey($key, $params, "missing param {$key}");
    }
    self::assertSame(true, $params['enabled']['default'] ?? null);
    self::assertSame([], $params['tracks']['default'] ?? null);
    self::assertSame(false, $params['try_autoplay']['default'] ?? null);
    self::assertSame(true, $params['waveform_default']['default'] ?? null);
    self::assertSame('array', $params['tracks']['type'] ?? null);
    self::assertSame('media_image', $params['tracks']['item_schema']['url']['type'] ?? null);
    self::assertSame('audio', $params['tracks']['item_schema']['url']['media_options']['kind'] ?? null);
    self::assertArrayHasKey('title', $params['tracks']['item_schema'] ?? []);
    self::assertArrayHasKey('intro', $params['tracks']['item_schema'] ?? []);
    self::assertTrue(($params['tracks']['item_schema']['intro']['i18n'] ?? false) === true);
    self::assertSame('选择曲目添加', $params['tracks']['add_with_media_label'] ?? null);
}

private function assert_StoreMusicDefaultInjection_testFloatSlotHookRemoved(): void
{
    $hook = '/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/view/hooks/Weline_Theme/frontend/layouts/base/float-slot-start.phtml';
    self::assertFileDoesNotExist($hook);
}

private function assert_StoreMusicDefaultInjection_testLayoutPresenceHelperExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/Service/StoreMusicLayoutPresence.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('function layoutPlacesWidget', $src);
    self::assertStringContainsString('function layoutContainsStoreMusic', $src);
    self::assertStringContainsString('ThemeRuntimeLayoutResolver', $src);
    self::assertStringContainsString('ThemePageTypeResolver', $src);
    self::assertStringContainsString('ThemeContextService', $src);
    self::assertStringContainsString('resolveRegisteredDefaultTheme', $src);
    self::assertStringContainsString('resolvePageTypeFromUri', $src);
    self::assertStringContainsString('identityCandidates', $src);
    self::assertStringContainsString('default.__website__.default', $src);
}

private function assert_StoreMusicDefaultInjection_testInactiveAndDuplicateRendersEmitPresenceStub(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/view/templates/frontend/widgets/store-music.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-widget-code="store-music"', $src);
    self::assertStringContainsString('data-store-music-skipped', $src);
    self::assertStringContainsString("\$presenceStub('inactive')", $src);
    self::assertStringContainsString("\$presenceStub('already-rendered')", $src);
    self::assertStringNotContainsString("\$presenceStub('hook-owned')", $src);
    self::assertStringNotContainsString('__weline_store_music_from_hook', $src);
    self::assertStringContainsString('storefront-float-start', $src);
}

private function assert_StoreMusicDefaultInjection_testRenderGateClaimsOnce(): void
{
    $gate = '/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/Service/StoreMusicRenderGate.php';
    self::assertFileExists($gate);
    $src = (string) file_get_contents($gate);
    self::assertStringContainsString('function claim', $src);
    self::assertStringContainsString('function reset', $src);
    self::assertStringContainsString('weline.store_music.render_claimed', $src);
}

private function assert_StoreMusicDefaultInjection_testRenderGateResetAllowsSecondClaim(): void
{
    \Weline\StoreMusic\Service\StoreMusicRenderGate::reset();
    self::assertTrue(\Weline\StoreMusic\Service\StoreMusicRenderGate::claim());
    self::assertFalse(\Weline\StoreMusic\Service\StoreMusicRenderGate::claim());
    \Weline\StoreMusic\Service\StoreMusicRenderGate::reset();
    self::assertTrue(\Weline\StoreMusic\Service\StoreMusicRenderGate::claim());
    \Weline\StoreMusic\Service\StoreMusicRenderGate::reset();
}
}
