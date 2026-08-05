<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

/**
 * Start payment — server freezes money/scope from PayableResolver.
 * Forbidden caller fields: amount*, currency*, merchant_account, provider_reference.
 */
final class PaymentStartCommand extends AbstractPaymentData
{
    public const FIELD_PAYABLE_TYPE = 'payable_type';
    public const FIELD_PAYABLE_ID = 'payable_id';
    public const FIELD_METHOD_CODE = 'method_code';
    public const FIELD_IDEMPOTENCY_KEY = 'idempotency_key';
    public const FIELD_REQUEST_HASH = 'request_hash';
    public const FIELD_ACTOR = 'actor';
    public const FIELD_WEBSITE_ID = 'website_id';
    public const FIELD_STORE_ID = 'store_id';
    public const FIELD_RETURN_URL = 'return_url';
    public const FIELD_ALLOWED_RETURN_URLS = 'allowed_return_urls';
    public const FIELD_ASSET_REQUESTS = 'asset_requests';

    /**
     * @param list<string> $allowedReturnUrls
     * @param list<array<string, mixed>> $assetRequests
     */
    public static function create(
        string $payableType,
        string $payableId,
        string $methodCode,
        string $idempotencyKey,
        string $requestHash,
        ?Actor $actor = null,
        int $websiteId = 0,
        int $storeId = 0,
        ?string $returnUrl = null,
        array $allowedReturnUrls = [],
        array $assetRequests = [],
    ): self {
        return self::fromArray([
            self::FIELD_PAYABLE_TYPE => $payableType,
            self::FIELD_PAYABLE_ID => $payableId,
            self::FIELD_METHOD_CODE => $methodCode,
            self::FIELD_IDEMPOTENCY_KEY => $idempotencyKey,
            self::FIELD_REQUEST_HASH => $requestHash,
            self::FIELD_ACTOR => $actor,
            self::FIELD_WEBSITE_ID => $websiteId,
            self::FIELD_STORE_ID => $storeId,
            self::FIELD_RETURN_URL => $returnUrl,
            self::FIELD_ALLOWED_RETURN_URLS => array_values($allowedReturnUrls),
            self::FIELD_ASSET_REQUESTS => array_values($assetRequests),
        ]);
    }

    public function getPayableType(): string
    {
        return strtolower(trim($this->getString(self::FIELD_PAYABLE_TYPE)));
    }

    public function getPayableId(): string
    {
        return trim($this->getString(self::FIELD_PAYABLE_ID));
    }

    public function getMethodCode(): string
    {
        return strtolower(trim($this->getString(self::FIELD_METHOD_CODE)));
    }

    public function getIdempotencyKey(): string
    {
        return trim($this->getString(self::FIELD_IDEMPOTENCY_KEY));
    }

    public function getRequestHash(): string
    {
        return trim($this->getString(self::FIELD_REQUEST_HASH));
    }

    public function getActor(): ?Actor
    {
        $actor = $this->getData(self::FIELD_ACTOR);
        if ($actor instanceof Actor) {
            return $actor;
        }

        return \is_array($actor) ? Actor::fromArray($actor) : null;
    }

    public function getWebsiteId(): int
    {
        return $this->getInt(self::FIELD_WEBSITE_ID, 0);
    }

    public function getStoreId(): int
    {
        return $this->getInt(self::FIELD_STORE_ID, 0);
    }

    public function getReturnUrl(): ?string
    {
        return $this->getNullableString(self::FIELD_RETURN_URL);
    }

    /**
     * @return list<string>
     */
    public function getAllowedReturnUrls(): array
    {
        $urls = [];
        foreach ($this->getArray(self::FIELD_ALLOWED_RETURN_URLS) as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Caller-proposed asset quantities. Payment validates policy, payer, scope,
     * conversion and payable conservation against the server snapshot.
     *
     * @return list<array<string, mixed>>
     */
    public function getAssetRequests(): array
    {
        $requests = array_values(array_filter(
            $this->getArray(self::FIELD_ASSET_REQUESTS),
            'is_array',
        ));
        usort(
            $requests,
            static fn (array $left, array $right): int => strcmp(
                implode('|', [
                    strtolower(trim((string) ($left['asset_code'] ?? ''))),
                    strtolower(trim((string) ($left['role'] ?? ''))),
                    strtolower(trim((string) ($left['source_code'] ?? ''))),
                ]),
                implode('|', [
                    strtolower(trim((string) ($right['asset_code'] ?? ''))),
                    strtolower(trim((string) ($right['role'] ?? ''))),
                    strtolower(trim((string) ($right['source_code'] ?? ''))),
                ]),
            ),
        );

        return $requests;
    }
}
