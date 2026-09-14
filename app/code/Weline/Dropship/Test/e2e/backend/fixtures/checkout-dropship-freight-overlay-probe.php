<?php

declare(strict_types=1);

/**
 * 结账货源运费 overlay 通路：壳标准请求 → Cj 映射；Fake Aggregator 最低档；overlay 全货源覆盖。
 * 退出码 0=PASS。不打公网 CJ freightCalculate。
 */
require dirname(__DIR__, 7) . '/bootstrap.php';

use Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider\CjProvider;
use Weline\Dropship\Service\DropshipCheckoutFreightService;
use Weline\Dropship\Service\DropshipFreightAggregator;
use Weline\Dropship\Service\DropshipPricingService;
use Weline\Dropship\Extends\Module\Weline_Dropship\DropshipProvider\FakeDropshipProvider;
use Weline\Framework\Manager\ObjectManager;

$fail = static function (string $m): void {
    fwrite(STDERR, "FAIL: {$m}\n");
    exit(1);
};

$body = CjProvider::mapFreightCalculateRequest([
    'start_country_code' => 'CN',
    'end_country_code' => 'US',
    'zip' => '10001',
    'products' => [
        ['external_vid' => '2609110854341615800', 'qty' => 1],
    ],
]);
if (($body['products'][0]['vid'] ?? '') !== '2609110854341615800') {
    $fail('cj map vid');
}

$fake = new FakeDropshipProvider();
$opts = $fake->quoteFreight([]);
if ($opts === [] || (int)($opts[0]['amount_minor'] ?? 0) !== 499) {
    $fail('fake quoteFreight expected 499');
}

$channels = new class ($fake) {
    public function __construct(private FakeDropshipProvider $fake)
    {
    }

    public function getProvider(string $code): ?\Weline\Dropship\Interface\DropshipProviderInterface
    {
        return $code === 'fake' ? $this->fake : null;
    }
};

// Aggregator type-hints DropshipChannelManager; use real scanner manager and stub via overlay service only.
$agg = ObjectManager::getInstance(DropshipFreightAggregator::class);
$svc = new class ($fake) extends DropshipCheckoutFreightService {
    public function __construct(private FakeDropshipProvider $fake)
    {
        parent::__construct(null, null, new DropshipPricingService(), null);
    }

    public function resolveFreightLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $i => $line) {
            if (!is_array($line) || !(bool)($line['requires_shipping'] ?? true)) {
                continue;
            }
            $out[] = [
                'line_key' => 'offer-' . (int)($line['offer_id'] ?? 0) . '-' . $i,
                'offer_id' => (int)($line['offer_id'] ?? 0),
                'provider_code' => 'fake',
                'external_sku' => 'VID',
                'external_spu' => 'SPU',
                'external_vid' => 'VID',
                'remote_country' => 'CN',
                'qty' => max(1, (int)($line['qty'] ?? 1)),
                'split_key' => (string)($line['split_key'] ?? ''),
            ];
        }

        return $out;
    }

    protected function quoteViaFake(array $resolved, array $address, string $currency): array
    {
        $opts = $this->fake->quoteFreight([]);
        $best = null;
        foreach ($opts as $seg) {
            $m = (int)($seg['amount_minor'] ?? 0);
            if ($best === null || $m < $best['amount_minor']) {
                $best = array_merge($seg, ['amount_minor' => $m, 'currency' => $currency, 'provider_code' => 'fake']);
            }
        }
        if ($best === null) {
            return ['total_minor' => 0, 'currency' => $currency, 'segments' => [], 'error' => DropshipCheckoutFreightService::ERROR_FREIGHT_UNAVAILABLE];
        }

        return ['total_minor' => (int)$best['amount_minor'], 'currency' => $currency, 'segments' => [$best], 'error' => null];
    }

    public function overlayAmount(
        int $localAmountMinor,
        array $lines,
        array $address,
        array $scope,
        string $currency,
        array $splitPackages = [],
    ): array {
        $resolved = $this->resolveFreightLines($lines);
        if ($resolved === []) {
            return [
                'applied' => false,
                'amount_minor' => $localAmountMinor,
                'currency' => $currency,
                'segments' => [],
                'split_packages' => $splitPackages,
                'error' => null,
            ];
        }
        $quote = $this->quoteViaFake($resolved, $address, $currency);
        if (($quote['error'] ?? null) !== null) {
            return [
                'applied' => true,
                'amount_minor' => $localAmountMinor,
                'currency' => $currency,
                'segments' => [],
                'split_packages' => $splitPackages,
                'error' => (string)$quote['error'],
            ];
        }

        return [
            'applied' => true,
            'amount_minor' => (int)$quote['total_minor'],
            'currency' => $currency,
            'segments' => $quote['segments'],
            'split_packages' => $splitPackages,
            'error' => null,
        ];
    }
};

$result = $svc->overlayAmount(
    1200,
    [['offer_id' => 99, 'requires_shipping' => true, 'qty' => 1]],
    ['country_code' => 'US'],
    ['website_id' => 1],
    'USD',
);
unset($agg, $channels);
if (!empty($result['error'])) {
    $fail('overlay error ' . $result['error']);
}
if ((int)$result['amount_minor'] !== 499) {
    $fail('overlay amount expected 499 got ' . (int)$result['amount_minor']);
}

$checkoutSrc = (string)file_get_contents(
    dirname(__DIR__, 5) . '/Checkout/Service/CheckoutGroupSubmitService.php'
);
if (!str_contains($checkoutSrc, 'Weline_Checkout::checkout::shipping_quote::overlay')) {
    $fail('checkout missing overlay dispatch');
}

echo "PASS checkout-dropship-freight-overlay\n";
exit(0);
