<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class CallbackRequest extends AbstractPaymentData
{
    public const FIELD_PROVIDER_CODE = 'provider_code';
    public const FIELD_WEBHOOK_ENDPOINT_CODE = 'webhook_endpoint_code';
    public const FIELD_HEADERS = 'headers';
    public const FIELD_QUERY = 'query';
    public const FIELD_PAYLOAD = 'payload';
    public const FIELD_RAW_BODY = 'raw_body';
    public const FIELD_SIGNATURE = 'signature';
    public const FIELD_RECEIVED_AT = 'received_at';

    public function getProviderCode(): string
    {
        return $this->getString(self::FIELD_PROVIDER_CODE);
    }

    public function getWebhookEndpointCode(): ?string
    {
        return $this->getNullableString(self::FIELD_WEBHOOK_ENDPOINT_CODE);
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
    public function getPayload(): array
    {
        return $this->getArray(self::FIELD_PAYLOAD);
    }

    public function getRawBody(): string
    {
        return $this->getString(self::FIELD_RAW_BODY);
    }

    public function getSignature(): ?string
    {
        return $this->getNullableString(self::FIELD_SIGNATURE);
    }
}
