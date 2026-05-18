<?php
declare(strict_types=1);

namespace WeShop\Address\Extends\Module\Weline_Framework\Query;

use WeShop\Address\Service\AddressService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class AddressQueryProvider implements QueryProviderInterface
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly AddressService $addressService,
        private readonly Url $url
    ) {
    }

    public function getProviderName(): string
    {
        return 'address';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'list' => $this->list(),
            'default' => $this->default(),
            'save' => $this->save($params),
            'delete' => $this->delete($params),
            'setDefault' => $this->setDefault($params),
            default => throw new \InvalidArgumentException('Address query provider does not support operation: ' . $operation),
        };
    }

    private function list(): array
    {
        $customerId = $this->requireCustomerIdOrZero();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        return $this->success('Addresses loaded.', [
            'addresses' => $this->addressService->getCustomerAddresses($customerId),
        ]);
    }

    private function default(): array
    {
        $customerId = $this->requireCustomerIdOrZero();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        return $this->success('Default address loaded.', [
            'default_address' => $this->addressService->getDefaultAddress($customerId),
        ]);
    }

    private function save(array $params): array
    {
        $customerId = $this->requireCustomerIdOrZero();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $payload = $params['address'] ?? $params['form'] ?? $params;
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['customer_id'] = $customerId;
        $address = $this->addressService->saveAddress($payload);

        return $this->success('Address saved successfully.', [
            'address' => $address,
        ] + $address);
    }

    private function delete(array $params): array
    {
        $customerId = $this->requireCustomerIdOrZero();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $addressId = $this->readAddressId($params);
        if ($addressId <= 0) {
            return $this->failure('Address ID is required.');
        }

        $deleted = $this->addressService->deleteAddress($addressId, $customerId);
        if (!$deleted) {
            return $this->failure('Address could not be removed.');
        }

        return $this->success('Address removed successfully.', [
            'address_count' => count($this->addressService->getCustomerAddresses($customerId)),
        ]);
    }

    private function setDefault(array $params): array
    {
        $customerId = $this->requireCustomerIdOrZero();
        if ($customerId <= 0) {
            return $this->loginRequired();
        }

        $addressId = $this->readAddressId($params);
        if ($addressId <= 0) {
            return $this->failure('Address ID is required.');
        }

        $this->addressService->setDefaultAddress($addressId, $customerId);
        return $this->success('Default address updated.', [
            'default_address' => $this->addressService->getDefaultAddress($customerId),
        ]);
    }

    private function readAddressId(array $params): int
    {
        return (int)($params['address_id'] ?? $params['id'] ?? $params['delivery_address_id'] ?? 0);
    }

    private function requireCustomerIdOrZero(): int
    {
        return (int)($this->customerContext->getUserId() ?? 0);
    }

    private function loginRequired(): array
    {
        return $this->failure('Please log in to continue.', [
            'redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE),
        ]);
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
            'provider' => 'address',
            'name' => 'Frontend address worker API',
            'description' => 'Customer address book operations for storefront UI.',
            'module' => 'WeShop_Address',
            'operations' => [
                [
                    'name' => 'list',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 5,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'List signed-in customer addresses',
                ],
                [
                    'name' => 'default',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 5,
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get signed-in customer default address',
                ],
                [
                    'name' => 'save',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => [
                        'form' => ['type' => 'map'],
                        'address' => ['type' => 'map'],
                        'address_id' => ['type' => 'int', 'min' => 0],
                        'delivery_address_id' => ['type' => 'int', 'min' => 0],
                        'firstname' => $string,
                        'lastname' => $string,
                        'telephone' => $string,
                        'phone' => $string,
                        'contact_name' => $string,
                        'contact_phone' => $string,
                        'country_id' => $string,
                        'country' => $string,
                        'region' => $string,
                        'province' => $string,
                        'city' => $string,
                        'district' => $string,
                        'street' => ['type' => 'string', 'max_length' => 512],
                        'postcode' => $string,
                        'postal_code' => $string,
                        'is_default' => ['type' => 'mixed'],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Create or update customer address',
                ],
                [
                    'name' => 'delete',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'address_id' => ['type' => 'int', 'min' => 1],
                        'id' => ['type' => 'int', 'min' => 1],
                        'delivery_address_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Delete customer address',
                ],
                [
                    'name' => 'setDefault',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'params' => [
                        'address_id' => ['type' => 'int', 'min' => 1],
                        'id' => ['type' => 'int', 'min' => 1],
                        'delivery_address_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Set default customer address',
                ],
            ],
        ];
    }
}
