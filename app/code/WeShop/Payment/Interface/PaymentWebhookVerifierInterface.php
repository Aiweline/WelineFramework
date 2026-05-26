<?php

declare(strict_types=1);

namespace WeShop\Payment\Interface;

interface PaymentWebhookVerifierInterface
{
    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $context
     */
    public function verifyWebhook(string $rawPayload, array $headers = [], array $context = []): bool;
}
