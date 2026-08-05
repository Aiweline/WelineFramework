<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

use Weline\Cart\Service\CartPriceSellabilityGate as CartPriceSellabilityGateService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Public Cart sellability gate for cross-module consumers.
 */
final class CartPriceSellabilityGate
{
    private readonly CartPriceSellabilityGateService $service;

    public function __construct(?CartPriceSellabilityGateService $service = null)
    {
        $this->service = $service
            ?? ObjectManager::getInstance(CartPriceSellabilityGateService::class);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{ok:bool,error_code?:string,message?:string,detail?:array<string,mixed>}
     */
    public function assertOrAllow(array $params): array
    {
        return $this->service->assertOrAllow($params);
    }
}
