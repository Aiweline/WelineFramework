<?php

declare(strict_types=1);

use Weline\B2B\Service\B2BCheckoutRecheckService;
use Weline\B2B\Service\B2BService;

require_once dirname(__DIR__, 2) . '/Unit/bootstrap.php';

try {
    $service = B2BService::forTesting();
    $service->seedGroup('g-e2e-submit', 0, 'e2e-submit');
    $service->assignCustomer('cust-e2e-submit', 'g-e2e-submit');
    $service->seedPriceList('pl-e2e-web', 'g-e2e-submit', 0, 1, ['SKU-A' => 800]);
    $service->seedPriceList('pl-e2e-a', 'g-e2e-submit', 0, 2, ['SKU-A' => 700], 'ch-a');
    $service->seedPriceList('pl-e2e-b', 'g-e2e-submit', 0, 2, ['SKU-A' => 720], 'ch-b');
    $service->enableShadow();

    $channelA = $service->issueQuote([
        'customer_id' => 'cust-e2e-submit',
        'website_id' => 0,
        'channel_id' => 'ch-a',
        'sku' => 'SKU-A',
        'retail_amount_minor' => 1000,
    ]);
    $channelB = $service->issueQuote([
        'customer_id' => 'cust-e2e-submit',
        'website_id' => 0,
        'channel_id' => 'ch-b',
        'sku' => 'SKU-A',
        'retail_amount_minor' => 1000,
    ]);
    $website = $service->issueQuote([
        'customer_id' => 'cust-e2e-submit',
        'website_id' => 0,
        'sku' => 'SKU-A',
        'retail_amount_minor' => 1000,
    ]);
    $accepted = $service->submit(
        (string)$website['token']['token_id'],
        'cust-e2e-submit',
        0,
        'order-e2e-frozen',
    );
    $frozenHash = (string)$accepted['snapshot']['hash'];

    $stale = $service->issueQuote([
        'customer_id' => 'cust-e2e-submit',
        'website_id' => 0,
        'sku' => 'SKU-A',
        'retail_amount_minor' => 1000,
    ]);
    $service->seedPriceList('pl-e2e-web', 'g-e2e-submit', 0, 9, ['SKU-A' => 1]);
    $rejected = $service->submit(
        (string)$stale['token']['token_id'],
        'cust-e2e-submit',
        0,
        'order-e2e-stale',
    );
    $frozen = $service->checkout()->readSnapshot(
        'order-e2e-frozen',
        'cust-e2e-submit',
        0,
    );

    echo json_encode([
        'ok' => true,
        'channel_a_amount' => $channelA['token']['amount_minor'],
        'channel_a_list' => $channelA['token']['price_list_id'],
        'channel_b_amount' => $channelB['token']['amount_minor'],
        'channel_b_list' => $channelB['token']['price_list_id'],
        'accepted' => $accepted['ok'],
        'accepted_count' => $service->checkout()->acceptedOrderCount(),
        'stale_rejected' => !($rejected['ok'] ?? false),
        'stale_error' => $rejected['error'] ?? null,
        'expected_stale_error' => B2BCheckoutRecheckService::ERROR_QUOTE_VERSION_CONFLICT,
        'frozen_amount' => $frozen['amount_minor'] ?? null,
        'frozen_version' => $frozen['version'] ?? null,
        'frozen_hash_unchanged' => ($frozen['hash'] ?? null) === $frozenHash,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
        'type' => $exception::class,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
}
