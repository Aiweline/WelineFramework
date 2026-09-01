<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data\Tracking;

final class TrackingFeedbackRequest extends AbstractTrackingData
{
    public const FIELD_ENDPOINT_CODE = 'endpoint_code';
    public const FIELD_METHOD_CODE = 'method_code';
    public const FIELD_PROVIDER_CODE = 'provider_code';
    public const FIELD_RAW_BODY = 'raw_body';
    public const FIELD_HEADERS = 'headers';
    public const FIELD_QUERY = 'query';
    public const FIELD_CONTEXT = 'context';

    public function getEndpointCode(): string
    {
        return $this->getString(self::FIELD_ENDPOINT_CODE);
    }

    public function getMethodCode(): string
    {
        return $this->getString(self::FIELD_METHOD_CODE);
    }

    public function getProviderCode(): string
    {
        return $this->getString(self::FIELD_PROVIDER_CODE);
    }

    public function getRawBody(): string
    {
        return $this->getString(self::FIELD_RAW_BODY);
    }

    /**
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->getArray(self::FIELD_HEADERS);
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuery(): array
    {
        return $this->getArray(self::FIELD_QUERY);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->getArray(self::FIELD_CONTEXT);
    }
}
