<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class QueryRequest extends PaymentOperationRequest
{
    public const FIELD_QUERY_TYPE = 'query_type';

    public function getQueryType(): string
    {
        return $this->getString(self::FIELD_QUERY_TYPE, 'payment');
    }
}
