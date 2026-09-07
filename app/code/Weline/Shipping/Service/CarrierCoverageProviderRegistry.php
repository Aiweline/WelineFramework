<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Api\Carrier\CarrierCoverageProviderInterface;

/**
 * 聚合 shipping.carrier_coverage.* Provider 的默认覆盖。
 */
final class CarrierCoverageProviderRegistry
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly ?ServiceProviderRegistry $providers = null,
    ) {
    }

    /**
     * @return list<CarrierCoverageProviderInterface>
     */
    public function all(): array
    {
        $out = [];
        $seen = [];

        $default = $this->objectManager->getInstance(DefaultCarrierCoverageProvider::class);
        if ($default instanceof CarrierCoverageProviderInterface) {
            $out[] = $default;
            $seen[$default->providerCode()] = true;
        }

        $registry = $this->providers ?? $this->objectManager->getInstance(ServiceProviderRegistry::class);
        if (!$registry instanceof ServiceProviderRegistry) {
            return $out;
        }

        try {
            $map = $registry->implementationsWithPrefix('shipping.carrier_coverage.');
        } catch (\Throwable) {
            return $out;
        }
        if (!is_array($map)) {
            return $out;
        }

        foreach ($map as $key => $class) {
            if (!is_string($class) || $class === '' || !class_exists($class)) {
                continue;
            }
            try {
                $instance = $this->objectManager->getInstance($class);
            } catch (\Throwable) {
                continue;
            }
            if (!$instance instanceof CarrierCoverageProviderInterface) {
                continue;
            }
            $code = $instance->providerCode();
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = $instance;
        }

        return $out;
    }

    /**
     * @return list<array{region_type:string,country_code:string,region_id:?int,region_code:string,street_id:?int}>
     */
    public function mergedDefaultCoverage(): array
    {
        $rows = [];
        $seen = [];
        foreach ($this->all() as $provider) {
            foreach ($provider->defaultCoverage() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $type = strtolower(trim((string)($row['region_type'] ?? '')));
                $country = strtoupper(trim((string)($row['country_code'] ?? '')));
                if ($type === '' || $country === '') {
                    continue;
                }
                $regionId = (int)($row['region_id'] ?? 0);
                $regionCode = trim((string)($row['region_code'] ?? ''));
                $streetId = (int)($row['street_id'] ?? 0);
                $key = $type . '|' . $country . '|' . $regionId . '|' . strtoupper($regionCode) . '|' . $streetId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'region_type' => $type,
                    'country_code' => $country,
                    'region_id' => $regionId > 0 ? $regionId : null,
                    'region_code' => $regionCode !== '' ? $regionCode : ($type === 'country' ? $country : ''),
                    'street_id' => $streetId > 0 ? $streetId : null,
                ];
            }
        }

        return $rows;
    }
}
