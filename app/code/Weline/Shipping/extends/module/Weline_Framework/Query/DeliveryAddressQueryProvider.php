<?php
declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\Region;
use Weline\Shipping\Model\ShippingService as ShippingServiceModel;
use Weline\Shipping\Service\AddressFormatter;
use Weline\Shipping\Service\DeliveryAddressService;
use Weline\Shipping\Service\RegionService;
use Weline\Shipping\Service\ShippingServiceManager;

class DeliveryAddressQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SessionFactory $sessionFactory,
        private readonly DeliveryAddressService $deliveryAddressService,
        private readonly AddressFormatter $addressFormatter,
        private readonly ShippingServiceManager $shippingServiceManager,
        private readonly RegionService $regionService
    ) {
    }

    public function getProviderName(): string
    {
        return 'deliveryAddress';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'save' => $this->save($params),
            'delete' => $this->delete($params),
            'setDefault' => $this->setDefault($params),
            'updateSession' => $this->updateSession($params['address'] ?? $params),
            'getSession' => $this->getSessionAddress(),
            'syncFromBrowser' => $this->syncFromBrowser($params['address'] ?? $params),
            'shippingInfoByLocation' => $this->shippingInfoByLocation($params['location'] ?? $params),
            default => throw new \InvalidArgumentException('Delivery address query provider does not support operation: ' . $operation),
        };
    }

    private function save(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->failure('Please log in to continue.');
        }

        $payload = $params['address'] ?? $params['form'] ?? $params;
        if (!is_array($payload)) {
            $payload = [];
        }

        $id = (int)($payload['delivery_address_id'] ?? $payload['id'] ?? 0);
        $address = $id > 0
            ? $this->deliveryAddressService->update($id, $payload, $customerId)
            : $this->deliveryAddressService->create($customerId, $payload);
        $data = $this->addressFormatter->toPayload($address->getData());
        $data['id'] = (int)$address->getId();
        $data['delivery_address_id'] = (int)$address->getId();

        return $this->success('Delivery address saved.', $data);
    }

    private function delete(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->failure('Please log in to continue.');
        }

        $id = $this->readAddressId($params);
        if ($id <= 0) {
            return $this->failure('Delivery address ID is required.');
        }

        $this->deliveryAddressService->delete($id, $customerId);
        return $this->success('Delivery address removed.');
    }

    private function setDefault(array $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->failure('Please log in to continue.');
        }

        $id = $this->readAddressId($params);
        if ($id <= 0) {
            return $this->failure('Delivery address ID is required.');
        }

        $address = $this->deliveryAddressService->setDefault($id, $customerId);
        $data = $this->addressFormatter->toPayload($address->getData());
        $data['id'] = (int)$address->getId();
        $data['delivery_address_id'] = (int)$address->getId();

        return $this->success('Delivery address default updated.', $data);
    }

    private function readAddressId(array $params): int
    {
        $payload = $params['address'] ?? $params['form'] ?? $params;
        if (!is_array($payload)) {
            $payload = [];
        }

        return (int)($payload['delivery_address_id'] ?? $payload['address_id'] ?? $payload['id'] ?? 0);
    }

    private function updateSession(mixed $params): array
    {
        $body = is_array($params) ? $params : [];
        foreach (['country', 'province', 'city'] as $field) {
            if (trim((string)($body[$field] ?? '')) === '') {
                return $this->failure($field . ' is required.');
            }
        }

        $deliveryAddress = $this->normalizeDeliveryAddress($body);
        $session = $this->sessionFactory->createSession();
        $session->set('shipping_delivery_address', $deliveryAddress);
        $session->save();

        $customerId = $this->getCustomerId();
        if ($customerId > 0) {
            $this->syncToDatabase($customerId, $deliveryAddress);
        }

        return $this->success('Delivery address updated.', $deliveryAddress);
    }

    private function getSessionAddress(): array
    {
        $address = $this->sessionFactory->createSession()->get('shipping_delivery_address');
        return $this->success('Delivery address loaded.', is_array($address) ? $address : []);
    }

    private function syncFromBrowser(mixed $params): array
    {
        $customerId = $this->getCustomerId();
        if ($customerId <= 0) {
            return $this->failure('Please log in to continue.');
        }
        $address = is_array($params) ? $params : [];
        if ($address === []) {
            return $this->failure('Address data is required.');
        }

        $session = $this->sessionFactory->createSession();
        $session->set('shipping_delivery_address', $address);
        $session->save();
        $this->syncToDatabase($customerId, $address);

        return $this->success('Delivery address synced.', $address);
    }

    private function shippingInfoByLocation(mixed $params): array
    {
        $body = is_array($params) ? $params : [];
        $countryCode = trim((string)($body['country_code'] ?? $body['countryCode'] ?? 'CN'));
        if ($countryCode === '') {
            return $this->failure('Country code is required.');
        }
        $province = (string)($body['province'] ?? '');
        $city = (string)($body['city'] ?? '');
        $district = (string)($body['district'] ?? '');
        $services = $this->shippingServiceManager->getAvailableServices($countryCode, $province, $city, $district);
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
                'service_name' => $service['service_name'] ?? '',
                'service_code' => $service['service_code'] ?? '',
                'carrier_id' => $service['carrier_id'] ?? null,
                'estimated_days_min' => $service['estimated_days_min'] ?? null,
                'estimated_days_max' => $service['estimated_days_max'] ?? null,
                'is_free_shipping' => $service['is_free_shipping'] ?? false,
                'price_rules' => $priceRules,
            ];
            if ($priceRules !== []) {
                $shippingInfo['price_rules'][$serviceId] = $priceRules;
            }
        }

        return $this->success('Shipping info loaded.', $shippingInfo);
    }

    private function normalizeDeliveryAddress(array $body): array
    {
        return [
            'country' => (string)($body['country'] ?? ''),
            'country_code' => (string)($body['country_code'] ?? $body['countryCode'] ?? ''),
            'province' => (string)($body['province'] ?? ''),
            'province_code' => (string)($body['province_code'] ?? ''),
            'province_region_id' => $body['province_region_id'] ?? null,
            'city' => (string)($body['city'] ?? ''),
            'city_code' => (string)($body['city_code'] ?? ''),
            'city_region_id' => $body['city_region_id'] ?? null,
            'district' => (string)($body['district'] ?? ''),
            'district_code' => (string)($body['district_code'] ?? ''),
            'district_region_id' => $body['district_region_id'] ?? null,
            'street' => (string)($body['street'] ?? ''),
            'postal_code' => (string)($body['postal_code'] ?? ''),
            'latitude' => $body['latitude'] ?? null,
            'longitude' => $body['longitude'] ?? null,
            'full_address' => $this->addressFormatter->formatSingleLine($body),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function getCustomerId(): int
    {
        $frontendSession = $this->sessionFactory->createFrontendSession();
        $userId = (int)($frontendSession->getUserId() ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        $customerData = $this->sessionFactory->createSession()->get('weshop_customer');
        return is_array($customerData) ? (int)($customerData['customer_id'] ?? 0) : 0;
    }

    private function syncToDatabase(int $customerId, array $addressData): void
    {
        try {
            $defaultAddress = $this->deliveryAddressService->getDefaultByCustomer($customerId);
            $data = [
                'country' => (string)($addressData['country'] ?? ''),
                'country_code' => (string)($addressData['country_code'] ?? ''),
                'province' => (string)($addressData['province'] ?? ''),
                'province_code' => (string)($addressData['province_code'] ?? ''),
                'province_region_id' => $addressData['province_region_id'] ?? null,
                'city' => (string)($addressData['city'] ?? ''),
                'city_code' => (string)($addressData['city_code'] ?? ''),
                'city_region_id' => $addressData['city_region_id'] ?? null,
                'district' => (string)($addressData['district'] ?? ''),
                'district_code' => (string)($addressData['district_code'] ?? ''),
                'district_region_id' => $addressData['district_region_id'] ?? null,
                'street' => (string)($addressData['street'] ?? ''),
                'postal_code' => (string)($addressData['postal_code'] ?? ''),
            ];

            if ($defaultAddress) {
                $this->deliveryAddressService->update((int)$defaultAddress->getId(), $data, $customerId);
                return;
            }

            $this->deliveryAddressService->create($customerId, [
                'name' => 'Auto delivery address',
                'contact_name' => '',
                'contact_phone' => '',
                'country' => $data['country'] ?: 'CN',
                'province' => $data['province'],
                'city' => $data['city'],
                'district' => $data['district'],
                'street' => $data['street'],
                'postal_code' => $data['postal_code'],
                'is_default' => 1,
                'is_enabled' => 1,
            ]);
        } catch (\Throwable $throwable) {
            if (function_exists('w_log_error')) {
                w_log_error('DeliveryAddressQueryProvider sync error: ' . $throwable->getMessage());
            }
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

    private function success(string $message, array $data = []): array
    {
        return ['success' => true, 'message' => $message, 'data' => $data] + $data;
    }

    private function failure(string $message, array $data = []): array
    {
        return ['success' => false, 'message' => $message, 'data' => $data] + $data;
    }

    public function getDescriptor(): array
    {
        $string = ['type' => 'string', 'max_length' => 255];
        return [
            'provider' => 'deliveryAddress',
            'name' => 'Frontend delivery address worker API',
            'description' => 'Session delivery address and shipping info operations.',
            'module' => 'Weline_Shipping',
            'operations' => [
                [
                    'name' => 'save',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => ['form' => ['type' => 'map'], 'address' => ['type' => 'map']] + $this->addressBookParamRules($string),
                    'returns' => ['type' => 'array'],
                    'summary' => 'Create or update signed-in customer delivery address',
                ],
                [
                    'name' => 'delete',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'id' => ['type' => 'int', 'min' => 1],
                        'address_id' => ['type' => 'int', 'min' => 1],
                        'delivery_address_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Delete signed-in customer delivery address',
                ],
                [
                    'name' => 'setDefault',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'id' => ['type' => 'int', 'min' => 1],
                        'address_id' => ['type' => 'int', 'min' => 1],
                        'delivery_address_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Set signed-in customer default delivery address',
                ],
                [
                    'name' => 'updateSession',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => ['address' => ['type' => 'map']] + $this->addressParamRules($string),
                    'returns' => ['type' => 'array'],
                    'summary' => 'Update current session delivery address',
                ],
                [
                    'name' => 'getSession',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get current session delivery address',
                ],
                [
                    'name' => 'syncFromBrowser',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => ['address' => ['type' => 'map']] + $this->addressParamRules($string),
                    'returns' => ['type' => 'array'],
                    'summary' => 'Sync browser delivery address to session and account',
                ],
                [
                    'name' => 'shippingInfoByLocation',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 3,
                    'cache_ttl' => 10,
                    'params' => [
                        'location' => ['type' => 'map'],
                        'country_code' => ['type' => 'string', 'max_length' => 8],
                        'countryCode' => ['type' => 'string', 'max_length' => 8],
                        'province' => $string,
                        'city' => $string,
                        'district' => $string,
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get shipping services and price rules for location',
                ],
            ],
        ];
    }

    private function addressParamRules(array $string): array
    {
        return [
            'country' => $string,
            'country_code' => ['type' => 'string', 'max_length' => 8],
            'countryCode' => ['type' => 'string', 'max_length' => 8],
            'province' => $string,
            'province_code' => $string,
            'province_region_id' => ['type' => 'mixed'],
            'city' => $string,
            'city_code' => $string,
            'city_region_id' => ['type' => 'mixed'],
            'district' => $string,
            'district_code' => $string,
            'district_region_id' => ['type' => 'mixed'],
            'street' => ['type' => 'string', 'max_length' => 512],
            'postal_code' => $string,
            'latitude' => ['type' => 'mixed'],
            'longitude' => ['type' => 'mixed'],
        ];
    }

    private function addressBookParamRules(array $string): array
    {
        return [
            'id' => ['type' => 'int', 'min' => 0],
            'address_id' => ['type' => 'int', 'min' => 0],
            'delivery_address_id' => ['type' => 'int', 'min' => 0],
            'name' => $string,
            'contact_name' => $string,
            'contact_phone' => $string,
            'country' => $string,
            'country_code' => ['type' => 'string', 'max_length' => 8],
            'province' => $string,
            'province_code' => $string,
            'province_region_id' => ['type' => 'mixed'],
            'city' => $string,
            'city_code' => $string,
            'city_region_id' => ['type' => 'mixed'],
            'district' => $string,
            'district_code' => $string,
            'district_region_id' => ['type' => 'mixed'],
            'street' => ['type' => 'string', 'max_length' => 512],
            'postal_code' => $string,
            'is_default' => ['type' => 'mixed'],
            'is_enabled' => ['type' => 'mixed'],
        ];
    }
}
