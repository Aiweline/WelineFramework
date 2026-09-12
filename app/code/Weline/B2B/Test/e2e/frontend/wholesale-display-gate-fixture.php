<?php

declare(strict_types=1);

/**
 * CLI fixture for wholesale display gate e2e.
 * stdout JSON: gate matrix for Playwright / CI.
 */
require dirname(__DIR__, 2) . '/Unit/bootstrap.php';

use Weline\B2B\Model\PriceList;
use Weline\B2B\Service\B2BCartQtyPolicy;
use Weline\B2B\Service\PriceListStore;
use Weline\B2B\Service\ProductWholesaleEligibility;
use Weline\B2B\Service\SellingModePolicy;

try {
    $lists = PriceListStore::forTesting();
    $lists->put(new PriceList('pl-gate', 'g-gate', 1, 1, [
        'SKU-WHOLESALE' => [5 => 800, 10 => 700],
    ]));
    $policy = SellingModePolicy::forTesting(['website:1' => ['toc' => true, 'tob' => true]]);
    $gate = new ProductWholesaleEligibility($policy, $lists);
    $qty = new B2BCartQtyPolicy($policy, $gate);

    $eligibleDisplay = $gate->allowsWholesaleDisplay(1, 0, [SellingModePolicy::PRODUCT_FLAG_TOB => true], 'SKU-WHOLESALE');
    $flagOffDisplay = $gate->allowsWholesaleDisplay(1, 0, [SellingModePolicy::PRODUCT_FLAG_TOB => false], 'SKU-WHOLESALE');
    $noTierDisplay = $gate->allowsWholesaleDisplay(1, 0, [SellingModePolicy::PRODUCT_FLAG_TOB => true], 'SKU-RETAIL');

    $ineligibleQty = $qty->assertQty([
        'cart_type' => 'tob',
        'qty' => 1,
        'sku' => 'SKU-RETAIL',
        'website_id' => 1,
        'product_flags' => [SellingModePolicy::PRODUCT_FLAG_TOB => true],
    ]);
    $eligibleQty = $qty->assertQty([
        'cart_type' => 'tob',
        'qty' => 1,
        'sku' => 'SKU-WHOLESALE',
        'website_id' => 1,
        'product_flags' => [SellingModePolicy::PRODUCT_FLAG_TOB => true],
    ]);

    $switcher = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/selling-mode-switcher.phtml';
    $tiers = dirname(__DIR__, 3) . '/view/templates/frontend/partials/qty-tiers.phtml';
    $switcherSrc = (string)file_get_contents($switcher);
    $tiersSrc = (string)file_get_contents($tiers);

    $ok = $eligibleDisplay === true
        && $flagOffDisplay === false
        && $noTierDisplay === false
        && ($ineligibleQty['ok'] ?? false) === true
        && ($eligibleQty['ok'] ?? true) === false
        && str_contains($switcherSrc, 'ProductWholesaleEligibility')
        && str_contains($tiersSrc, 'allowsWholesaleDisplay');

    echo json_encode([
        'ok' => $ok,
        'eligible_display' => $eligibleDisplay,
        'flag_off_display' => $flagOffDisplay,
        'no_tiers_display' => $noTierDisplay,
        'ineligible_qty_ok' => (bool)($ineligibleQty['ok'] ?? false),
        'eligible_qty_ok' => (bool)($eligibleQty['ok'] ?? false),
        'templates_wired' => str_contains($switcherSrc, 'ProductWholesaleEligibility')
            && str_contains($tiersSrc, 'allowsWholesaleDisplay'),
    ], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
