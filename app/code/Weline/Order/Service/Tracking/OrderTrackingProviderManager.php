<?php

declare(strict_types=1);

namespace Weline\Order\Service\Tracking;

use Weline\Order\Extends\Module\Weline_Order\TrackingProvider\SystemTrackingProvider;
use Weline\Order\Interface\TrackingProviderInterface;

final class OrderTrackingProviderManager
{
    public const SYSTEM_CODE = 'system';
    public const FAKE_CODE = 'fake_carrier';

    /** @var array<string, TrackingProviderInterface>|null */
    private ?array $providersByCode = null;

    public function __construct(
        private readonly TrackingProviderScanner $scanner,
    ) {
    }

    /**
     * @return array<string, TrackingProviderInterface>
     */
    public function getProviders(bool $forceReload = false): array
    {
        if (!$forceReload && $this->providersByCode !== null) {
            return $this->providersByCode;
        }

        $map = [];
        foreach ($this->scanner->getProviderInstances($forceReload) as $provider) {
            $code = trim($provider->getCode());
            if ($code === '') {
                continue;
            }
            $map[$code] = $provider;
        }

        $this->providersByCode = $map;

        return $map;
    }

    public function getProvider(string $code, bool $forceReload = false): ?TrackingProviderInterface
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return $this->getProviders($forceReload)[$code] ?? null;
    }

    public function getSystemProvider(bool $forceReload = false): TrackingProviderInterface
    {
        $provider = $this->getProvider(self::SYSTEM_CODE, $forceReload);
        if ($provider instanceof TrackingProviderInterface) {
            return $provider;
        }

        return new SystemTrackingProvider();
    }

    /**
     * Resolve formal provider when available; otherwise system fallback.
     */
    public function resolveForShipment(
        string $trackingProviderCode,
        string $carrier,
        string $trackingNumber,
        bool $forceReload = false,
    ): TrackingProviderInterface {
        $trackingProviderCode = trim($trackingProviderCode);
        if ($trackingProviderCode !== '') {
            $provider = $this->getProvider($trackingProviderCode, $forceReload);
            if ($provider instanceof TrackingProviderInterface) {
                return $provider;
            }
        }

        $carrier = trim($carrier);
        if ($carrier !== '') {
            foreach ($this->getProviders($forceReload) as $provider) {
                if ($provider->getCode() === self::SYSTEM_CODE) {
                    continue;
                }
                $caps = $provider->getCapabilities();
                $aliases = array_map('strtolower', array_map('strval', (array) ($caps['carrier_aliases'] ?? [])));
                if ($aliases !== [] && in_array(strtolower($carrier), $aliases, true)) {
                    return $provider;
                }
                if (strcasecmp($provider->getCode(), $carrier) === 0
                    || strcasecmp($provider->getProviderCode(), $carrier) === 0) {
                    return $provider;
                }
            }
        }

        if (trim($trackingNumber) !== '' && $carrier !== '') {
            $fake = $this->getProvider(self::FAKE_CODE, $forceReload);
            if ($fake instanceof TrackingProviderInterface) {
                $caps = $fake->getCapabilities();
                if (($caps['accept_unregistered_carrier'] ?? false) === true) {
                    return $fake;
                }
            }
        }

        return $this->getSystemProvider($forceReload);
    }
}
