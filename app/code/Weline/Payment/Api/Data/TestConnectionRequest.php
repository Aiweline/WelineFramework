<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class TestConnectionRequest extends AbstractPaymentData
{
    public const FIELD_PROVIDER_CODE = 'provider_code';
    public const FIELD_METHOD_CODE = 'method_code';
    public const FIELD_MERCHANT_ACCOUNT = 'merchant_account';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_ENVIRONMENT = 'environment';
    public const FIELD_CONFIG = 'config';
    public const FIELD_CONTEXT = 'context';

    public function getProviderCode(): string
    {
        return $this->getString(self::FIELD_PROVIDER_CODE);
    }

    public function getMethodCode(): string
    {
        return $this->getString(self::FIELD_METHOD_CODE);
    }

    public function getMerchantAccount(): ?string
    {
        return $this->getNullableString(self::FIELD_MERCHANT_ACCOUNT);
    }

    public function getScope(): string
    {
        return $this->getString(self::FIELD_SCOPE, 'default');
    }

    public function getEnvironment(): string
    {
        return $this->getString(self::FIELD_ENVIRONMENT, 'sandbox');
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->getArray(self::FIELD_CONFIG);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->getArray(self::FIELD_CONTEXT);
    }
}
