<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CartPriceSellabilityProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * Cart-owned gate. Catalog behavior is supplied through the public optional
 * provider contract, keeping Cart independent from Product internals.
 */
class CartPriceSellabilityGate
{
    public function __construct(
        private readonly ?CartPriceSellabilityProviderInterface $provider = null,
        private readonly ?RuntimeProviderResolver $providerResolver = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{ok:bool,error_code?:string,message?:string,detail?:array<string,mixed>}
     */
    public function assertOrAllow(array $params): array
    {
        if ($this->provider !== null) {
            return $this->invoke($this->provider, $params);
        }

        try {
            $resolver = $this->providerResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $resolution = $resolver->resolveDetailed(CartPriceSellabilityProviderInterface::class);
        } catch (\Throwable $exception) {
            return $this->unavailable(
                'cart_sellability_provider_unavailable',
                (string)__('购物车可售校验服务暂不可用：%{1}', [$exception->getMessage()]),
            );
        }

        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            return ['ok' => true];
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof CartPriceSellabilityProviderInterface
        ) {
            return $this->unavailable(
                $resolution->errorCode !== ''
                    ? $resolution->errorCode
                    : 'cart_sellability_provider_unavailable',
                $resolution->error !== ''
                    ? $resolution->error
                    : (string)__('购物车可售校验服务暂不可用'),
            );
        }

        return $this->invoke($resolution->provider, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{ok:bool,error_code?:string,message?:string,detail?:array<string,mixed>}
     */
    private function invoke(
        CartPriceSellabilityProviderInterface $provider,
        array $params,
    ): array {
        try {
            $result = $provider->assertOrAllow($params);
        } catch (\Throwable $exception) {
            return $this->unavailable(
                'cart_sellability_provider_failed',
                (string)__('购物车可售校验失败：%{1}', [$exception->getMessage()]),
            );
        }
        if (!array_key_exists('ok', $result)) {
            return $this->unavailable(
                'cart_sellability_provider_invalid_result',
                (string)__('购物车可售校验返回无效结果'),
            );
        }

        return $result;
    }

    /**
     * @return array{ok:false,error_code:string,message:string}
     */
    private function unavailable(string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
        ];
    }
}
