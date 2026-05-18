<?php
declare(strict_types=1);

namespace Weline\Location\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Location\Service\LocationService;

class LocationQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly LocationService $locationService,
        private readonly EventsManager $eventsManager
    ) {
    }

    public function getProviderName(): string
    {
        return 'location';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'ip' => $this->ip($params),
            'address' => $this->address($params),
            default => throw new \InvalidArgumentException('Location query provider does not support operation: ' . $operation),
        };
    }

    private function ip(array $params): array
    {
        try {
            return $this->success($this->locationService->getLocationByIp(
                trim((string)($params['ip'] ?? '')) ?: null
            ));
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function address(array $params): array
    {
        $latitude = $params['latitude'] ?? null;
        $longitude = $params['longitude'] ?? null;
        if (!\is_int($latitude) && !\is_float($latitude)) {
            return $this->failure('Latitude is required.');
        }
        if (!\is_int($longitude) && !\is_float($longitude)) {
            return $this->failure('Longitude is required.');
        }
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return $this->failure('Latitude or longitude is out of range.');
        }

        try {
            $eventData = [
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
                'address' => null,
            ];
            $this->eventsManager->dispatch('Weline_Location::location-to-address', $eventData);
            $event = $this->eventsManager->getEventData('Weline_Location::location-to-address');
            $address = $event ? $event->getData('address') : null;

            if (!\is_array($address) || $address === []) {
                $ipLocation = $this->locationService->getLocationByIp();
                $ipData = \is_array($ipLocation['data'] ?? null) ? $ipLocation['data'] : [];
                $address = [
                    'country' => (string)($ipData['country'] ?? ''),
                    'countryCode' => (string)($ipData['countryCode'] ?? ''),
                    'region' => (string)($ipData['region'] ?? ''),
                    'province' => (string)($ipData['region'] ?? ''),
                    'city' => (string)($ipData['city'] ?? ''),
                    'district' => (string)($ipData['district'] ?? ''),
                    'street' => (string)($ipData['street'] ?? ''),
                    'postalCode' => (string)($ipData['postalCode'] ?? ''),
                    'timezone' => (string)($ipData['timezone'] ?? ''),
                    'full_address' => $this->buildFullAddress($ipData),
                ];
            }

            $this->eventsManager->dispatch('Weline_Location::address-updated', [
                'latitude' => (float)$latitude,
                'longitude' => (float)$longitude,
                'address' => $address,
            ]);

            return $this->success($address);
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function success(array $data): array
    {
        return [
            'success' => true,
            'code' => 200,
            'data' => $data,
        ];
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

    private function buildFullAddress(array $data): string
    {
        return \implode('', \array_filter([
            (string)($data['country'] ?? ''),
            (string)($data['region'] ?? $data['province'] ?? ''),
            (string)($data['city'] ?? ''),
            (string)($data['district'] ?? ''),
            (string)($data['street'] ?? ''),
        ]));
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'location',
            'name' => 'Frontend location worker API',
            'description' => 'Storefront location lookup and reverse geocoding operations.',
            'module' => 'Weline_Location',
            'operations' => [
                [
                    'name' => 'ip',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 60,
                    'params' => [
                        'ip' => ['type' => 'string', 'max_length' => 64],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Get location by IP',
                ],
                [
                    'name' => 'address',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 2,
                    'cache_ttl' => 60,
                    'params' => [
                        'latitude' => ['type' => 'number', 'required' => true, 'min' => -90, 'max' => 90],
                        'longitude' => ['type' => 'number', 'required' => true, 'min' => -180, 'max' => 180],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Reverse geocode location',
                ],
            ],
        ];
    }
}
