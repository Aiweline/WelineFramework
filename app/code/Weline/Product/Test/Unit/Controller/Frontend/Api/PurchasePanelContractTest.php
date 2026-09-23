<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend\Api;

use PHPUnit\Framework\TestCase;

final class PurchasePanelContractTest extends TestCase
{
    public function testPurchasePanelApiExistsAndUsesQuickAddProductInfo(): void
    {
        $root = dirname(__DIR__, 5);
        $controller = $root . '/Controller/Frontend/Api/PurchasePanel.php';
        $service = $root . '/Service/PurchasePanelService.php';
        $provider = $root . '/extends/module/Weline_Framework/Query/ProductQueryProvider.php';
        $info = $root . '/view/templates/frontend/widgets/product-info.phtml';
        $cardAdd = dirname($root) . '/Cart/view/templates/frontend/widgets/product-card-add-to-cart.phtml';
        $js = dirname($root) . '/Cart/view/statics/js/widgets/product-purchase-actions.js';
        $tiers = dirname($root) . '/B2B/view/templates/frontend/partials/qty-tiers.phtml';

        self::assertFileExists($controller);
        self::assertFileExists($service);
        self::assertFileExists($tiers);
        $controllerSrc = (string)file_get_contents($controller);
        $serviceSrc = (string)file_get_contents($service);
        $providerSrc = (string)file_get_contents($provider);
        $infoSrc = (string)file_get_contents($info);
        $cardSrc = (string)file_get_contents($cardAdd);
        $jsSrc = (string)file_get_contents($js);

        self::assertStringContainsString('PurchasePanelService', $controllerSrc);
        self::assertStringContainsString("'quick_add' => true", $serviceSrc);
        self::assertStringContainsString('product-info.phtml', $serviceSrc);
        self::assertStringContainsString('getPurchasePanel', $providerSrc);
        self::assertStringContainsString("'auth' => 'any'", $providerSrc);
        self::assertStringContainsString("'external' => true", $providerSrc);
        self::assertStringContainsString('product-native-detail--quick-add', $infoSrc);
        self::assertStringContainsString('qty-tiers.phtml', $infoSrc);
        self::assertStringContainsString('data-open-purchase-panel', $cardSrc);
        self::assertStringNotContainsString('data-purchase-panel-url', $cardSrc);
        self::assertStringContainsString('openPurchasePanel', $jsSrc);
        self::assertStringContainsString('shouldOpenPurchasePanel', $jsSrc);
        self::assertStringContainsString('waitForProductApi', $jsSrc);
        self::assertStringContainsString("resource('product')", $jsSrc);
        self::assertStringContainsString('getPurchasePanel', $jsSrc);
        self::assertStringNotContainsString('fetch(url.toString()', $jsSrc);
        self::assertStringContainsString('loadInjectedAttributeModules', $jsSrc);
        self::assertStringContainsString('helpPayShare', $jsSrc);
        self::assertStringContainsString('WelineAffiliateProductShare', $jsSrc);
        self::assertStringContainsString('applyIdentity', $jsSrc);
        self::assertStringContainsString('resolveB2bIdentity', $serviceSrc);
        self::assertStringContainsString('b2b-qty-tiers', (string)file_get_contents($tiers));
        // __() default args='' strips %{1}; JS stock template must pass ['%{1}'] sentinel.
        self::assertStringContainsString("__('仅剩 %{1} 件', ['%{1}'])", $infoSrc);
        self::assertStringNotContainsString("json_encode((string)__('仅剩 %{1} 件'), JSON_UNESCAPED_UNICODE)", $infoSrc);
    }
}
