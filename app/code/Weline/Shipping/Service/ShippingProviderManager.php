<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Interface\ShippingProviderInterface;
use Weline\Shipping\Model\Carrier;
use Weline\SystemConfig\Api\ConfigReader;

class ShippingProviderManager
{
    public const DEFAULT_PROVIDER_CODE = 'local';
    public const CONFIG_PREFIX = 'shipping/carrier/';

    /** @var array<string, ShippingProviderInterface>|null */
    private ?array $byCode = null;

    public function __construct(
        private readonly ShippingProviderScanner $scanner,
        private readonly ObjectManager $objectManager,
        private readonly ?ConfigReader $configReader = null,
    ) {
    }

    /**
     * @return array<string, ShippingProviderInterface>
     */
    public function getProvidersByCode(bool $forceReload = false): array
    {
        if ($forceReload || $this->byCode === null) {
            $map = [];
            foreach ($this->scanner->getProviderInstances($forceReload) as $provider) {
                $code = strtolower(trim($provider->getCode()));
                if ($code === '') {
                    continue;
                }
                $map[$code] = $provider;
            }
            $this->byCode = $map;
        }

        return $this->byCode;
    }

    public function getProvider(string $providerCode, bool $forceReload = false): ?ShippingProviderInterface
    {
        $code = $this->normalizeProviderCode($providerCode);
        $map = $this->getProvidersByCode($forceReload);

        return $map[$code] ?? null;
    }

    public function normalizeProviderCode(string $providerCode): string
    {
        $code = strtolower(trim($providerCode));

        return $code !== '' ? $code : self::DEFAULT_PROVIDER_CODE;
    }

    public function resolveProviderCodeFromCarrier(Carrier $carrier): string
    {
        $raw = '';
        if ($carrier->getId()) {
            $raw = (string)$carrier->getData(Carrier::schema_fields_PROVIDER_CODE);
        }

        return $this->normalizeProviderCode($raw);
    }

    public function resolveProviderForCarrier(Carrier $carrier): ?ShippingProviderInterface
    {
        return $this->getProvider($this->resolveProviderCodeFromCarrier($carrier));
    }

    /**
     * @return array<string, mixed>
     */
    public function getProviderConfig(string $providerCode): array
    {
        $code = $this->normalizeProviderCode($providerCode);
        $reader = $this->configReader
            ?? $this->objectManager->getInstance(ConfigReader::class);
        if (!$reader instanceof ConfigReader) {
            return [];
        }

        $keys = [
            'enabled',
            'user_id',
            'api_token',
            'environment',
            'default_city_id',
            'sort_order',
        ];
        $config = [];
        foreach ($keys as $key) {
            $fullKey = self::CONFIG_PREFIX . $code . '/' . $key;
            try {
                $config[$key] = $reader->get(
                    $fullKey,
                    'Weline_Shipping',
                    ConfigReader::area_BACKEND,
                    null,
                );
            } catch (\Throwable) {
                $config[$key] = null;
            }
        }

        return $config;
    }
}
