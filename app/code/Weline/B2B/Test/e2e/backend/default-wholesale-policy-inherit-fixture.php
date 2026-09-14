<?php

declare(strict_types=1);

/**
 * CLI fixture: default policy inherit + tob gate + guard reject.
 */

use Weline\B2B\Model\SystemVipLadder;
use Weline\B2B\Service\B2BCheckoutRecheckService;
use Weline\B2B\Service\B2BPriceEngine;
use Weline\B2B\Service\B2BService;
use Weline\B2B\Service\DefaultWholesalePolicy;
use Weline\B2B\Service\WholesalePricingGuard;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;

require_once dirname(__DIR__, 2) . '/Unit/bootstrap.php';

$policy = DefaultWholesalePolicy::forTesting(2000);
$engine = B2BPriceEngine::forTesting(null, $policy, new WholesalePricingGuard());
$engine->rollout()->setMode(B2BPriceEngine::CAPABILITY, CommerceRolloutGateInterface::MODE_SHADOW);
$service = new B2BService(
    $engine,
    $engine->rollout(),
    B2BCheckoutRecheckService::forTesting($engine),
);
$gid = SystemVipLadder::groupId(0);
$service->seedGroup($gid, 0, SystemVipLadder::code(0));
$service->assignCustomer('cust-e2e-inherit', $gid);

$inherit = $service->resolve([
    'customer_id' => 'cust-e2e-inherit',
    'website_id' => 0,
    'sku' => 'SKU-E2E-INHERIT',
    'qty' => 5,
    'retail_amount_minor' => 10000,
    'selling_mode_tob' => true,
]);

$tobOff = $service->resolve([
    'customer_id' => 'cust-e2e-inherit',
    'website_id' => 0,
    'sku' => 'SKU-E2E-INHERIT',
    'qty' => 5,
    'retail_amount_minor' => 10000,
    'selling_mode_tob' => false,
]);

$guardRejected = false;
try {
    (new WholesalePricingGuard())->assertAmountAllowed(10000, 7000, 2000);
} catch (InvalidArgumentException) {
    $guardRejected = true;
}

$out = [
    'ok' => ($inherit['ok'] ?? false) === true
        && ($inherit['source'] ?? '') === B2BPriceEngine::SOURCE_B2B_DEFAULT_POLICY
        && (int) ($inherit['amount_minor'] ?? 0) === 9500
        && ($tobOff['source'] ?? '') === B2BPriceEngine::SOURCE_RETAIL
        && $guardRejected,
    'inherit_source' => $inherit['source'] ?? null,
    'inherit_amount_minor' => $inherit['amount_minor'] ?? null,
    'tob_off_source' => $tobOff['source'] ?? null,
    'guard_rejected' => $guardRejected,
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
