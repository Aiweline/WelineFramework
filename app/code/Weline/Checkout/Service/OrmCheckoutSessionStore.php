<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Checkout\Api\CheckoutSessionStoreInterface;
use Weline\Checkout\Model\CheckoutSession;

/**
 * ORM 实现：表 weline_checkout_session（跨 Worker 共享）。
 */
final class OrmCheckoutSessionStore implements CheckoutSessionStoreInterface
{
    public function __construct(
        private readonly CheckoutSession $model = new CheckoutSession(),
    ) {
    }

    public function put(string $quoteToken, array $payload, ?string $expiresAt = null): void
    {
        $token = trim($quoteToken);
        if ($token === '') {
            throw new \InvalidArgumentException('checkout_session_token_empty');
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('checkout_session_payload_encode_failed');
        }

        $row = $this->findModel($token);
        $now = gmdate('Y-m-d H:i:s');
        if (!$row->getId()) {
            $row->setData(CheckoutSession::schema_fields_CREATED_AT, $now);
        }
        $state = (string)($payload['state'] ?? CheckoutSession::STATE_QUOTED);
        $row->setData([
            CheckoutSession::schema_fields_QUOTE_TOKEN => $token,
            CheckoutSession::schema_fields_REQUEST_HASH => (string)($payload['request_hash'] ?? ''),
            CheckoutSession::schema_fields_CURRENCY => (string)($payload['currency'] ?? 'CNY'),
            CheckoutSession::schema_fields_CONFIG_VERSION => (string)($payload['config_version'] ?? '1'),
            CheckoutSession::schema_fields_STATE => $state,
            CheckoutSession::schema_fields_IDEMPOTENCY_KEY => $payload['idempotency_key'] ?? null,
            CheckoutSession::schema_fields_SUBMITTED_RESULT_JSON => $this->encodeSubmittedResult(
                $payload['submitted_result'] ?? null,
            ),
            CheckoutSession::schema_fields_PAYLOAD_JSON => $json,
            CheckoutSession::schema_fields_EXPIRES_AT => $expiresAt ?? $this->defaultExpiresAt($state),
        ])->save();
    }

    public function get(string $quoteToken): ?array
    {
        $token = trim($quoteToken);
        if ($token === '') {
            return null;
        }
        $row = $this->findModel($token);
        if (!$row->getId()) {
            return null;
        }
        $state = (string)$row->getData(CheckoutSession::schema_fields_STATE);
        $expires = (string)$row->getData(CheckoutSession::schema_fields_EXPIRES_AT);
        $expired = $expires !== ''
            && strtotime($expires . ' UTC') !== false
            && strtotime($expires . ' UTC') < time();
        if ($expired && !$this->withinSubmittedSuccessGrace($row, $state)) {
            $this->delete($token);

            return null;
        }
        $raw = (string)$row->getData(CheckoutSession::schema_fields_PAYLOAD_JSON);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function getForUpdate(string $quoteToken): ?array
    {
        $token = trim($quoteToken);
        if ($token === '') {
            return null;
        }
        $row = $this->findModel($token, true);
        if (!$row->getId()) {
            return null;
        }
        $state = (string)$row->getData(CheckoutSession::schema_fields_STATE);
        $expires = (string)$row->getData(CheckoutSession::schema_fields_EXPIRES_AT);
        $expired = $expires !== ''
            && strtotime($expires . ' UTC') !== false
            && strtotime($expires . ' UTC') < time();
        if ($expired && !$this->withinSubmittedSuccessGrace($row, $state)) {
            return null;
        }
        $decoded = json_decode((string)$row->getData(CheckoutSession::schema_fields_PAYLOAD_JSON), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function delete(string $quoteToken): bool
    {
        $token = trim($quoteToken);
        if ($token === '') {
            return false;
        }
        $row = $this->findModel($token);
        $id = (int)$row->getId();
        if ($id <= 0) {
            return false;
        }
        $deleter = clone $this->model;
        $deleter->clear()
            ->where(CheckoutSession::schema_fields_ID, $id)
            ->delete();

        return true;
    }

    private function findModel(string $quoteToken, bool $lockingRead = false): CheckoutSession
    {
        $model = clone $this->model;
        $model->clear();
        $model->where(CheckoutSession::schema_fields_QUOTE_TOKEN, $quoteToken);
        if ($lockingRead && $this->supportsForUpdate($model)) {
            $model->additional('FOR UPDATE');
        }
        $hit = $model->find()->fetch();

        return $hit instanceof CheckoutSession ? $hit : $model;
    }

    private function supportsForUpdate(CheckoutSession $model): bool
    {
        $type = strtolower((string)$model->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());

        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function encodeSubmittedResult(mixed $result): ?string
    {
        if (!is_array($result)) {
            return null;
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('checkout_session_result_encode_failed');
        }

        return $json;
    }

    private function defaultExpiresAt(string $state): string
    {
        $ttl = $state === CheckoutSession::STATE_SUBMITTED
            ? CheckoutSession::TTL_SUBMITTED_SUCCESS_SECONDS
            : CheckoutSession::TTL_QUOTED_SECONDS;

        return gmdate('Y-m-d H:i:s', time() + $ttl);
    }

    private function withinSubmittedSuccessGrace(CheckoutSession $row, string $state): bool
    {
        if ($state !== CheckoutSession::STATE_SUBMITTED) {
            return false;
        }
        $created = (string)$row->getData(CheckoutSession::schema_fields_CREATED_AT);
        $createdTs = $created !== '' ? strtotime($created . ' UTC') : false;
        if ($createdTs === false) {
            return false;
        }

        return ($createdTs + CheckoutSession::TTL_SUBMITTED_SUCCESS_SECONDS) >= time();
    }
}
