<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;

/** 单测 / forTesting 用进程内会话。 */
final class InMemoryCheckoutSessionStore implements CheckoutSessionStoreInterface
{
    /** @var array<string, array{payload:array<string,mixed>,expires_at:?string,fingerprint:string,error:?array{code:string,message:string,snapshot:array<string,mixed>,at:string}}> */
    private array $rows = [];

    public function put(string $quoteToken, array $payload, ?string $expiresAt = null): void
    {
        $token = trim($quoteToken);
        if ($token === '') {
            throw new \InvalidArgumentException('checkout_session_token_empty');
        }
        $state = (string)($payload['state'] ?? \Weline\Checkout\Model\CheckoutSession::STATE_QUOTED);
        $ttl = $state === \Weline\Checkout\Model\CheckoutSession::STATE_SUBMITTED
            ? \Weline\Checkout\Model\CheckoutSession::TTL_SUBMITTED_SUCCESS_SECONDS
            : \Weline\Checkout\Model\CheckoutSession::TTL_QUOTED_SECONDS;
        if ($state === \Weline\Checkout\Model\CheckoutSession::STATE_QUOTED
            && CheckoutSessionContact::extractEmail($payload) !== ''
        ) {
            $ttl = max($ttl, \Weline\Checkout\Model\CheckoutSession::TTL_QUOTED_WITH_EMAIL_SECONDS);
        }
        $existing = $this->rows[$token] ?? null;
        $fingerprint = trim((string)($payload['cart_fingerprint'] ?? ($existing['fingerprint'] ?? '')));
        $this->rows[$token] = [
            'payload' => $payload,
            'expires_at' => $expiresAt ?? gmdate('Y-m-d H:i:s', time() + $ttl),
            'fingerprint' => $fingerprint,
            'error' => is_array($existing['error'] ?? null) ? $existing['error'] : null,
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

    public function findQuotedTokenByFingerprint(string $fingerprint): ?string
    {
        $fp = trim($fingerprint);
        if ($fp === '') {
            return null;
        }
        $bestToken = null;
        $bestAt = '';
        foreach ($this->rows as $token => $row) {
            if (($row['fingerprint'] ?? '') !== $fp) {
                continue;
            }
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $state = (string)($payload['state'] ?? \Weline\Checkout\Model\CheckoutSession::STATE_QUOTED);
            if ($state !== \Weline\Checkout\Model\CheckoutSession::STATE_QUOTED) {
                continue;
            }
            $expires = (string)($row['expires_at'] ?? '');
            if ($expires !== '' && strtotime($expires . ' UTC') !== false && strtotime($expires . ' UTC') < time()) {
                continue;
            }
            $created = (string)($payload['created_at'] ?? $expires);
            if ($bestToken === null || $created >= $bestAt) {
                $bestToken = $token;
                $bestAt = $created;
            }
        }

        return $bestToken;
    }

    public function setErrorSnapshot(string $quoteToken, string $code, string $message, array $snapshot): void
    {
        $token = trim($quoteToken);
        $code = trim($code);
        if ($token === '' || $code === '' || !isset($this->rows[$token])) {
            return;
        }
        $this->rows[$token]['error'] = [
            'code' => $code,
            'message' => mb_substr(trim($message), 0, 255),
            'snapshot' => $snapshot,
            'at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function clearErrorSnapshot(string $quoteToken): void
    {
        $token = trim($quoteToken);
        if ($token === '' || !isset($this->rows[$token])) {
            return;
        }
        $this->rows[$token]['error'] = null;
    }

    public function getErrorSnapshot(string $quoteToken): ?array
    {
        $token = trim($quoteToken);
        $error = $this->rows[$token]['error'] ?? null;

        return is_array($error) && trim((string)($error['code'] ?? '')) !== '' ? $error : null;
    }

    public function findSubmittedTokenByOrderUuid(string $orderUuid): ?string
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '') {
            return null;
        }
        $bestToken = null;
        $bestAt = '';
        foreach ($this->rows as $token => $row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $state = (string)($payload['state'] ?? '');
            if ($state !== \Weline\Checkout\Model\CheckoutSession::STATE_SUBMITTED) {
                continue;
            }
            $expires = (string)($row['expires_at'] ?? '');
            if ($expires !== '' && strtotime($expires . ' UTC') !== false && strtotime($expires . ' UTC') < time()) {
                // submitted still usable within success TTL window stored in expires_at
                continue;
            }
            $submitted = is_array($payload['submitted_result'] ?? null) ? $payload['submitted_result'] : [];
            $uuids = array_map(
                static fn(mixed $v): string => trim((string)$v),
                (array)($submitted['order_uuids'] ?? []),
            );
            if (!in_array($orderUuid, $uuids, true)) {
                continue;
            }
            $created = (string)($payload['created_at'] ?? $expires);
            if ($bestToken === null || $created >= $bestAt) {
                $bestToken = (string)$token;
                $bestAt = $created;
            }
        }

        return $bestToken;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
