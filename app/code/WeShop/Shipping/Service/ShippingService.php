<?php

declare(strict_types=1);

namespace WeShop\Shipping\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Service\ShippingServiceManager as FrameworkShippingServiceManager;
use WeShop\Shipping\Interface\ShippingProviderInterface;
use WeShop\Shipping\Provider\DHL;
use WeShop\Shipping\Provider\FedEx;

class ShippingService
{
    public function __construct(
        private readonly ?FrameworkShippingServiceManager $frameworkShippingManager = null
    ) {
    }

    public function calculateShipping(array $shippingData, string $shippingMethod): float
    {
        $frameworkFee = $this->calculateFrameworkShippingFee($shippingMethod, $shippingData);
        if ($frameworkFee !== null) {
            return $frameworkFee;
        }

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
        $frameworkMethods = $this->getFrameworkCheckoutShippingMethods($context);
        if ($frameworkMethods !== []) {
            return $frameworkMethods;
        }

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
                'name' => (string) __('固定运费'),
                'description' => (string) __('按固定运费配送。'),
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
                'name' => (string) __('免运费'),
                'description' => (string) __('符合条件的订单免费配送。'),
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
                'name' => (string) __('到店自提'),
                'description' => (string) __('到选择的本地门店自提订单。'),
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
                'description' => (string) __('DHL 承运商集成（沙箱可用，默认关闭）。'),
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
                'description' => (string) __('FedEx 承运商集成（沙箱可用，默认关闭）。'),
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

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    protected function getFrameworkCheckoutShippingMethods(array $context): array
    {
        $services = $this->getFrameworkServices($context);
        if ($services === []) {
            return [];
        }

        $methods = [];
        foreach ($services as $index => $service) {
            $code = trim((string) ($service['service_code'] ?? ''));
            $name = trim((string) ($service['service_name'] ?? $code));
            if ($code === '' || $name === '') {
                continue;
            }

            $methods[] = [
                'code' => $code,
                'name' => $name,
                'description' => $this->buildFrameworkMethodDescription($service),
                'enabled' => true,
                'is_default' => $index === 0,
                'sort_order' => ($index + 1) * 10,
                'price' => 0.0,
                'areas' => ['frontend', 'backend', 'api'],
                'currencies' => [],
                'countries' => [],
                'source' => 'weline_shipping',
                'service_id' => (int) ($service['service_id'] ?? 0),
            ];
        }

        return $methods;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function calculateFrameworkShippingFee(string $shippingMethod, array $context): ?float
    {
        foreach ($this->getFrameworkServices($context) as $service) {
            if (trim((string) ($service['service_code'] ?? '')) !== $shippingMethod) {
                continue;
            }

            $serviceId = (int) ($service['service_id'] ?? 0);
            if ($serviceId <= 0 || $this->frameworkShippingManager === null) {
                return null;
            }

            [$subtotal, $weight, $volume, $quantity, $memberLevelId, $regionId, $couponCode] = $this->extractFrameworkQuoteMetrics($context);

            try {
                $result = $this->frameworkShippingManager->calculateShippingFee(
                    $serviceId,
                    $subtotal,
                    $weight,
                    $volume,
                    $quantity,
                    $memberLevelId,
                    $regionId,
                    $couponCode
                );
            } catch (\Throwable) {
                return null;
            }

            if (!\is_array($result) || !isset($result['fee']) || !is_numeric($result['fee'])) {
                return 0.0;
            }

            return round(max(0.0, (float) $result['fee']), 2);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    protected function getFrameworkServices(array $context): array
    {
        if ($this->frameworkShippingManager === null) {
            return [];
        }

        $address = $this->extractFrameworkAddress($context);
        $country = $address['country'];
        if ($country === '') {
            return [];
        }

        try {
            $services = $this->frameworkShippingManager->getAvailableServices(
                $country,
                $address['province'] !== '' ? $address['province'] : null,
                $address['city'] !== '' ? $address['city'] : null,
                $address['district'] !== '' ? $address['district'] : null
            );
        } catch (\Throwable) {
            return [];
        }

        if (!\is_array($services)) {
            return [];
        }

        return array_values(array_filter($services, static fn(mixed $service): bool => \is_array($service)));
    }

    /**
     * @param array<string, mixed> $service
     */
    protected function buildFrameworkMethodDescription(array $service): string
    {
        $parts = [];
        $estimatedDays = $this->formatEstimatedDays(
            (int) ($service['estimated_days_min'] ?? 0),
            (int) ($service['estimated_days_max'] ?? 0)
        );
        if ($estimatedDays !== '') {
            $parts[] = (string) __('预计送达：%{1}。', [$estimatedDays]);
        }

        if (!empty($service['is_free_shipping'])) {
            $parts[] = (string) __('该服务支持免运费。');
        }

        if ($parts === []) {
            $serviceName = (string) ($service['service_name'] ?? $service['service_code'] ?? __('配送服务'));
            return (string) __('由 %{1} 提供配送服务。', [$serviceName]);
        }

        return implode(' ', $parts);
    }

    protected function formatEstimatedDays(int $minDays, int $maxDays): string
    {
        $minDays = max(0, $minDays);
        $maxDays = max(0, $maxDays);

        if ($minDays === 0 && $maxDays === 0) {
            return '';
        }

        if ($minDays > 0 && $maxDays > 0 && $minDays !== $maxDays) {
            return (string) __('%{1}-%{2} 天', [$minDays, $maxDays]);
        }

        $days = max($minDays, $maxDays);
        if ($days <= 0) {
            return '';
        }

        return (string) __('%{1} 天', [$days]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array{country: string, province: string, city: string, district: string}
     */
    protected function extractFrameworkAddress(array $context): array
    {
        $address = [];
        foreach (['address', 'shipping_address', 'shipping'] as $key) {
            if (\is_array($context[$key] ?? null)) {
                $address = $context[$key];
                break;
            }
        }

        if ($address === []) {
            $address = $context;
        }

        return [
            'country' => strtoupper(trim((string) ($address['country'] ?? $address['country_id'] ?? $address['country_code'] ?? ''))),
            'province' => trim((string) ($address['province'] ?? $address['region'] ?? $address['state'] ?? '')),
            'city' => trim((string) ($address['city'] ?? '')),
            'district' => trim((string) ($address['district'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{0: float, 1: float, 2: float, 3: int, 4: ?int, 5: ?int, 6: ?string}
     */
    protected function extractFrameworkQuoteMetrics(array $context): array
    {
        $subtotal = max(0.0, (float) ($context['subtotal'] ?? 0.0));
        $weight = max(0.0, (float) ($context['weight'] ?? 0.0));
        $volume = max(0.0, (float) ($context['volume'] ?? 0.0));
        $quantity = max(1, (int) ($context['quantity'] ?? 1));

        if (\is_array($context['items'] ?? null) && $context['items'] !== []) {
            $weight = 0.0;
            $volume = 0.0;
            $quantity = 0;
            foreach ($context['items'] as $item) {
                if (!\is_array($item)) {
                    continue;
                }

                $itemQty = max(1, (int) ($item['qty'] ?? 1));
                $quantity += $itemQty;
                $weight += max(0.0, (float) ($item['weight'] ?? 0.0)) * $itemQty;
                $volume += max(0.0, (float) ($item['volume'] ?? 0.0)) * $itemQty;
            }
            $quantity = max(1, $quantity);
        }

        $memberLevelId = isset($context['member_level_id']) && is_numeric($context['member_level_id'])
            ? (int) $context['member_level_id']
            : null;
        $regionId = isset($context['region_id']) && is_numeric($context['region_id'])
            ? (int) $context['region_id']
            : null;
        $couponCode = trim((string) ($context['coupon_code'] ?? ''));

        return [
            $subtotal,
            round($weight, 4),
            round($volume, 4),
            $quantity,
            $memberLevelId,
            $regionId,
            $couponCode !== '' ? $couponCode : null,
        ];
    }
}
