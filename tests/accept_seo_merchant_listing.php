<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

define('SANDBOX', true);
if (!defined('SANDBOX')) {
    define('SANDBOX', true);
}

function accept_fail(string $reason): never
{
    echo "ACCEPT FAIL: {$reason}\n";
    exit(1);
}

function accept_assert(bool $condition, string $reason): void
{
    if (!$condition) {
        accept_fail($reason);
    }
}

$class = 'Weline\Seo\Service\Head\ProductCommerceFactsResolver';
accept_assert(class_exists($class), 'resolver class missing');
$reflection = new ReflectionClass($class);
accept_assert($reflection->hasMethod('resolve'), 'resolve method missing');
$method = $reflection->getMethod('resolve');
accept_assert($method->isPublic(), 'resolve is not public');
accept_assert(($reflection->getConstructor()?->getNumberOfParameters() ?? 0) === 0, 'constructor must have no parameters');

$resolver = new $class();
$result = $resolver->resolve(['name' => 'x'], []);
accept_assert(is_array($result) && array_keys($result) === ['merchant_return_policy', 'shipping_details'], 'A2 structure invalid');
accept_assert($result['merchant_return_policy'] === null || is_array($result['merchant_return_policy']), 'A2 return policy type invalid');
accept_assert($result['shipping_details'] === null || is_array($result['shipping_details']), 'A2 shipping details type invalid');

if (class_exists(\Weline\Shipping\Service\ShippingCommercePolicyService::class)
    && class_exists(\Weline\Shipping\Service\ShippingProfileResolver::class)) {
    $result = $resolver->resolve(
        ['shipping_profile_code' => 'SEED_PROFILE_GENERAL'],
        ['website_id' => 0, 'currency' => 'USD']
    );
    accept_assert(is_array($result['merchant_return_policy']), 'A3 return policy missing');
    $return = $result['merchant_return_policy'];
    accept_assert(($return['@type'] ?? null) === 'MerchantReturnPolicy', 'A3 return policy type invalid');
    foreach (['applicableCountry', 'returnPolicyCategory', 'returnMethod', 'returnFees'] as $key) {
        accept_assert(array_key_exists($key, $return), "A3 return policy missing {$key}");
    }

    $shipping = $result['shipping_details'];
    accept_assert(is_array($shipping) && $shipping !== [], 'A3 shipping details missing');
    $details = array_is_list($shipping) && isset($shipping[0]) ? $shipping : [$shipping];
    foreach ($details as $detail) {
        accept_assert(is_array($detail) && ($detail['@type'] ?? null) === 'OfferShippingDetails', 'A3 shipping detail type invalid');
        accept_assert(isset($detail['shippingDestination']) && is_array($detail['shippingDestination']), 'A3 shipping destination missing');
        $countries = $detail['shippingDestination']['addressCountry'] ?? null;
        foreach (is_array($countries) ? $countries : [$countries] as $country) {
            accept_assert(is_string($country) && preg_match('/^[A-Z]{2}$/', $country) === 1, 'A3 country code invalid');
        }
    }
}

$result = $resolver->resolve([], ['website_id' => 0]);
accept_assert(is_array($result) && array_keys($result) === ['merchant_return_policy', 'shipping_details'], 'A4 structure invalid');
accept_assert($result['merchant_return_policy'] === null || is_array($result['merchant_return_policy']), 'A4 return policy type invalid');
accept_assert($result['shipping_details'] === null || is_array($result['shipping_details']), 'A4 shipping details type invalid');

echo "ACCEPT PASS\n";
exit(0);
