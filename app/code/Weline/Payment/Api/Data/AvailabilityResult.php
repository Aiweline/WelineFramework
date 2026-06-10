<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class AvailabilityResult extends AbstractPaymentData
{
    public const FIELD_AVAILABLE = 'available';
    public const FIELD_DISABLED_REASON_CODE = 'disabled_reason_code';
    public const FIELD_DISABLED_REASON_TEXT = 'disabled_reason_text';
    public const FIELD_SORT_WEIGHT = 'sort_weight';
    public const FIELD_REQUIRES_TERMS = 'requires_terms';
    public const FIELD_DYNAMIC_FORM_SCHEMA = 'dynamic_form_schema';
    public const FIELD_SURCHARGE = 'surcharge';

    public function isAvailable(): bool
    {
        return $this->getBool(self::FIELD_AVAILABLE);
    }

    public function getDisabledReasonCode(): ?string
    {
        return $this->getNullableString(self::FIELD_DISABLED_REASON_CODE);
    }

    public function getDisabledReasonText(): ?string
    {
        return $this->getNullableString(self::FIELD_DISABLED_REASON_TEXT);
    }

    public function getSortWeight(): int
    {
        return $this->getInt(self::FIELD_SORT_WEIGHT);
    }

    public function requiresTerms(): bool
    {
        return $this->getBool(self::FIELD_REQUIRES_TERMS);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDynamicFormSchema(): array
    {
        return $this->getArray(self::FIELD_DYNAMIC_FORM_SCHEMA);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSurcharge(): array
    {
        return $this->getArray(self::FIELD_SURCHARGE);
    }
}
