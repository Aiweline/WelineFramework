<?php
declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Shipping\Service\AddressFormatter;
use Weline\Shipping\Service\ShippingAddressService;

class ShippingAddressQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SessionFactory $sessionFactory,
        private readonly ShippingAddressService $shippingAddressService,
        private readonly AddressFormatter $addressFormatter
    ) {
    }

    public function getProviderName(): string
    {
        return 'shippingAddress';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'save' => $this->save($params),
            'delete' => $this->delete($params),
            'setDefault' => $this->setDefault($params),
            default => throw new \InvalidArgumentException('Shipping address query provider does not support operation: ' . $operation),
        };
    }

    private function save(array $params): array
    {
        if (!$this->isLoggedIn()) {
            return $this->failure('Please log in to continue.');
        }

        $payload = $params['address'] ?? $params['form'] ?? $params;
        if (!is_array($payload)) {
            $payload = [];
        }

        $id = (int)($payload['shipping_address_id'] ?? $payload['id'] ?? 0);
        $address = $id > 0
            ? $this->shippingAddressService->update($id, $payload)
            : $this->shippingAddressService->create($payload);
        $data = $this->addressFormatter->toPayload($address->getData());
        $data['id'] = (int)$address->getId();
        $data['shipping_address_id'] = (int)$address->getId();

        return $this->success('Shipping address saved.', $data);
    }

    private function delete(array $params): array
    {
        if (!$this->isLoggedIn()) {
            return $this->failure('Please log in to continue.');
        }

        $id = $this->readAddressId($params);
        if ($id <= 0) {
            return $this->failure('Shipping address ID is required.');
        }

        $this->shippingAddressService->delete($id);
        return $this->success('Shipping address removed.');
    }

    private function setDefault(array $params): array
    {
        if (!$this->isLoggedIn()) {
            return $this->failure('Please log in to continue.');
        }

        $id = $this->readAddressId($params);
        if ($id <= 0) {
            return $this->failure('Shipping address ID is required.');
        }

        $address = $this->shippingAddressService->setDefault($id);
        $data = $this->addressFormatter->toPayload($address->getData());
        $data['id'] = (int)$address->getId();
        $data['shipping_address_id'] = (int)$address->getId();

        return $this->success('Shipping address default updated.', $data);
    }

    private function readAddressId(array $params): int
    {
        $payload = $params['address'] ?? $params['form'] ?? $params;
        if (!is_array($payload)) {
            $payload = [];
        }

        return (int)($payload['shipping_address_id'] ?? $payload['address_id'] ?? $payload['id'] ?? 0);
    }

    private function isLoggedIn(): bool
    {
        $frontendSession = $this->sessionFactory->createFrontendSession();
        return $frontendSession->isLoggedIn() || (int)($frontendSession->getUserId() ?? 0) > 0;
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
        $addressParams = [
            'form' => ['type' => 'map'],
            'address' => ['type' => 'map'],
            'id' => ['type' => 'int', 'min' => 0],
            'address_id' => ['type' => 'int', 'min' => 0],
            'shipping_address_id' => ['type' => 'int', 'min' => 0],
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

        return [
            'provider' => 'shippingAddress',
            'name' => 'Frontend shipping address worker API',
            'description' => 'Store shipping address operations for signed-in frontend account UI.',
            'module' => 'Weline_Shipping',
            'operations' => [
                [
                    'name' => 'save',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'params' => $addressParams,
                    'returns' => ['type' => 'array'],
                    'summary' => 'Create or update shipping address',
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
                        'shipping_address_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Delete shipping address',
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
                        'shipping_address_id' => ['type' => 'int', 'min' => 1],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Set default shipping address',
                ],
            ],
        ];
    }
}
