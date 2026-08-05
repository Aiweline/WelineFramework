<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;

/** 单测 / forTesting 用进程内会话。 */
final class InMemoryCheckoutSessionStore implements CheckoutSessionStoreInterface
{
    /** @var array<string, array{payload:array<string,mixed>,expires_at:?string}> */
    private array $rows = [];

    public function put(string $quoteToken, array $payload, ?string $expiresAt = null): void
    {
        $token = trim($quoteToken);
        if ($token === '') {
            throw new \InvalidArgumentException('checkout_session_token_empty');
        }
        $this->rows[$token] = [
            'payload' => $payload,
            'expires_at' => $expiresAt,
        ];
    }

    public function get(string $quoteToken): ?array
    {
        $token = trim($quoteToken);
        $row = $this->rows[$token] ?? null;
        if ($row === null) {
            return null;
        }
        $expires = (string)($row['expires_at'] ?? '');
        if ($expires !== '' && strtotime($expires . ' UTC') !== false && strtotime($expires . ' UTC') < time()) {
            unset($this->rows[$token]);

            return null;
        }

        return $row['payload'];
    }

    public function getForUpdate(string $quoteToken): ?array
    {
        return $this->get($quoteToken);
    }

    public function delete(string $quoteToken): bool
    {
        $token = trim($quoteToken);
        if (!isset($this->rows[$token])) {
            return false;
        }
        unset($this->rows[$token]);

        return true;
    }
}
