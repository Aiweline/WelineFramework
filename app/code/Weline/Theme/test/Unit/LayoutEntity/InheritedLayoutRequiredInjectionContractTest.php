<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionContract;
use Weline\Theme\Service\LayoutEntity\StorefrontFloatLayerHost;

/**
 * UC（固化）：新主题继承某布局（本层无 design override）时，
 * 该布局内插槽上的应用部件 default_injections 仍必须生效。
 *
 * 机制：
 * 1) SolidifiedControllerTemplateResolver::resolvePublishedVersionAlongThemeChain
 *    — 子主题无自有 published / 无本层布局覆盖 → 沿 parent_id 用祖先已固化布局
 *    （含祖先 bake 进模板的 required 注入关系）。
 * 2) themeProvidesLayoutOverride — 子主题自己写了同 layout_type/option 则不得
 *    误用父固化产物盖掉子品牌布局。
 * 3) RequiredDefaultInjectionRuntimeSafetyNet + StorefrontFloatLayerHost —
 *    即使继承链上的固化物缺槽 / 子主题改写 chrome，运行期仍 XOR 必装
 *    （layout_type=* 的客服/进店音乐/Filters），除非 user_deleted@*。
 *
 * owning=Weline_Theme；not_to_do=禁止要求子主题重声明父布局上的 JSON 注入。
 */
final class InheritedLayoutRequiredInjectionContractTest extends TestCase
{
    public function testResolverInheritsAncestorSolidifyOnlyWhenChildLacksLayoutOverride(): void
    {
        $resolver = dirname(__DIR__, 3) . '/Service/LayoutEntity/SolidifiedControllerTemplateResolver.php';
        $src = (string)file_get_contents($resolver);
        self::assertStringContainsString('resolvePublishedVersionAlongThemeChain', $src);
        self::assertStringContainsString('themeProvidesLayoutOverride', $src);
        self::assertStringContainsString('getParentId', $src);
        self::assertStringContainsString(
            'Child design themes may bind package_defaults',
            $src,
            'Inheritance comment must stay: package_defaults child reuses ancestor solidify',
        );
        self::assertStringContainsString(
            'Inherit ancestor published solidification only for layouts the child does',
            $src,
        );
        // Order: own published → else if child override stop → else walk parents
        $ownPos = strpos($src, 'getPublished($themeId, $scope, $storeMode, $area)');
        $overridePos = strpos($src, 'themeProvidesLayoutOverride($themeId, $layoutType, $layoutOption, $area)');
        $parentWalkPos = strpos($src, 'getParentId()');
        self::assertNotFalse($ownPos);
        self::assertNotFalse($overridePos);
        self::assertNotFalse($parentWalkPos);
        self::assertLessThan($overridePos, $ownPos);
        self::assertLessThan($parentWalkPos, $overridePos);
    }

    public function testStarLayoutInjectionsApplyOnInheritedNonHomepageLayouts(): void
    {
        // Application widgets declare layout_type=* so products/category/… inherit the same
        // required float injections as homepage when the child only inherits those layouts.
        $declarations = [
            [
                'module' => 'Weline_StoreMusic',
                'type' => 'content',
                'code' => 'store-music',
                'default_injections' => [[
                    'layout_type' => '*',
                    'slot' => 'storefront-float-start',
                    'required' => true,
                ]],
            ],
            [
                'module' => 'Weline_CustomerService',
                'type' => 'content',
                'code' => 'customer-service-float',
                'default_injections' => [[
                    'layout_type' => '*',
                    'slot' => 'storefront-float-end',
                    'required' => true,
                ]],
            ],
            [
                'module' => 'Weline_Theme',
                'type' => 'content',
                'code' => 'filters',
                'default_injections' => [[
                    'layout_type' => 'products',
                    'slot' => 'list-filters',
                    'required' => true,
                ]],
            ],
        ];

        $productSlots = array_column(
            RequiredDefaultInjectionContract::requiredTargets($declarations, 'products'),
            'slot_id',
        );
        self::assertContains('storefront-float-start', $productSlots);
        self::assertContains('storefront-float-end', $productSlots);
        self::assertContains('list-filters', $productSlots);

        $categorySlots = array_column(
            RequiredDefaultInjectionContract::requiredTargets($declarations, 'category'),
            'slot_id',
        );
        self::assertContains('storefront-float-start', $categorySlots);
        self::assertContains('storefront-float-end', $categorySlots);
        self::assertNotContains('list-filters', $categorySlots);
    }

    public function testInheritedSolidifiedHtmlMissingFloatsStillGetsSafetyNetHosts(): void
    {
        // Simulate child rendering an ancestor-solidified page whose chrome lost float slots
        // (parent design rewrite / incomplete bake) — inheritance must not skip required inject.
        $inherited = <<<'HTML'
<!DOCTYPE html>
<html><head><title>child inherits parent products layout</title></head>
<body>
<main data-layout-type="products" class="theme-layout-products">
  <w:slot id="list-filters" class="filters-slot"></w:slot>
  <div class="product-list">items</div>
</main>
</body></html>
HTML;
        self::assertFalse(StorefrontFloatLayerHost::htmlHasFloatDestinations($inherited));
        $withHosts = StorefrontFloatLayerHost::ensureInHtml($inherited);
        self::assertTrue(StorefrontFloatLayerHost::htmlHasFloatDestinations($withHosts));
        self::assertStringContainsString('data-slot-id="storefront-float-start"', $withHosts);
        self::assertStringContainsString('data-slot-id="storefront-float-end"', $withHosts);
        self::assertStringContainsString('list-filters', $withHosts);
    }

    public function testChildDesignFooterOverrideStillRestoredAtMaterialize(): void
    {
        // Child may inherit most layouts but rewrite footer (like injectprobe) — bake must
        // restore float destinations so inherited *and* own layouts keep injection targets.
        $childFooter = "<?php\n?>\n<!-- child inherits chrome but rewrote footer without floats -->\n"
            . "<w:slot id=\"footer\" name=\"整体底部\"></w:slot>\n";
        $baked = StorefrontFloatLayerHost::ensureInSourceTemplate($childFooter);
        self::assertStringContainsString('<w:slot id="storefront-float-start"', $baked);
        self::assertStringContainsString('<w:slot id="storefront-float-end"', $baked);

        $materializer = dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityMaterializer.php';
        self::assertStringContainsString(
            'StorefrontFloatLayerHost::ensureInSourceTemplate',
            (string)file_get_contents($materializer),
        );
    }

    public function testInjectinheritProbeThemeInheritsWithoutLayoutOverrides(): void
    {
        $root = dirname(__DIR__, 6) . '/design/Weline/injectinherit';
        self::assertDirectoryExists($root);
        // Frontend area must exist (ThemeContextService::assertFrontendTheme) even with zero overrides.
        self::assertDirectoryExists($root . '/frontend');
        self::assertFileExists($root . '/frontend/.inherit-only');

        $register = (string)file_get_contents($root . '/register.php');
        self::assertStringContainsString("'parent'", $register);
        self::assertMatchesRegularExpression("/'parent'\\s*=>\\s*'injectprobe'/", $register);

        $layouts = $root . '/frontend/layouts';
        if (is_dir($layouts)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($layouts));
            foreach ($it as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.phtml')) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($layouts)));
                self::fail('injectinherit must not override layouts (found frontend/layouts' . $rel . ')');
            }
        }

        // Parent probe still stresses floatless footer; child inherits that stress via parent_id.
        $parentFooter = dirname(__DIR__, 6)
            . '/design/Weline/injectprobe/frontend/partials/footer/default.phtml';
        self::assertFileExists($parentFooter);
        $src = (string)file_get_contents($parentFooter);
        self::assertStringContainsString('injectprobe:floatless', $src);
        self::assertFalse(StorefrontFloatLayerHost::htmlHasFloatDestinations($src));
    }

    public function testAppWidgetsKeepStarLayoutTypeForInheritedPages(): void
    {
        $music = dirname(__DIR__, 4) . '/StoreMusic/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
        if (!is_file($music)) {
            $music = dirname(__DIR__, 5) . '/StoreMusic/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
        }
        $cs = dirname(__DIR__, 4) . '/CustomerService/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
        if (!is_file($cs)) {
            $cs = dirname(__DIR__, 5) . '/CustomerService/extends/module/Weline_Widget/Weline_CustomerService/widget.php';
        }
        self::assertFileExists($music);
        self::assertFileExists($cs);
        $musicInj = (require $music)['store-music']['default_injections'][0] ?? [];
        $csInj = (require $cs)['customer-service-float']['default_injections'][0] ?? [];
        self::assertSame('*', $musicInj['layout_type'] ?? null);
        self::assertSame('*', $csInj['layout_type'] ?? null);
        self::assertTrue((bool)($musicInj['required'] ?? false));
        self::assertTrue((bool)($csInj['required'] ?? false));
    }
}
