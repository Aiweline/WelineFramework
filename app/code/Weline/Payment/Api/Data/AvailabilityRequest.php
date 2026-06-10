<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class AvailabilityRequest extends AbstractPaymentData
{
    public const FIELD_PAYABLE_TYPE = 'payable_type';
    public const FIELD_PAYABLE_ID = 'payable_id';
    public const FIELD_METHOD_CODE = 'method_code';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_AMOUNT_MINOR = 'amount_minor';
    public const FIELD_CURRENCY_CODE = 'currency_code';
    public const FIELD_COUNTRY_CODE = 'country_code';
    public const FIELD_LANGUAGE_CODE = 'language_code';
    public const FIELD_BUSINESS_TAGS = 'business_tags';
    public const FIELD_CONTEXT = 'context';

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

    public function getCountryCode(): ?string
    {
        $countryCode = $this->getNullableString(self::FIELD_COUNTRY_CODE);

        return $countryCode === null ? null : strtoupper($countryCode);
    }

    /**
     * @return string[]
     */
    public function getBusinessTags(): array
    {
        return array_values(array_filter($this->getArray(self::FIELD_BUSINESS_TAGS), 'is_string'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->getArray(self::FIELD_CONTEXT);
    }
}
