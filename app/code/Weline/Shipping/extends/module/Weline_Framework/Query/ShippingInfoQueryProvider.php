<?php
declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Shipping\Api\Quote\ShippingQuoteServiceInterface;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\ShippingService as ShippingServiceModel;
use Weline\Shipping\Service\RegionService;
use Weline\Shipping\Service\ShippingServiceManager;

class ShippingInfoQueryProvider implements QueryProviderInterface
{
    private ?ShippingQuoteServiceInterface $quoteService;

    public function __construct(
        private readonly ShippingServiceManager $serviceManager,
        private readonly RegionService $regionService,
        ?ShippingQuoteServiceInterface $quoteService = null,
    ) {
        $this->quoteService = $quoteService;
    }

    public function getProviderName(): string
    {
        return 'shippingInfo';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getByLocation' => $this->getByLocation($params),
            'listQuoteOptions', 'quote' => $this->quoteOps($operation, $params),
            'quoteReturnShipping' => $this->quoteReturnShipping($params),
            default => throw new \InvalidArgumentException('Shipping info query provider does not support operation: ' . $operation),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function quoteReturnShipping(array $params): array
    {
        try {
            /** @var \Weline\Shipping\Service\ShippingFacade $facade */
            $facade = ObjectManager::getInstance(\Weline\Shipping\Service\ShippingFacade::class);
            $lines = \is_array($params['lines'] ?? null) ? $params['lines'] : [];
            $address = \is_array($params['address'] ?? null) ? $params['address'] : [];
            $context = \is_array($params['context'] ?? null) ? $params['context'] : null;
            $quote = $facade->quoteReturnShipping(
                $lines,
                $address,
                (string)($params['currency'] ?? 'CNY'),
                (int)($params['currency_precision'] ?? 2),
                $context,
            );

            return ['success' => true, 'data' => $quote];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Minor-unit Quote API surface（MOD-P2E-002）.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function quoteOps(string $operation, array $params): array
    {
        try {
            $svc = $this->quoteService();
            $scope = $this->normalizeQuoteScope($params);
            $configVersion = \array_key_exists('config_version', $params)
                ? (string)$params['config_version']
                : '';
            // listQuoteOptions：未传 version 时留空，跳过双读比对；quote 未传则用当前层 active。
            if ($operation === 'quote' && $configVersion === '') {
                $configVersion = $this->serviceManager->activeQuoteConfigVersion([
                    'website_id' => (int)$scope['website_id'],
                    'store_id' => (int)$scope['store_id'],
                    'channel_id' => (int)$scope['channel_id'],
                ]);
            }
            $request = new \Weline\Shipping\Api\Quote\ShippingQuoteRequest(
                scope: $scope,
                address: \is_array($params['address'] ?? null) ? $params['address'] : [],
                lines: \is_array($params['lines'] ?? null) ? $params['lines'] : [],
                currency: (string)($params['currency'] ?? 'CNY'),
                currencyPrecision: (int)($params['currency_precision'] ?? 2),
                configVersion: $configVersion,
                serviceCode: isset($params['service_code']) ? (string)$params['service_code'] : null,
            );
            if ($operation === 'listQuoteOptions') {
                $options = $svc->listOptions($request);
                $pinned = $configVersion !== ''
                    ? $configVersion
                    : $this->serviceManager->activeQuoteConfigVersion([
                        'website_id' => (int)$scope['website_id'],
                        'store_id' => (int)$scope['store_id'],
                        'channel_id' => (int)$scope['channel_id'],
                    ]);

                return [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'options' => $options,
                        'config_version' => $pinned,
                        'quote_diagnostics' => $this->serviceManager->getLastQuoteDiagnostics(),
                    ],
                ];
            }
            $serviceCode = trim((string)($params['service_code'] ?? ''));
            $quote = $svc->quote($request, $serviceCode);
            return [
                'success' => true,
                'code' => 200,
                'data' => $quote->toArray(),
            ];
        } catch (\Throwable $e) {
            $code = $e instanceof \Weline\Shipping\Service\ShippingQuoteConflictException
                ? $e->errorCode()
                : 'shipping_quote_failed';
            return [
                'success' => false,
                'code' => 400,
                'message' => $e->getMessage(),
                'error_code' => $code,
                'data' => [],
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizeQuoteScope(array $params): array
    {
        $scope = \is_array($params['scope'] ?? null) ? $params['scope'] : [];
        foreach (['website_id', 'store_id', 'channel_id'] as $key) {
            if (array_key_exists($key, $params) && !array_key_exists($key, $scope)) {
                $scope[$key] = (int)$params[$key];
            }
        }
        if (!isset($scope['website_id'])) {
            $scope['website_id'] = (int)\Weline\Framework\Runtime\RequestContext::getWelineWebsiteId();
        }
        if (!isset($scope['store_id'])) {
            $scope['store_id'] = (int)\Weline\Framework\Runtime\RequestContext::getWelineStoreId();
        }
        if (!isset($scope['channel_id'])) {
            $scope['channel_id'] = (int)\Weline\Framework\Runtime\RequestContext::getWelineChannelId();
        }
        $scope['website_id'] = max(0, (int)$scope['website_id']);
        $scope['store_id'] = max(0, (int)$scope['store_id']);
        $scope['channel_id'] = max(0, (int)$scope['channel_id']);

        return $scope;
    }

    private function quoteService(): ShippingQuoteServiceInterface
    {
        if ($this->quoteService instanceof ShippingQuoteServiceInterface) {
            return $this->quoteService;
        }
        $resolved = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(ShippingQuoteServiceInterface::class);
        if (!$resolved instanceof ShippingQuoteServiceInterface) {
            throw new \RuntimeException('shipping_quote_provider_missing');
        }

        return $this->quoteService = $resolved;
    }

    private function getByLocation(array $params): array
    {
        $countryCode = trim((string)($params['country_code'] ?? 'CN'));
        if ($countryCode === '') {
            return $this->failure('Country code is required.');
        }

        try {
            $province = trim((string)($params['province'] ?? ''));
            $city = trim((string)($params['city'] ?? ''));
            $district = trim((string)($params['district'] ?? ''));
            $services = $this->serviceManager->getAvailableServices($countryCode, $province, $city, $district);
            $region = $this->regionService->findByLocation($countryCode, $province, $city, $district);
            $shippingInfo = [
                'location' => [
                    'country_code' => $countryCode,
                    'province' => $province,
                    'city' => $city,
                    'district' => $district,
                ],
                'region' => $region ? [
                    'region_id' => $region->getId(),
                    'region_name' => $region->getData(Region::schema_fields_REGION_NAME),
                    'region_code' => $region->getData(Region::schema_fields_REGION_CODE),
                    'country_code' => $region->getData(Region::schema_fields_COUNTRY_CODE),
                ] : null,
                'services' => [],
                'price_rules' => [],
            ];

            foreach ($services as $service) {
                $serviceId = (int)($service['service_id'] ?? $service['shipping_service_id'] ?? 0);
                if ($serviceId <= 0) {
                    continue;
                }
                $priceRules = $this->getPriceRulesForService($serviceId);
                $shippingInfo['services'][] = [
                    'service_id' => $serviceId,
                    'service_name' => (string)($service['service_name'] ?? ''),
                    'service_code' => (string)($service['service_code'] ?? ''),
                    'carrier_id' => $service['carrier_id'] ?? null,
                    'estimated_days_min' => $service['estimated_days_min'] ?? null,
                    'estimated_days_max' => $service['estimated_days_max'] ?? null,
                    'is_free_shipping' => (bool)($service['is_free_shipping'] ?? false),
                    'price_rules' => $priceRules,
                ];
                if ($priceRules !== []) {
                    $shippingInfo['price_rules'][$serviceId] = $priceRules;
                }
            }

            return [
                'success' => true,
                'code' => 200,
                'data' => $shippingInfo,
            ];
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function getPriceRulesForService(int $serviceId): array
    {
        try {
            /** @var ShippingServiceModel $serviceModel */
            $serviceModel = ObjectManager::getInstance(ShippingServiceModel::class);
            $service = $serviceModel->load($serviceId);
            if (!$service->getId()) {
                return [];
            }

            $templateId = (int)$service->getData(ShippingServiceModel::schema_fields_RATE_TEMPLATE_ID);
            if ($templateId <= 0) {
                return [];
            }

            /** @var RateTemplate $templateModel */
            $templateModel = ObjectManager::getInstance(RateTemplate::class);
            $template = $templateModel->load($templateId);
            if (!$template->getId()) {
                return [];
            }

            return [
                'template_id' => $template->getId(),
                'template_name' => $template->getData(RateTemplate::schema_fields_TEMPLATE_NAME),
                'calculation_type' => $template->getData(RateTemplate::schema_fields_CALCULATION_TYPE),
                'base_fee' => (float)$template->getData(RateTemplate::schema_fields_BASE_FEE),
                'weight_unit' => $template->getData(RateTemplate::schema_fields_WEIGHT_UNIT),
                'weight_rate' => (float)$template->getData(RateTemplate::schema_fields_WEIGHT_RATE),
                'volume_unit' => $template->getData(RateTemplate::schema_fields_VOLUME_UNIT),
                'volume_rate' => (float)$template->getData(RateTemplate::schema_fields_VOLUME_RATE),
                'quantity_rate' => (float)$template->getData(RateTemplate::schema_fields_QUANTITY_RATE),
                'mixed_config' => $template->getData(RateTemplate::schema_fields_MIXED_CONFIG),
                'currency_code' => $template->getData(RateTemplate::schema_fields_CURRENCY_CODE) ?: 'CNY',
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    private function failure(string $message): array
    {
        return [
            'success' => false,
            'code' => 400,
            'message' => (string)__($message),
            'msg' => (string)__($message),
            'data' => [],
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'shippingInfo',
            'name' => 'Frontend shipping info worker API',
            'description' => 'Storefront shipping info lookup by location.',
            'module' => 'Weline_Shipping',
            'operations' => [
                [
                    'name' => 'getByLocation',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 3,
                    'cache_ttl' => 30,
                    'params' => [
                        'country_code' => ['type' => 'string', 'required' => true, 'max_length' => 8],
                        'province' => ['type' => 'string', 'max_length' => 120],
                        'city' => ['type' => 'string', 'max_length' => 120],
                        'district' => ['type' => 'string', 'max_length' => 120],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get shipping info by location',
                ],
                [
                    'name' => 'listQuoteOptions',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 3,
                    'cache_ttl' => 0,
                    'params' => [
                        'address' => ['type' => 'array', 'required' => true],
                        'lines' => ['type' => 'array', 'required' => true],
                        'currency' => ['type' => 'string', 'required' => true, 'max_length' => 3],
                        'currency_precision' => ['type' => 'int', 'required' => false, 'min' => 0, 'max' => 6],
                        'config_version' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List active database-backed Shipping Quote options in minor units',
                ],
                [
                    'name' => 'quote',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => false,
                    'cost' => 3,
                    'cache_ttl' => 0,
                    'params' => [
                        'address' => ['type' => 'array', 'required' => true],
                        'lines' => ['type' => 'array', 'required' => true],
                        'currency' => ['type' => 'string', 'required' => true, 'max_length' => 3],
                        'currency_precision' => ['type' => 'int', 'required' => false, 'min' => 0, 'max' => 6],
                        'config_version' => ['type' => 'string', 'required' => false, 'max_length' => 64],
                        'service_code' => ['type' => 'string', 'required' => true, 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Create one database-backed Shipping Quote in minor units',
                ],
            ],
        ];
    }
}
