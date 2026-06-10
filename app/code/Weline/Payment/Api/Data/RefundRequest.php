<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class RefundRequest extends PaymentOperationRequest
{
    public const FIELD_REFUND_CODE = 'refund_code';
    public const FIELD_TRANSACTION_CODE = 'transaction_code';
    public const FIELD_REASON_CODE = 'reason_code';
    public const FIELD_REASON_TEXT = 'reason_text';

    public function getRefundCode(): ?string
    {
        return $this->getNullableString(self::FIELD_REFUND_CODE);
    }

    public function getTransactionCode(): ?string
    {
        return $this->getNullableString(self::FIELD_TRANSACTION_CODE);
    }

    public function getReasonCode(): ?string
    {
        return $this->getNullableString(self::FIELD_REASON_CODE);
    }

    public function getReasonText(): ?string
    {
        return $this->getNullableString(self::FIELD_REASON_TEXT);
    }
}
