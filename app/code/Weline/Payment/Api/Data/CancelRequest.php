<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class CancelRequest extends PaymentOperationRequest
{
    public const FIELD_TOKEN = 'token';
    public const FIELD_CANCEL_REASON = 'cancel_reason';

    public function getToken(): ?string
    {
        return $this->getNullableString(self::FIELD_TOKEN);
    }

    public function getCancelReason(): ?string
    {
        return $this->getNullableString(self::FIELD_CANCEL_REASON);
    }
}
