<?php

declare(strict_types=1);

namespace WeShop\Shipping\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Shipping\Interface\ShippingProviderInterface;
use WeShop\Shipping\Provider\DHL;
use WeShop\Shipping\Provider\FedEx;

class ShippingService
{
    public function calculateShipping(array $shippingData, string $shippingMethod): float
    {
        $method = $this->getShippingMethod($shippingMethod);
        if ($method === null || !$this->isEnabled($method)) {
            throw new \InvalidArgumentException((string) __('Unsupported shipping method: %{1}', [$shippingMethod]));
        }

        return match ((string) ($method['code'] ?? '')) {
            'free_shipping' => 0.0,
            'local_pickup', 'flat_rate' => (float) ($method['price'] ?? 0),
            default => $this->calculateByProvider($shippingMethod, $shippingData),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCheckoutShippingMethods(array $context = []): array
    {
        $methods = [];
        foreach ($this->getMethodRegistry() as $method) {
            $method = $this->normalizeMethod($method);
            $method = $this->applyRuntimeOverrides($method);
            if (!$this->isEnabled($method)) {
                continue;
            }
            if (!$this->matchesContext($method, $context)) {
                continue;
            }
            $methods[] = $method;
        }

        usort(
            $methods,
            static fn(array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0))
        );

        if ($methods !== []) {
            $hasDefault = false;
            foreach ($methods as $method) {
                if (!empty($method['is_default'])) {
                    $hasDefault = true;
                    break;
                }
            }
            if (!$hasDefault) {
                $methods[0]['is_default'] = true;
            }
        }

        return $methods;
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableShippingMethods(array $context = []): array
    {
        $result = [];
        foreach ($this->getCheckoutShippingMethods($context) as $method) {
            $code = (string) ($method['code'] ?? '');
            $name = (string) ($method['name'] ?? $method['title'] ?? $code);
            if ($code === '' || $name === '') {
                continue;
            }
            $result[$code] = $name;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getShippingMethod(string $code): ?array
    {
        $registry = $this->getMethodRegistry();
        if (!isset($registry[$code])) {
            return null;
        }

        return $this->applyRuntimeOverrides($this->normalizeMethod($registry[$code]));
    }

    protected function calculateByProvider(string $shippingMethod, array $shippingData): float
    {
        $provider = $this->getProvider($shippingMethod);
        if (!$provider) {
            throw new \InvalidArgumentException((string) __('Unsupported shipping method: %{1}', [$shippingMethod]));
        }

        return $provider->calculateShipping($shippingData);
    }

    protected function getProvider(string $method): ?ShippingProviderInterface
    {
        $method = strtolower(trim($method));
        $providerClass = match ($method) {
            'dhl' => DHL::class,
            'fedex' => FedEx::class,
            default => "WeShop\\Shipping\\Provider\\" . ucfirst($method),
        };

        if (!class_exists($providerClass)) {
            return null;
        }

        try {
            $provider = ObjectManager::getInstance($providerClass);
            return $provider instanceof ShippingProviderInterface ? $provider : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getMethodRegistry(): array
    {
        return [
            'flat_rate' => [
                'code' => 'flat_rate',
                'name' => (string) __('Flat Rate'),
                'description' => (string) __('Standard delivery with a fixed shipping fee.'),
                'enabled' => true,
                'is_default' => true,
                'sort_order' => 10,
                'price' => 5.00,
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
            ],
            'free_shipping' => [
                'code' => 'free_shipping',
                'name' => (string) __('Free Shipping'),
                'description' => (string) __('Free delivery for eligible orders.'),
                'enabled' => true,
                'is_default' => false,
                'sort_order' => 20,
                'price' => 0.00,
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
            ],
            'local_pickup' => [
                'code' => 'local_pickup',
                'name' => (string) __('Local Pickup'),
                'description' => (string) __('Pick up your order at the selected local location.'),
                'enabled' => true,
                'is_default' => false,
                'sort_order' => 30,
                'price' => 0.00,
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
            ],
            'dhl' => [
                'code' => 'dhl',
                'name' => (string) __('DHL'),
                'description' => (string) __('DHL carrier integration (sandbox-ready, disabled by default).'),
                'enabled' => false,
                'is_default' => false,
                'sort_order' => 40,
                'price' => 0.00,
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
            ],
            'fedex' => [
                'code' => 'fedex',
                'name' => (string) __('FedEx'),
                'description' => (string) __('FedEx carrier integration (sandbox-ready, disabled by default).'),
                'enabled' => false,
                'is_default' => false,
                'sort_order' => 50,
                'price' => 0.00,
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    protected function normalizeMethod(array $method): array
    {
        return [
            'code' => (string) ($method['code'] ?? ''),
            'name' => (string) ($method['name'] ?? $method['title'] ?? ''),
            'description' => (string) ($method['description'] ?? ''),
            'enabled' => (bool) ($method['enabled'] ?? false),
            'is_default' => (bool) ($method['is_default'] ?? false),
            'sort_order' => (int) ($method['sort_order'] ?? 0),
            'price' => (float) ($method['price'] ?? 0),
            'areas' => array_values(array_map(static fn(mixed $value): string => strtolower((string) $value), (array) ($method['areas'] ?? []))),
            'currencies' => array_values(array_map(static fn(mixed $value): string => strtoupper((string) $value), (array) ($method['currencies'] ?? []))),
            'countries' => array_values(array_map(static fn(mixed $value): string => strtoupper((string) $value), (array) ($method['countries'] ?? []))),
        ];
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    protected function applyRuntimeOverrides(array $method): array
    {
        $code = (string) ($method['code'] ?? '');
        if ($code === '') {
            return $method;
        }

        $override = $this->getMethodOverrides()[$code] ?? null;
        if (!\is_array($override)) {
            return $method;
        }

        foreach (['name', 'description'] as $key) {
            if (array_key_exists($key, $override)) {
                $method[$key] = (string) $override[$key];
            }
        }
        foreach (['enabled', 'is_default'] as $key) {
            if (array_key_exists($key, $override)) {
                $method[$key] = (bool) $override[$key];
            }
        }
        if (array_key_exists('sort_order', $override)) {
            $method['sort_order'] = (int) $override['sort_order'];
        }
        if (array_key_exists('price', $override)) {
            $method['price'] = (float) $override['price'];
        }
        foreach (['areas', 'currencies', 'countries'] as $key) {
            if (\is_array($override[$key] ?? null)) {
                $method[$key] = match ($key) {
                    'areas' => array_values(array_map(static fn(mixed $value): string => strtolower((string) $value), $override[$key])),
                    'currencies', 'countries' => array_values(array_map(static fn(mixed $value): string => strtoupper((string) $value), $override[$key])),
                    default => array_values(array_map(static fn(mixed $value): string => (string) $value, $override[$key])),
                };
            }
        }

        return $method;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getMethodOverrides(): array
    {
        try {
            $config = Env::getInstance()->getConfig('shipping.methods', []);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $method
     * @param array<string, mixed> $context
     */
    protected function matchesContext(array $method, array $context): bool
    {
        $area = strtolower((string) ($context['area'] ?? 'frontend'));
        $areas = (array) ($method['areas'] ?? []);
        if ($areas !== [] && !in_array($area, $areas, true)) {
            return false;
        }

        $currency = strtoupper((string) ($context['currency'] ?? ''));
        $currencies = (array) ($method['currencies'] ?? []);
        if ($currency !== '' && $currencies !== [] && !in_array($currency, $currencies, true)) {
            return false;
        }

        $country = strtoupper((string) ($context['country'] ?? $context['country_id'] ?? ''));
        $countries = (array) ($method['countries'] ?? []);
        if ($country !== '' && $countries !== [] && !in_array($country, $countries, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $method
     */
    protected function isEnabled(array $method): bool
    {
        return (bool) ($method['enabled'] ?? false);
    }
}
