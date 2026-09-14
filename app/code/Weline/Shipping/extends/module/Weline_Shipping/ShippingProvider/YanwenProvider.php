<?php

declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Shipping\ShippingProvider;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Api\Data\Shipping\ShippingAvailabilityRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingAvailabilityResult;
use Weline\Shipping\Api\Data\Shipping\ShippingQuoteRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingQuoteResult;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingShipmentResult;
use Weline\Shipping\Api\Data\Shipping\ShippingTestConnectionRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingTestConnectionResult;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingRequest;
use Weline\Shipping\Api\Data\Shipping\ShippingTrackingResult;
use Weline\Shipping\Service\Provider\AbstractShippingProvider;

/**
 * Built-in Yanwen logistics provider (quote / shipment / tracking).
 */
final class YanwenProvider extends AbstractShippingProvider
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ?YanwenOpenApiClient $client = null,
    ) {
    }

    public function getCode(): string
    {
        return 'yanwen';
    }

    public function getCapabilities(): array
    {
        return [
            'quote' => true,
            'shipment' => true,
            'cancel' => false,
            'confirm' => false,
            'label' => false,
            'tracking' => true,
            'webhook' => false,
        ];
    }

    public function getDisplayMetadata(): array
    {
        return [
            'title' => 'Yanwen',
            'description' => 'Yanwen Open Platform calc.list / express.order.create / express.order.get',
            'icon' => '',
            'config_template_code' => 'yanwen',
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'enabled' => ['type' => 'bool', 'default' => false],
            'user_id' => ['type' => 'string', 'required' => true],
            'api_token' => ['type' => 'string', 'required' => true],
            'environment' => ['type' => 'string', 'default' => 'sandbox'],
            'default_city_id' => ['type' => 'string', 'default' => ''],
        ];
    }

    public function cspDirectives(): array
    {
        return [
            'connect-src' => [
                'https://open.yw56.com.cn',
                'https://open-fat.yw56.com.cn',
            ],
        ];
    }

    public function checkAvailability(ShippingAvailabilityRequest $request): ShippingAvailabilityResult
    {
        $config = $request->config;
        if (!$this->isEnabled($config)) {
            return ShippingAvailabilityResult::no(['disabled']);
        }
        if ($this->userId($config) === '' || $this->apiToken($config) === '') {
            return ShippingAvailabilityResult::no(['missing_credentials']);
        }
        $country = strtoupper(trim((string)($request->address['country_code'] ?? $request->address['country'] ?? '')));
        if ($country !== '' && !YanwenDestinationCoverage::coversCountry($country)) {
            return ShippingAvailabilityResult::no(['destination_not_covered']);
        }

        return ShippingAvailabilityResult::yes();
    }

    public function defaultCoverageRegions(): array
    {
        // Country-level only — Yanwen Open API quotes by countryId / ISO code, not province.
        return YanwenDestinationCoverage::defaultCoverageRegions();
    }

    public function quote(ShippingQuoteRequest $request): ShippingQuoteResult
    {
        $availability = $this->checkAvailability(new ShippingAvailabilityRequest(
            $request->address,
            $request->scopeContext ?? [],
            $request->config,
            $request->currency,
        ));
        if (!$availability->available) {
            return ShippingQuoteResult::ok([], [], ['unavailable' => $availability->reasons]);
        }

        $weightGrams = $this->estimateWeightGrams($request->lines);
        if ($weightGrams <= 0) {
            $weightGrams = 100;
        }
        $countryId = strtoupper(trim((string)($request->address['country_code'] ?? '')));
        $cityId = trim((string)($request->config['default_city_id'] ?? ''));
        if ($cityId === '' || $countryId === '') {
            return ShippingQuoteResult::failed('missing_city_or_country');
        }

        $payload = [
            'cityId' => $cityId,
            'countryId' => $countryId,
            'weight' => $weightGrams,
        ];
        $postCode = trim((string)($request->address['postcode'] ?? $request->address['postal_code'] ?? ''));
        if ($postCode !== '') {
            $payload['postCode'] = $postCode;
        }

        try {
            $response = $this->client()->call(
                $this->userId($request->config),
                $this->apiToken($request->config),
                'calc.list',
                $payload,
                $this->environment($request->config),
            );
        } catch (\Throwable $e) {
            return ShippingQuoteResult::failed($e->getMessage());
        }

        if (!($response['success'] ?? false)) {
            return ShippingQuoteResult::failed((string)($response['message'] ?? 'quote_failed'), [
                'code' => $response['code'] ?? null,
            ]);
        }

        $rows = $response['data'] ?? [];
        if (!\is_array($rows)) {
            return ShippingQuoteResult::failed('quote_empty');
        }

        $currency = strtoupper(trim($request->currency));
        $precision = max(0, min(6, $request->currencyPrecision));
        $rates = [];
        $fxSkipped = [];
        $defaultCarrierId = 0;
        $defaultSpecificity = 0;
        $strictCodes = [];
        foreach ($request->matchedServices as $summary) {
            $code = trim((string)($summary['service_code'] ?? ''));
            $defaultCarrierId = $defaultCarrierId > 0
                ? $defaultCarrierId
                : (int)($summary['carrier_id'] ?? 0);
            $defaultSpecificity = max($defaultSpecificity, (int)($summary['lane_specificity'] ?? 0));
            if ($code === '') {
                continue;
            }
            // Only enforce product filters when service_code looks like a channel/product id.
            if (str_contains($code, ':') || preg_match('/^\d+$/', $code) === 1) {
                $strictCodes[$code] = $summary;
            }
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $productNumber = trim((string)($row['productNumber'] ?? ''));
            if ($productNumber === '') {
                continue;
            }
            $serviceCode = 'yanwen:' . $productNumber;
            if ($strictCodes !== []) {
                $found = isset($strictCodes[$serviceCode]) || isset($strictCodes[$productNumber]);
                if (!$found) {
                    foreach ($strictCodes as $code => $_) {
                        if (str_ends_with($code, ':' . $productNumber)) {
                            $serviceCode = $code;
                            $found = true;
                            break;
                        }
                    }
                }
                if (!$found) {
                    continue;
                }
            }

            $quoteCurrency = strtoupper(trim((string)($row['currency'] ?? 'CNY')));
            if ($quoteCurrency === '') {
                $quoteCurrency = 'CNY';
            }
            $major = (float)($row['totalMoney'] ?? $row['rmbTotalMoney'] ?? 0);
            $amountMinor = (int)round($major * (10 ** $precision));
            if ($quoteCurrency !== $currency) {
                $converted = $this->convertMajorToMinor($major, $quoteCurrency, $currency, $precision);
                if ($converted === null) {
                    $fxSkipped[] = $serviceCode;
                    continue;
                }
                $amountMinor = $converted;
            }
            $label = trim((string)($row['productName'] ?? $serviceCode));
            $carrierId = (int)($strictCodes[$serviceCode]['carrier_id'] ?? $defaultCarrierId);
            $rates[$serviceCode] = [
                'amount_minor' => max(0, $amountMinor),
                'label' => $label !== '' ? $label : $serviceCode,
                'currencies' => [$currency],
                'carrier_id' => $carrierId,
                'lane_specificity' => (int)($strictCodes[$serviceCode]['lane_specificity'] ?? $defaultSpecificity),
            ];
        }

        return ShippingQuoteResult::ok($rates, $fxSkipped, [
            'product_count' => \count($rows),
            'returned' => \count($rates),
        ]);
    }

    public function createShipment(ShippingShipmentRequest $request): ShippingShipmentResult
    {
        $availability = $this->checkAvailability(new ShippingAvailabilityRequest(
            $request->address,
            [],
            $request->config,
            $request->currency,
        ));
        if (!$availability->available) {
            return ShippingShipmentResult::failed('unavailable');
        }

        $channelId = trim((string)($request->extra['channel_id'] ?? ''));
        if ($channelId === '' && str_starts_with($request->serviceCode, 'yanwen:')) {
            $channelId = substr($request->serviceCode, strlen('yanwen:'));
        }
        if ($channelId === '') {
            $channelId = $request->serviceCode;
        }
        if ($channelId === '') {
            return ShippingShipmentResult::failed('channel_id_required');
        }

        $productList = $request->extra['product_list'] ?? [];
        if (!\is_array($productList) || $productList === []) {
            $productList = [[
                'goodsNameCh' => (string)($request->extra['goods_name_ch'] ?? '商品'),
                'goodsNameEn' => (string)($request->extra['goods_name_en'] ?? 'Goods'),
                'price' => (float)($request->extra['price'] ?? 1),
                'priceExport' => (float)($request->extra['price_export'] ?? 1),
                'quantity' => (int)($request->extra['quantity'] ?? 1),
                'weight' => (int)($request->extra['weight_grams'] ?? 100),
            ]];
        }

        $data = [
            'channelId' => $channelId,
            'orderSource' => (string)($request->extra['order_source'] ?? 'Weline'),
            'orderNumber' => $request->orderNumber,
            'receiver' => [
                'name' => (string)($request->address['name'] ?? $request->address['fullname'] ?? ''),
                'phone' => (string)($request->address['phone'] ?? $request->address['telephone'] ?? ''),
                'country' => (string)($request->address['country_code'] ?? ''),
                'state' => (string)($request->address['province'] ?? ''),
                'city' => (string)($request->address['city'] ?? ''),
                'address1' => (string)($request->address['street'] ?? $request->address['address'] ?? ''),
                'postcode' => (string)($request->address['postcode'] ?? $request->address['postal_code'] ?? ''),
            ],
            'productList' => $productList,
        ];
        if (!empty($request->extra['company_code'])) {
            $data['companyCode'] = (string)$request->extra['company_code'];
        }

        try {
            $response = $this->client()->call(
                $this->userId($request->config),
                $this->apiToken($request->config),
                'express.order.create',
                $data,
                $this->environment($request->config),
            );
        } catch (\Throwable $e) {
            return ShippingShipmentResult::failed($e->getMessage());
        }

        if (!($response['success'] ?? false)) {
            return ShippingShipmentResult::failed((string)($response['message'] ?? 'create_failed'), [
                'code' => $response['code'] ?? null,
            ]);
        }
        $payload = \is_array($response['data'] ?? null) ? $response['data'] : [];
        $waybill = trim((string)($payload['waybillNumber'] ?? ''));
        if ($waybill === '') {
            return ShippingShipmentResult::failed('missing_waybill', $payload);
        }

        return ShippingShipmentResult::ok(
            $waybill,
            (string)($payload['yanwenOrderNumber'] ?? $waybill),
            $payload,
        );
    }

    public function queryTracking(ShippingTrackingRequest $request): ShippingTrackingResult
    {
        $availability = $this->checkAvailability(new ShippingAvailabilityRequest(
            [],
            [],
            $request->config,
        ));
        if (!$availability->available) {
            return ShippingTrackingResult::failed('unavailable');
        }

        try {
            $response = $this->client()->call(
                $this->userId($request->config),
                $this->apiToken($request->config),
                'express.order.get',
                ['waybillNumber' => $request->trackingNumber],
                $this->environment($request->config),
            );
        } catch (\Throwable $e) {
            return ShippingTrackingResult::failed($e->getMessage());
        }

        if (!($response['success'] ?? false)) {
            return ShippingTrackingResult::failed((string)($response['message'] ?? 'tracking_failed'));
        }
        $payload = \is_array($response['data'] ?? null) ? $response['data'] : [];
        $statusCode = (int)($payload['status'] ?? -1);
        $statusMap = [
            0 => 'created',
            1 => 'confirmed',
            2 => 'received',
            3 => 'in_transit',
            4 => 'delivered',
            5 => 'cancelled',
            12 => 'out_for_delivery',
            15 => 'completed',
        ];

        return ShippingTrackingResult::ok(
            $statusMap[$statusCode] ?? ('status_' . $statusCode),
            (string)($payload['companyCode'] ?? ''),
            '',
            [],
            $payload,
        );
    }

    public function testConnection(ShippingTestConnectionRequest $request): ShippingTestConnectionResult
    {
        $availability = $this->checkAvailability(new ShippingAvailabilityRequest(
            [],
            [],
            $request->config,
        ));
        if (!$availability->available) {
            return ShippingTestConnectionResult::failed(implode(',', $availability->reasons));
        }
        try {
            $response = $this->client()->call(
                $this->userId($request->config),
                $this->apiToken($request->config),
                'calc.list',
                [
                    'cityId' => (string)($request->config['default_city_id'] ?? '1'),
                    'countryId' => 'US',
                    'weight' => 100,
                ],
                $this->environment($request->config) !== ''
                    ? $this->environment($request->config)
                    : ($request->environment !== '' ? $request->environment : YanwenOpenApiClient::ENV_SANDBOX),
            );
        } catch (\Throwable $e) {
            return ShippingTestConnectionResult::failed($e->getMessage());
        }

        if (!($response['success'] ?? false)) {
            return ShippingTestConnectionResult::failed((string)($response['message'] ?? 'test_failed'), [
                'code' => $response['code'] ?? null,
            ]);
        }

        return ShippingTestConnectionResult::ok('connected', [
            'rows' => \is_array($response['data'] ?? null) ? \count($response['data']) : 0,
        ]);
    }

    private function client(): YanwenOpenApiClient
    {
        return $this->client ?? new YanwenOpenApiClient();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isEnabled(array $config): bool
    {
        $raw = $config['enabled'] ?? false;

        return $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function userId(array $config): string
    {
        return trim((string)($config['user_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function apiToken(array $config): string
    {
        return trim((string)($config['api_token'] ?? ''));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function environment(array $config): string
    {
        $env = strtolower(trim((string)($config['environment'] ?? 'sandbox')));

        return $env !== '' ? $env : YanwenOpenApiClient::ENV_SANDBOX;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function estimateWeightGrams(array $lines): int
    {
        $grams = 0;
        foreach ($lines as $line) {
            $w = (float)($line['weight'] ?? $line['weight_kg'] ?? 0);
            $qty = max(1, (int)($line['qty'] ?? 1));
            if ($w > 0 && $w < 50) {
                // treat as kg
                $grams += (int)round($w * 1000) * $qty;
            } else {
                $grams += (int)round($w) * $qty;
            }
            $grams += (int)($line['weight_grams'] ?? 0) * $qty;
        }

        return max(0, $grams);
    }

    private function convertMajorToMinor(float $major, string $from, string $to, int $precision): ?int
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === $to) {
            return (int)round($major * (10 ** $precision));
        }
        try {
            /** @var CurrencyRateService $rates */
            $rates = $this->objectManager->getInstance(CurrencyRateService::class);
            $converted = $rates->tryConvert($major, $from, $to);
            if ($converted === null) {
                return null;
            }

            return max(0, (int)round($converted * (10 ** $precision)));
        } catch (\Throwable) {
            return null;
        }
    }
}
