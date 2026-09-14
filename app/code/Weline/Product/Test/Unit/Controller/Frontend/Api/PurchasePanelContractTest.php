<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Frontend\Api;

use PHPUnit\Framework\TestCase;

final class PurchasePanelContractTest extends TestCase
{
    public function testPurchasePanelApiExistsAndUsesQuickAddProductInfo(): void
    {
        $controller = BP . 'app/code/Weline/Product/Controller/Frontend/Api/PurchasePanel.php';
        $info = BP . 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml';
        $cardAdd = BP . 'app/code/Weline/Cart/view/templates/frontend/widgets/product-card-add-to-cart.phtml';
        $js = BP . 'app/code/Weline/Cart/view/statics/js/widgets/product-purchase-actions.js';
        $tiers = BP . 'app/code/Weline/B2B/view/templates/frontend/partials/qty-tiers.phtml';

        self::assertFileExists($controller);
        self::assertFileExists($tiers);
        $controllerSrc = (string)file_get_contents($controller);
        $infoSrc = (string)file_get_contents($info);
        $cardSrc = (string)file_get_contents($cardAdd);
        $jsSrc = (string)file_get_contents($js);

        self::assertStringContainsString('quick_add', $controllerSrc);
        self::assertStringContainsString('product-info.phtml', $controllerSrc);
        self::assertStringContainsString('product-native-detail--quick-add', $infoSrc);
        self::assertStringContainsString('qty-tiers.phtml', $infoSrc);
        self::assertStringContainsString('data-open-purchase-panel', $cardSrc);
        self::assertStringContainsString('openPurchasePanel', $jsSrc);
        self::assertStringContainsString('shouldOpenPurchasePanel', $jsSrc);
        self::assertStringContainsString('loadInjectedAttributeModules', $jsSrc);
        self::assertStringContainsString('helpPayShare', $jsSrc);
        self::assertStringContainsString('WelineAffiliateProductShare', $jsSrc);
        self::assertStringContainsString('applyIdentity', $jsSrc);
        self::assertStringContainsString('identity', $controllerSrc);
        self::assertStringContainsString('resolveB2bIdentity', $controllerSrc);
        self::assertStringContainsString('b2b-qty-tiers', (string)file_get_contents($tiers));
        // __() default args='' strips %{1}; JS stock template must pass ['%{1}'] sentinel.
        self::assertStringContainsString("__('仅剩 %{1} 件', ['%{1}'])", $infoSrc);
        self::assertStringNotContainsString("json_encode((string)__('仅剩 %{1} 件'), JSON_UNESCAPED_UNICODE)", $infoSrc);
    }
}
