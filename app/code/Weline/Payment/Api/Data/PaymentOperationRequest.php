<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

class PaymentOperationRequest extends AbstractPaymentData
{
    public const FIELD_INTENT_CODE = 'intent_code';
    public const FIELD_ATTEMPT_CODE = 'attempt_code';
    public const FIELD_PAYABLE_TYPE = 'payable_type';
    public const FIELD_PAYABLE_ID = 'payable_id';
    public const FIELD_METHOD_CODE = 'method_code';
    public const FIELD_PROVIDER_CODE = 'provider_code';
    public const FIELD_MERCHANT_ACCOUNT = 'merchant_account';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_AMOUNT_MINOR = 'amount_minor';
    public const FIELD_CURRENCY_CODE = 'currency_code';
    public const FIELD_IDEMPOTENCY_KEY = 'idempotency_key';
    public const FIELD_PROVIDER_REFERENCE = 'provider_reference';
    public const FIELD_DYNAMIC_FORM_VALUES = 'dynamic_form_values';
    public const FIELD_RETURN_URL = 'return_url';
    public const FIELD_CANCEL_URL = 'cancel_url';
    public const FIELD_FAILURE_URL = 'failure_url';
    public const FIELD_CONTEXT = 'context';

    public function getIntentCode(): string
    {
        return $this->getString(self::FIELD_INTENT_CODE);
    }

    public function getAttemptCode(): ?string
    {
        return $this->getNullableString(self::FIELD_ATTEMPT_CODE);
    }

    public function getPayableType(): string
    {
        return $this->getString(self::FIELD_PAYABLE_TYPE);
    }

    public function getPayableId(): string
    {
        return $this->getString(self::FIELD_PAYABLE_ID);
    }

    public function getMethodCode(): string
    {
        return $this->getString(self::FIELD_METHOD_CODE);
    }

    public function getProviderCode(): ?string
    {
        return $this->getNullableString(self::FIELD_PROVIDER_CODE);
    }

    public function getMerchantAccount(): ?string
    {
        return $this->getNullableString(self::FIELD_MERCHANT_ACCOUNT);
    }

    public function getScope(): string
    {
        return $this->getString(self::FIELD_SCOPE, 'default.default.default');
    }

    public function getAmountMinor(): int
    {
        return $this->getInt(self::FIELD_AMOUNT_MINOR);
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->getString(self::FIELD_CURRENCY_CODE));
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->getNullableString(self::FIELD_IDEMPOTENCY_KEY);
    }

    public function getProviderReference(): ?string
    {
        return $this->getNullableString(self::FIELD_PROVIDER_REFERENCE);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDynamicFormValues(): array
    {
        return $this->getArray(self::FIELD_DYNAMIC_FORM_VALUES);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->getArray(self::FIELD_CONTEXT);
    }
}
