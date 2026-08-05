<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Webhook;

interface WebhookEndpointDirectoryInterface
{
    public function resolveActive(string $endpointCode): ?WebhookEndpointRecord;

    /**
     * @return list<array{secret_version:string,secret_ref:string,status:string,valid_from:int,valid_until:int}>
     */
    public function resolveVerificationSecrets(WebhookEndpointRecord $record, int $receivedAt): array;

    /**
     * Resolve an opaque secret_ref only inside the server verification boundary.
     * Plaintext refs must fail closed in production.
     */
    public function resolveSecretMaterial(string $secretRef): ?string;
}
