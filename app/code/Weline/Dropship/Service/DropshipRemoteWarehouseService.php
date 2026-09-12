<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipWarehouseProviderInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 壳编排：按 provider 调 Warehouse SPI（不碰供应商客户端）。
 */
final class DropshipRemoteWarehouseService
{
    /**
     * @param array{q?:string,limit?:int,country_code?:string} $context
     * @return list<array{value:string,label:string,country_code?:string}>
     */
    public function list(string $providerCode, array $context = []): array
    {
        $provider = $this->warehouseProvider($providerCode);
        if ($provider === null) {
            return [];
        }

        return $provider->listWarehouses($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return list<array{value:string,label:string,country_code?:string}>
     */
    public function pull(string $providerCode, array $context = []): array
    {
        $provider = $this->warehouseProvider($providerCode);
        if ($provider === null) {
            return [];
        }

        return $provider->pullWarehouses($context);
    }

    private function warehouseProvider(string $providerCode): ?DropshipWarehouseProviderInterface
    {
        $code = trim($providerCode);
        if ($code === '') {
            return null;
        }
        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $channels->registerAllProviders();
        $provider = $channels->getProvider($code);
        if (!$provider instanceof DropshipWarehouseProviderInterface) {
            return null;
        }
        $caps = $provider->getCapabilities();
        if (empty($caps['warehouse'])) {
            return null;
        }

        return $provider;
    }
}
