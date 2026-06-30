<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class PayableSnapshot extends AbstractPaymentData
{
    public const FIELD_PAYABLE_TYPE = 'payable_type';
    public const FIELD_PAYABLE_ID = 'payable_id';
    public const FIELD_AMOUNT_MINOR = 'amount_minor';
    public const FIELD_CURRENCY_CODE = 'currency_code';
    public const FIELD_PRECISION = 'precision';
    public const FIELD_COUNTRY_CODE = 'country_code';
    public const FIELD_LANGUAGE_CODE = 'language_code';
    public const FIELD_TIMEZONE = 'timezone';
    public const FIELD_OWNER = 'owner';
    public const FIELD_PAYER = 'payer';
    public const FIELD_VERSION = 'version';
    public const FIELD_ITEMS = 'items';
    public const FIELD_TOTALS = 'totals';

    public function getPayableType(): string
    {
        return $this->getString(self::FIELD_PAYABLE_TYPE);
    }

    public function getPayableId(): string
    {
        return $this->getString(self::FIELD_PAYABLE_ID);
    }

    public function getAmountMinor(): int
    {
        return $this->getInt(self::FIELD_AMOUNT_MINOR);
    }

    public function getCurrencyCode(): string
    {
        return strtoupper($this->getString(self::FIELD_CURRENCY_CODE));
    }

    public function getPrecision(): int
    {
        return $this->getInt(self::FIELD_PRECISION, 2);
    }

    public function getVersion(): string
    {
        return $this->getString(self::FIELD_VERSION);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        return array_values(array_filter($this->getArray(self::FIELD_ITEMS), 'is_array'));
    }
}
