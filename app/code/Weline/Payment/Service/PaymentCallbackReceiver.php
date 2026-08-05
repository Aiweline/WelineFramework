<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Http\Security\SecretRefCipher;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\CallbackRequest;
use Weline\Payment\Api\Data\CallbackResult;
use Weline\Payment\Api\Webhook\WebhookEndpointRecord;
use Weline\Payment\Api\Webhook\WebhookReceiveResult;
use Weline\Payment\Interface\ProviderInterface;
use Weline\Payment\Model\PaymentWebhookInbox;

/**
 * Phase-A webhook receiver：endpoint → verify → pure parse → immutable inbox → 2xx（MOD-P2F-003）。
 * 不修改 Intent/Attempt/Order 状态（消费者归 P2F-004）。
 */
final class PaymentCallbackReceiver
{
    public const ERROR_ENDPOINT_NOT_FOUND = 'payment_webhook_endpoint_not_found';
    public const ERROR_ENDPOINT_DISABLED = 'payment_webhook_endpoint_disabled';
    public const ERROR_SIGNATURE_INVALID = 'payment_webhook_signature_invalid';
    public const ERROR_TIMESTAMP_SKEW = 'payment_webhook_timestamp_skew';
    public const ERROR_EVENT_ID_REQUIRED = 'payment_webhook_event_id_required';
    public const ERROR_PAYLOAD_CONFLICT = 'event_id_payload_conflict';
    public const ERROR_PROVIDER_REQUIRED = 'payment_webhook_provider_required';
    public const ERROR_INBOX_COMMIT_FAILED = 'payment_webhook_inbox_commit_failed';

    public const MAX_SKEW_SECONDS = 300;

    /**
     * @var array{
     *   inbox: array<string, array<string, mixed>>,
     *   by_event: array<string, string>,
     *   audit: list<array<string, mixed>>,
     *   urgent: list<array<string, mixed>>,
     *   fail_before_commit: bool
     * }
     */
    private array $memory;

    private bool $useMemory;
    private int $now;
    private ?DatabaseTransactionRunnerInterface $transactions = null;

    /** @var (\Closure(string): (?ProviderInterface))|null */
    private $providerResolver = null;

    public function __construct(
        private readonly WebhookEndpointDirectory $directory,
        private readonly ObjectManager $objectManager,
        private readonly PaymentMethodManager $methodManager,
        bool $useMemory = false,
        int $now = 0,
    ) {
        $this->useMemory = $useMemory;
        $this->now = $now > 0 ? $now : time();
        $this->memory = [
            'inbox' => [],
            'by_event' => [],
            'audit' => [],
            'urgent' => [],
            'fail_before_commit' => false,
        ];
    }

    public static function forTesting(WebhookEndpointDirectory $directory, int $now = 0): self
    {
        return new self(
            $directory,
            ObjectManager::getInstance(),
            ObjectManager::getInstance(PaymentMethodManager::class),
            useMemory: true,
            now: $now > 0 ? $now : 1_700_000_000,
        );
    }

    public function setNow(int $timestamp): void
    {
        $this->now = $timestamp;
    }

    public function setFailBeforeCommit(bool $fail): void
    {
        $this->memory['fail_before_commit'] = $fail;
    }

    /**
     * @param (\Closure(string): (?ProviderInterface)) $resolver
     */
    public function setProviderResolver(callable $resolver): void
    {
        $this->providerResolver = $resolver;
    }

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $payload
     */
    public function receive(
        string $endpointCode,
        string $rawBody,
        array $headers = [],
        array $payload = [],
        ?string $signature = null,
        ?int $providerTimestamp = null,
    ): WebhookReceiveResult {
        $receivedAt = $this->now;
        $record = $this->directory->resolveActive($endpointCode);
        if ($record === null) {
            return $this->reject(WebhookReceiveResult::HTTP_NOT_FOUND, self::ERROR_ENDPOINT_NOT_FOUND, 'endpoint missing');
        }
        if (!$record->isReceivable($receivedAt)) {
            return $this->reject(WebhookReceiveResult::HTTP_GONE, self::ERROR_ENDPOINT_DISABLED, 'endpoint disabled', [
                'endpoint_code' => $endpointCode,
            ]);
        }

        $secrets = $this->directory->resolveVerificationSecrets($record, $receivedAt);
        if ($secrets === []) {
            return $this->reject(WebhookReceiveResult::HTTP_UNAUTHORIZED, self::ERROR_SIGNATURE_INVALID, 'no verification secret', [
                'endpoint_code' => $endpointCode,
            ]);
        }

        if ($providerTimestamp !== null && abs($receivedAt - $providerTimestamp) > self::MAX_SKEW_SECONDS) {
            return $this->reject(WebhookReceiveResult::HTTP_UNAUTHORIZED, self::ERROR_TIMESTAMP_SKEW, 'timestamp skew', [
                'endpoint_code' => $endpointCode,
                'skew' => abs($receivedAt - $providerTimestamp),
            ]);
        }

        $signature = $signature ?? (string) ($headers['x-signature'] ?? $headers['X-Signature'] ?? $payload['signature'] ?? '');
        $provider = $this->resolveProvider($record);
        if ($provider === null) {
            return $this->reject(WebhookReceiveResult::HTTP_SERVER_ERROR, self::ERROR_PROVIDER_REQUIRED, 'provider missing');
        }

        $verified = false;
        $matchedSecretVersion = null;
        foreach ($secrets as $secret) {
            $material = $this->directory->resolveSecretMaterial((string) $secret['secret_ref']);
            if ($material === null) {
                continue;
            }
            $request = CallbackRequest::fromArray([
                CallbackRequest::FIELD_PROVIDER_CODE => $record->providerCode,
                CallbackRequest::FIELD_WEBHOOK_ENDPOINT_CODE => $record->endpointCode,
                CallbackRequest::FIELD_HEADERS => $headers,
                CallbackRequest::FIELD_PAYLOAD => $payload !== [] ? $payload : $this->decodePayload($rawBody),
                CallbackRequest::FIELD_RAW_BODY => $rawBody,
                CallbackRequest::FIELD_SIGNATURE => $signature,
                CallbackRequest::FIELD_RECEIVED_AT => date('Y-m-d H:i:s', $receivedAt),
                'verification_secret' => $material,
                'secret_version' => $secret['secret_version'],
                'merchant_account' => $record->merchantAccount,
                'environment' => $record->environment,
                'provider_timestamp' => $providerTimestamp,
            ]);
            $result = $provider->verifyCallback($request);
            if ($result->isVerified()) {
                $verified = true;
                $matchedSecretVersion = (string) $secret['secret_version'];
                break;
            }
        }

        if (!$verified) {
            return $this->reject(WebhookReceiveResult::HTTP_UNAUTHORIZED, self::ERROR_SIGNATURE_INVALID, 'signature failed', [
                'endpoint_code' => $endpointCode,
            ]);
        }

        $parseRequest = CallbackRequest::fromArray([
            CallbackRequest::FIELD_PROVIDER_CODE => $record->providerCode,
            CallbackRequest::FIELD_WEBHOOK_ENDPOINT_CODE => $record->endpointCode,
            CallbackRequest::FIELD_HEADERS => $headers,
            CallbackRequest::FIELD_PAYLOAD => $payload !== [] ? $payload : $this->decodePayload($rawBody),
            CallbackRequest::FIELD_RAW_BODY => $rawBody,
            CallbackRequest::FIELD_SIGNATURE => $signature,
            CallbackRequest::FIELD_RECEIVED_AT => date('Y-m-d H:i:s', $receivedAt),
            'secret_version' => $matchedSecretVersion,
        ]);
        $parsed = $provider->parseCallback($parseRequest);
        $eventId = trim((string) ($parsed->getProviderEventId() ?? ''));
        if ($eventId === '') {
            return $this->reject(WebhookReceiveResult::HTTP_BAD_REQUEST, self::ERROR_EVENT_ID_REQUIRED, 'event id required', [
                'endpoint_code' => $endpointCode,
            ]);
        }

        $schemaVersion = (string) ($parsed->getData('schema_version') ?? $record->contextVersion ?: '1');
        $payloadHash = hash('sha256', $rawBody);
        $eventKey = $record->endpointCode . '|' . $eventId;

        if (!$this->useMemory) {
            return $this->persistInbox(
                $record,
                $eventId,
                $schemaVersion,
                $payloadHash,
                $rawBody,
                $headers,
                $signature,
                (string) $matchedSecretVersion,
                $parsed,
                $receivedAt,
            );
        }

        if (isset($this->memory['by_event'][$eventKey])) {
            $existingCode = $this->memory['by_event'][$eventKey];
            $existing = $this->memory['inbox'][$existingCode];
            if (!$this->inboxMatches($existing, $record, $schemaVersion, $payloadHash)) {
                $this->memory['urgent'][] = [
                    'type' => self::ERROR_PAYLOAD_CONFLICT,
                    'endpoint_code' => $record->endpointCode,
                    'provider_event_id' => $eventId,
                    'existing_hash' => $existing['payload_hash'],
                    'new_hash' => $payloadHash,
                ];
                $this->audit('conflict', $endpointCode, self::ERROR_PAYLOAD_CONFLICT);

                return new WebhookReceiveResult(
                    httpStatus: WebhookReceiveResult::HTTP_CONFLICT,
                    body: 'event_id_payload_conflict',
                    errorCode: self::ERROR_PAYLOAD_CONFLICT,
                    inboxCode: $existingCode,
                    inboxWritten: false,
                    replayed: false,
                    audit: [['code' => self::ERROR_PAYLOAD_CONFLICT]],
                );
            }

            $this->audit('replay', $endpointCode, null, $existingCode);

            return new WebhookReceiveResult(
                httpStatus: WebhookReceiveResult::HTTP_OK,
                body: 'ok',
                inboxCode: $existingCode,
                inboxWritten: false,
                replayed: true,
            );
        }

        if ($this->memory['fail_before_commit']) {
            $this->audit('commit_failed', $endpointCode, self::ERROR_INBOX_COMMIT_FAILED);

            return new WebhookReceiveResult(
                httpStatus: WebhookReceiveResult::HTTP_SERVER_ERROR,
                body: 'retry',
                errorCode: self::ERROR_INBOX_COMMIT_FAILED,
                inboxWritten: false,
            );
        }

        $inboxCode = 'wi_' . bin2hex(random_bytes(8));
        $row = [
            'inbox_code' => $inboxCode,
            'endpoint_code' => $record->endpointCode,
            'provider_event_id' => $eventId,
            'provider_code' => $record->providerCode,
            'merchant_account' => $record->merchantAccount,
            'environment' => $record->environment,
            'schema_version' => $schemaVersion,
            'verification_secret_version' => $matchedSecretVersion,
            'payload_hash' => $payloadHash,
            'encrypted_raw_payload' => $this->encrypt($rawBody),
            'encrypted_headers' => $this->encrypt(json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'),
            'encrypted_signature' => $this->encrypt($signature),
            'status' => PaymentWebhookInbox::STATUS_RECEIVED,
            'intent_code' => $parsed->getIntentCode(),
            'event_type' => $parsed->getEventType(),
            'status_transition' => (string) ($parsed->getData(CallbackResult::FIELD_STATUS_TRANSITION) ?? ''),
            'secret_version' => $matchedSecretVersion,
            'received_at' => $receivedAt,
            'applied_at' => null,
        ];
        $this->memory['inbox'][$inboxCode] = $row;
        $this->memory['by_event'][$eventKey] = $inboxCode;
        $this->audit('received', $endpointCode, null, $inboxCode);

        // 2xx only after inbox commit.
        return new WebhookReceiveResult(
            httpStatus: WebhookReceiveResult::HTTP_OK,
            body: 'ok',
            inboxCode: $inboxCode,
            inboxWritten: true,
            replayed: false,
        );
    }

    /**
     * Persist an immutable inbox row. The Provider has already verified and
     * parsed the callback before this local transaction begins.
     *
     * @param array<string, mixed> $headers
     */
    private function persistInbox(
        WebhookEndpointRecord $record,
        string $eventId,
        string $schemaVersion,
        string $payloadHash,
        string $rawBody,
        array $headers,
        string $signature,
        string $secretVersion,
        CallbackResult $parsed,
        int $receivedAt,
    ): WebhookReceiveResult {
        $existing = $this->loadInboxByEvent($record->endpointCode, $eventId);
        if ($existing instanceof PaymentWebhookInbox) {
            return $this->existingPersistentResult(
                $existing,
                $record,
                $schemaVersion,
                $payloadHash,
            );
        }

        $inboxCode = 'wi_' . bin2hex(random_bytes(16));
        $model = $this->newModel(PaymentWebhookInbox::class);
        try {
            return $this->transactionRunner()->run(
                $model->getConnection(),
                function () use (
                    $record,
                    $eventId,
                    $schemaVersion,
                    $payloadHash,
                    $rawBody,
                    $headers,
                    $signature,
                    $secretVersion,
                    $parsed,
                    $receivedAt,
                    $inboxCode,
                ): WebhookReceiveResult {
                    $raced = $this->loadInboxByEvent($record->endpointCode, $eventId);
                    if ($raced instanceof PaymentWebhookInbox) {
                        return $this->existingPersistentResult(
                            $raced,
                            $record,
                            $schemaVersion,
                            $payloadHash,
                        );
                    }
                    if ($this->memory['fail_before_commit']) {
                        throw new \RuntimeException(self::ERROR_INBOX_COMMIT_FAILED);
                    }

                    $inbox = $this->newModel(PaymentWebhookInbox::class);
                    $inbox->setData([
                        PaymentWebhookInbox::schema_fields_INBOX_CODE => $inboxCode,
                        PaymentWebhookInbox::schema_fields_ENDPOINT_CODE => $record->endpointCode,
                        PaymentWebhookInbox::schema_fields_PROVIDER_EVENT_ID => $eventId,
                        PaymentWebhookInbox::schema_fields_PROVIDER_CODE => $record->providerCode,
                        PaymentWebhookInbox::schema_fields_MERCHANT_ACCOUNT => $record->merchantAccount,
                        PaymentWebhookInbox::schema_fields_ENVIRONMENT => $record->environment,
                        PaymentWebhookInbox::schema_fields_SCHEMA_VERSION => $schemaVersion,
                        PaymentWebhookInbox::schema_fields_VERIFICATION_SECRET_VERSION => $secretVersion,
                        PaymentWebhookInbox::schema_fields_PAYLOAD_HASH => $payloadHash,
                        PaymentWebhookInbox::schema_fields_ENCRYPTED_RAW_PAYLOAD => $this->encrypt($rawBody),
                        PaymentWebhookInbox::schema_fields_ENCRYPTED_HEADERS => $this->encrypt(
                            json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                        ),
                        PaymentWebhookInbox::schema_fields_ENCRYPTED_SIGNATURE => $this->encrypt($signature),
                        PaymentWebhookInbox::schema_fields_STATUS => PaymentWebhookInbox::STATUS_RECEIVED,
                        PaymentWebhookInbox::schema_fields_INTENT_CODE => $parsed->getIntentCode(),
                        PaymentWebhookInbox::schema_fields_ATTEMPT_CODE => $parsed->getData('attempt_code'),
                        PaymentWebhookInbox::schema_fields_EVENT_TYPE => $parsed->getEventType(),
                        PaymentWebhookInbox::schema_fields_STATUS_TRANSITION => (string) (
                            $parsed->getData(CallbackResult::FIELD_STATUS_TRANSITION) ?? ''
                        ),
                        PaymentWebhookInbox::schema_fields_RECEIVED_AT => $this->dateTime($receivedAt),
                    ])->save();
                    $this->audit('received', $record->endpointCode, null, $inboxCode);

                    return new WebhookReceiveResult(
                        httpStatus: WebhookReceiveResult::HTTP_OK,
                        body: 'ok',
                        inboxCode: $inboxCode,
                        inboxWritten: true,
                        replayed: false,
                    );
                },
            );
        } catch (\Throwable $throwable) {
            // A unique-key race rolls back first; only then re-read the winner.
            $winner = $this->loadInboxByEvent($record->endpointCode, $eventId);
            if ($winner instanceof PaymentWebhookInbox) {
                return $this->existingPersistentResult(
                    $winner,
                    $record,
                    $schemaVersion,
                    $payloadHash,
                );
            }
            $this->audit('commit_failed', $record->endpointCode, self::ERROR_INBOX_COMMIT_FAILED);
            if (\function_exists('w_log_error')) {
                w_log_error('[PaymentWebhook] immutable inbox commit failed');
            }

            return new WebhookReceiveResult(
                httpStatus: WebhookReceiveResult::HTTP_SERVER_ERROR,
                body: 'retry',
                errorCode: self::ERROR_INBOX_COMMIT_FAILED,
                inboxWritten: false,
            );
        }
    }

    private function existingPersistentResult(
        PaymentWebhookInbox $existing,
        WebhookEndpointRecord $record,
        string $schemaVersion,
        string $payloadHash,
    ): WebhookReceiveResult {
        $row = $this->inboxToArray($existing);
        $inboxCode = (string) $row['inbox_code'];
        if (!$this->inboxMatches($row, $record, $schemaVersion, $payloadHash)) {
            $this->memory['urgent'][] = [
                'type' => self::ERROR_PAYLOAD_CONFLICT,
                'endpoint_code' => $record->endpointCode,
                'provider_event_id' => (string) $row['provider_event_id'],
                'existing_hash' => (string) $row['payload_hash'],
                'new_hash' => $payloadHash,
            ];
            $this->audit('conflict', $record->endpointCode, self::ERROR_PAYLOAD_CONFLICT, $inboxCode);
            if (\function_exists('w_log_error')) {
                w_log_error('[PaymentWebhook] event id payload conflict');
            }

            return new WebhookReceiveResult(
                httpStatus: WebhookReceiveResult::HTTP_CONFLICT,
                body: self::ERROR_PAYLOAD_CONFLICT,
                errorCode: self::ERROR_PAYLOAD_CONFLICT,
                inboxCode: $inboxCode,
                inboxWritten: false,
                replayed: false,
                audit: [['code' => self::ERROR_PAYLOAD_CONFLICT]],
            );
        }

        $this->audit('replay', $record->endpointCode, null, $inboxCode);

        return new WebhookReceiveResult(
            httpStatus: WebhookReceiveResult::HTTP_OK,
            body: 'ok',
            inboxCode: $inboxCode,
            inboxWritten: false,
            replayed: true,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listReceivedInbox(int $limit = 50): array
    {
        if (!$this->useMemory) {
            $rows = $this->newModel(PaymentWebhookInbox::class)
                ->where(PaymentWebhookInbox::schema_fields_STATUS, PaymentWebhookInbox::STATUS_RECEIVED)
                ->order(PaymentWebhookInbox::schema_fields_ID, 'ASC')
                ->select()
                ->limit(max(1, $limit))
                ->fetchArray();

            return array_map(
                fn (array $row): array => $this->normalizeInboxRow($row),
                $rows,
            );
        }

        $out = [];
        foreach ($this->memory['inbox'] as $row) {
            if (($row['status'] ?? '') !== PaymentWebhookInbox::STATUS_RECEIVED) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function updateInbox(string $inboxCode, array $patch): bool
    {
        if (!$this->useMemory) {
            $inbox = $this->loadInboxByCode($inboxCode);
            if (!$inbox instanceof PaymentWebhookInbox) {
                return false;
            }
            $allowed = [
                PaymentWebhookInbox::schema_fields_STATUS,
                PaymentWebhookInbox::schema_fields_CONSUMER_VERSION,
                PaymentWebhookInbox::schema_fields_IGNORE_REASON,
                PaymentWebhookInbox::schema_fields_APPLIED_AT,
            ];
            foreach ($patch as $key => $value) {
                if (!\in_array($key, $allowed, true)) {
                    continue;
                }
                if ($key === PaymentWebhookInbox::schema_fields_APPLIED_AT && \is_int($value)) {
                    $value = $this->dateTime($value);
                }
                $inbox->setData($key, $value);
            }
            $inbox->save();

            return true;
        }

        if (!isset($this->memory['inbox'][$inboxCode])) {
            return false;
        }
        foreach ($patch as $k => $v) {
            $this->memory['inbox'][$inboxCode][$k] = $v;
        }

        return true;
    }

    /**
     * Seed an already-received inbox row（consumer / crash-replay harness）.
     *
     * @param array<string, mixed> $row
     */
    public function seedInbox(array $row): string
    {
        if (!$this->useMemory) {
            throw new \LogicException('payment_webhook_seed_memory_only');
        }

        $inboxCode = (string) ($row['inbox_code'] ?? ('wi_' . bin2hex(random_bytes(6))));
        $eventId = (string) ($row['provider_event_id'] ?? '');
        $endpoint = (string) ($row['endpoint_code'] ?? 'ep');
        $row['inbox_code'] = $inboxCode;
        $row['status'] = (string) ($row['status'] ?? PaymentWebhookInbox::STATUS_RECEIVED);
        $this->memory['inbox'][$inboxCode] = $row;
        if ($eventId !== '') {
            $this->memory['by_event'][$endpoint . '|' . $eventId] = $inboxCode;
        }

        return $inboxCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInbox(string $inboxCode): ?array
    {
        if (!$this->useMemory) {
            $inbox = $this->loadInboxByCode($inboxCode);

            return $inbox instanceof PaymentWebhookInbox
                ? $this->inboxToArray($inbox)
                : null;
        }

        return $this->memory['inbox'][$inboxCode] ?? null;
    }

    public function inboxCount(): int
    {
        if (!$this->useMemory) {
            return (int) $this->newModel(PaymentWebhookInbox::class)->total();
        }

        return count($this->memory['inbox']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function urgentEvents(): array
    {
        return $this->memory['urgent'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function auditLog(): array
    {
        return $this->memory['audit'];
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function inboxMatches(
        array $existing,
        WebhookEndpointRecord $record,
        string $schemaVersion,
        string $payloadHash,
    ): bool {
        return ($existing['endpoint_code'] ?? '') === $record->endpointCode
            && ($existing['provider_code'] ?? '') === $record->providerCode
            && ($existing['merchant_account'] ?? '') === $record->merchantAccount
            && ($existing['environment'] ?? '') === $record->environment
            && ($existing['schema_version'] ?? '') === $schemaVersion
            && ($existing['payload_hash'] ?? '') === $payloadHash;
    }

    private function resolveProvider(WebhookEndpointRecord $record): ?ProviderInterface
    {
        if ($this->providerResolver !== null) {
            return ($this->providerResolver)($record->providerCode);
        }
        if ($this->useMemory) {
            return null;
        }

        try {
            return $this->methodManager->resolveWebhookProvider(
                $record->methodCode,
                $record->providerCode,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function encrypt(string $plain): string
    {
        return SecretRefCipher::seal($plain);
    }

    private function loadInboxByEvent(string $endpointCode, string $providerEventId): ?PaymentWebhookInbox
    {
        $model = $this->newModel(PaymentWebhookInbox::class);
        $model->where(PaymentWebhookInbox::schema_fields_ENDPOINT_CODE, $endpointCode)
            ->where(PaymentWebhookInbox::schema_fields_PROVIDER_EVENT_ID, $providerEventId)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function loadInboxByCode(string $inboxCode): ?PaymentWebhookInbox
    {
        $model = $this->newModel(PaymentWebhookInbox::class);
        $model->where(PaymentWebhookInbox::schema_fields_INBOX_CODE, $inboxCode)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function inboxToArray(PaymentWebhookInbox $inbox): array
    {
        return $this->normalizeInboxRow([
            PaymentWebhookInbox::schema_fields_INBOX_CODE => $inbox->getData(PaymentWebhookInbox::schema_fields_INBOX_CODE),
            PaymentWebhookInbox::schema_fields_ENDPOINT_CODE => $inbox->getData(PaymentWebhookInbox::schema_fields_ENDPOINT_CODE),
            PaymentWebhookInbox::schema_fields_PROVIDER_EVENT_ID => $inbox->getData(PaymentWebhookInbox::schema_fields_PROVIDER_EVENT_ID),
            PaymentWebhookInbox::schema_fields_PROVIDER_CODE => $inbox->getData(PaymentWebhookInbox::schema_fields_PROVIDER_CODE),
            PaymentWebhookInbox::schema_fields_MERCHANT_ACCOUNT => $inbox->getData(PaymentWebhookInbox::schema_fields_MERCHANT_ACCOUNT),
            PaymentWebhookInbox::schema_fields_ENVIRONMENT => $inbox->getData(PaymentWebhookInbox::schema_fields_ENVIRONMENT),
            PaymentWebhookInbox::schema_fields_SCHEMA_VERSION => $inbox->getData(PaymentWebhookInbox::schema_fields_SCHEMA_VERSION),
            PaymentWebhookInbox::schema_fields_VERIFICATION_SECRET_VERSION => $inbox->getData(PaymentWebhookInbox::schema_fields_VERIFICATION_SECRET_VERSION),
            PaymentWebhookInbox::schema_fields_PAYLOAD_HASH => $inbox->getData(PaymentWebhookInbox::schema_fields_PAYLOAD_HASH),
            PaymentWebhookInbox::schema_fields_ENCRYPTED_RAW_PAYLOAD => $inbox->getData(PaymentWebhookInbox::schema_fields_ENCRYPTED_RAW_PAYLOAD),
            PaymentWebhookInbox::schema_fields_ENCRYPTED_HEADERS => $inbox->getData(PaymentWebhookInbox::schema_fields_ENCRYPTED_HEADERS),
            PaymentWebhookInbox::schema_fields_ENCRYPTED_SIGNATURE => $inbox->getData(PaymentWebhookInbox::schema_fields_ENCRYPTED_SIGNATURE),
            PaymentWebhookInbox::schema_fields_STATUS => $inbox->getData(PaymentWebhookInbox::schema_fields_STATUS),
            PaymentWebhookInbox::schema_fields_CONSUMER_VERSION => $inbox->getData(PaymentWebhookInbox::schema_fields_CONSUMER_VERSION),
            PaymentWebhookInbox::schema_fields_IGNORE_REASON => $inbox->getData(PaymentWebhookInbox::schema_fields_IGNORE_REASON),
            PaymentWebhookInbox::schema_fields_INTENT_CODE => $inbox->getData(PaymentWebhookInbox::schema_fields_INTENT_CODE),
            PaymentWebhookInbox::schema_fields_ATTEMPT_CODE => $inbox->getData(PaymentWebhookInbox::schema_fields_ATTEMPT_CODE),
            PaymentWebhookInbox::schema_fields_EVENT_TYPE => $inbox->getData(PaymentWebhookInbox::schema_fields_EVENT_TYPE),
            PaymentWebhookInbox::schema_fields_STATUS_TRANSITION => $inbox->getData(PaymentWebhookInbox::schema_fields_STATUS_TRANSITION),
            PaymentWebhookInbox::schema_fields_RECEIVED_AT => $inbox->getData(PaymentWebhookInbox::schema_fields_RECEIVED_AT),
            PaymentWebhookInbox::schema_fields_APPLIED_AT => $inbox->getData(PaymentWebhookInbox::schema_fields_APPLIED_AT),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeInboxRow(array $row): array
    {
        return [
            'inbox_code' => (string) ($row[PaymentWebhookInbox::schema_fields_INBOX_CODE] ?? ''),
            'endpoint_code' => (string) ($row[PaymentWebhookInbox::schema_fields_ENDPOINT_CODE] ?? ''),
            'provider_event_id' => (string) ($row[PaymentWebhookInbox::schema_fields_PROVIDER_EVENT_ID] ?? ''),
            'provider_code' => (string) ($row[PaymentWebhookInbox::schema_fields_PROVIDER_CODE] ?? ''),
            'merchant_account' => (string) ($row[PaymentWebhookInbox::schema_fields_MERCHANT_ACCOUNT] ?? ''),
            'environment' => (string) ($row[PaymentWebhookInbox::schema_fields_ENVIRONMENT] ?? ''),
            'schema_version' => (string) ($row[PaymentWebhookInbox::schema_fields_SCHEMA_VERSION] ?? ''),
            'verification_secret_version' => (string) ($row[PaymentWebhookInbox::schema_fields_VERIFICATION_SECRET_VERSION] ?? ''),
            'payload_hash' => (string) ($row[PaymentWebhookInbox::schema_fields_PAYLOAD_HASH] ?? ''),
            'encrypted_raw_payload' => (string) ($row[PaymentWebhookInbox::schema_fields_ENCRYPTED_RAW_PAYLOAD] ?? ''),
            'encrypted_headers' => (string) ($row[PaymentWebhookInbox::schema_fields_ENCRYPTED_HEADERS] ?? ''),
            'encrypted_signature' => (string) ($row[PaymentWebhookInbox::schema_fields_ENCRYPTED_SIGNATURE] ?? ''),
            'status' => (string) ($row[PaymentWebhookInbox::schema_fields_STATUS] ?? ''),
            'consumer_version' => (int) ($row[PaymentWebhookInbox::schema_fields_CONSUMER_VERSION] ?? 0),
            'ignore_reason' => $row[PaymentWebhookInbox::schema_fields_IGNORE_REASON] ?? null,
            'intent_code' => $row[PaymentWebhookInbox::schema_fields_INTENT_CODE] ?? null,
            'attempt_code' => $row[PaymentWebhookInbox::schema_fields_ATTEMPT_CODE] ?? null,
            'event_type' => $row[PaymentWebhookInbox::schema_fields_EVENT_TYPE] ?? null,
            'status_transition' => $row[PaymentWebhookInbox::schema_fields_STATUS_TRANSITION] ?? null,
            'received_at' => $row[PaymentWebhookInbox::schema_fields_RECEIVED_AT] ?? null,
            'applied_at' => $row[PaymentWebhookInbox::schema_fields_APPLIED_AT] ?? null,
        ];
    }

    private function transactionRunner(): DatabaseTransactionRunnerInterface
    {
        return $this->transactions ??= $this->objectManager->getInstance(
            DatabaseTransactionRunnerInterface::class,
        );
    }

    private function dateTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    private function newModel(string $class): Model
    {
        /** @var T $model */
        $model = $this->objectManager->getInstance($class, [], false);

        return $model;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reject(int $status, string $code, string $body, array $context = []): WebhookReceiveResult
    {
        $this->audit('reject', (string) ($context['endpoint_code'] ?? ''), $code);
        // Desensitized audit only — no raw payload.
        return new WebhookReceiveResult(
            httpStatus: $status,
            body: $body,
            errorCode: $code,
            inboxWritten: false,
            audit: [['code' => $code, 'http' => $status]],
        );
    }

    private function audit(string $action, string $endpointCode, ?string $errorCode, ?string $inboxCode = null): void
    {
        $this->memory['audit'][] = [
            'action' => $action,
            'endpoint_code' => $endpointCode,
            'error_code' => $errorCode,
            'inbox_code' => $inboxCode,
            'at' => $this->now,
        ];
    }
}
