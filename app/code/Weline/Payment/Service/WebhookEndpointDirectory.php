<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Http\Security\SecretRefCipher;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Webhook\WebhookEndpointDirectoryInterface;
use Weline\Payment\Api\Webhook\WebhookEndpointRecord;
use Weline\Payment\Model\PaymentWebhookEndpoint;
use Weline\Payment\Model\PaymentWebhookSecret;

/**
 * Persistent endpoint/secret directory. Plaintext secret material exists only
 * inside the receive-time verification boundary.
 */
final class WebhookEndpointDirectory implements WebhookEndpointDirectoryInterface
{
    /** @var array<string, string> */
    private array $secretMaterialByRef = [];

    /** @var array<string, WebhookEndpointRecord> */
    private array $endpoints = [];

    private ?DatabaseTransactionRunnerInterface $transactions = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly bool $useMemory = false,
    ) {
    }

    public static function forTesting(): self
    {
        return new self(ObjectManager::getInstance(), useMemory: true);
    }

    /**
     * @param list<array{
     *   secret_version:string,
     *   secret_ref:string,
     *   status:string,
     *   valid_from:int,
     *   valid_until:int,
     *   material?:string
     * }> $secrets
     * @param array<string, mixed> $scopeSnapshot
     */
    public function registerEndpoint(
        string $endpointCode,
        string $providerCode,
        string $methodCode,
        string $merchantAccount = 'default',
        string $environment = 'sandbox',
        string $status = WebhookEndpointRecord::STATUS_ACTIVE,
        string $activeSecretVersion = 'v1',
        string $contextVersion = '1',
        array $secrets = [],
        array $scopeSnapshot = [],
        bool $allowNewCapture = true,
        int $retainUntil = 0,
    ): WebhookEndpointRecord {
        $endpointCode = trim($endpointCode);
        $providerCode = trim($providerCode);
        $methodCode = trim($methodCode);
        if ($endpointCode === '' || $providerCode === '' || $methodCode === '') {
            throw new \InvalidArgumentException('payment_webhook_endpoint_identity_required');
        }
        $this->assertEndpointStatus($status);

        if ($this->useMemory) {
            return $this->registerMemory(
                $endpointCode,
                $providerCode,
                $methodCode,
                $merchantAccount,
                $environment,
                $status,
                $activeSecretVersion,
                $contextVersion,
                $secrets,
                $scopeSnapshot,
                $allowNewCapture,
                $retainUntil,
            );
        }

        $connection = $this->newModel(PaymentWebhookEndpoint::class)->getConnection();
        $this->transactionRunner()->run(
            $connection,
            function () use (
                $endpointCode,
                $providerCode,
                $methodCode,
                $merchantAccount,
                $environment,
                $status,
                $activeSecretVersion,
                $contextVersion,
                $secrets,
                $scopeSnapshot,
                $allowNewCapture,
                $retainUntil,
            ): void {
                $endpoint = $this->loadEndpoint($endpointCode)
                    ?? $this->newModel(PaymentWebhookEndpoint::class);
                $endpoint->setData([
                    PaymentWebhookEndpoint::schema_fields_ENDPOINT_CODE => $endpointCode,
                    PaymentWebhookEndpoint::schema_fields_PROVIDER_CODE => $providerCode,
                    PaymentWebhookEndpoint::schema_fields_METHOD_CODE => $methodCode,
                    PaymentWebhookEndpoint::schema_fields_MERCHANT_ACCOUNT => $merchantAccount,
                    PaymentWebhookEndpoint::schema_fields_ENVIRONMENT => $environment,
                    PaymentWebhookEndpoint::schema_fields_STATUS => $status,
                    PaymentWebhookEndpoint::schema_fields_ACTIVE_SECRET_VERSION => $activeSecretVersion,
                    PaymentWebhookEndpoint::schema_fields_CONTEXT_VERSION => $contextVersion,
                    PaymentWebhookEndpoint::schema_fields_SCOPE_SNAPSHOT_JSON => $this->json($scopeSnapshot),
                    PaymentWebhookEndpoint::schema_fields_ALLOW_NEW_CAPTURE => $allowNewCapture ? 1 : 0,
                    PaymentWebhookEndpoint::schema_fields_RETAIN_UNTIL => $this->dateTimeOrNull($retainUntil),
                    PaymentWebhookEndpoint::schema_fields_UPDATED_AT => $this->dateTime(time()),
                ])->save();

                foreach ($secrets as $secret) {
                    $version = trim((string) ($secret['secret_version'] ?? ''));
                    if ($version === '') {
                        throw new \InvalidArgumentException('payment_webhook_secret_version_required');
                    }
                    $secretRef = trim((string) ($secret['secret_ref'] ?? ''));
                    $material = $secret['material'] ?? null;
                    if (\is_string($material) && $material !== '') {
                        $secretRef = SecretRefCipher::seal($material);
                    }
                    if (!SecretRefCipher::isRef($secretRef)) {
                        throw new \InvalidArgumentException('payment_webhook_secret_ref_unsealed');
                    }

                    $secretModel = $this->loadSecret($endpointCode, $version)
                        ?? $this->newModel(PaymentWebhookSecret::class);
                    $secretModel->setData([
                        PaymentWebhookSecret::schema_fields_ENDPOINT_CODE => $endpointCode,
                        PaymentWebhookSecret::schema_fields_SECRET_VERSION => $version,
                        PaymentWebhookSecret::schema_fields_SECRET_REF => $secretRef,
                        PaymentWebhookSecret::schema_fields_STATUS => (string) ($secret['status'] ?? PaymentWebhookSecret::STATUS_ACTIVE),
                        PaymentWebhookSecret::schema_fields_VALID_FROM => $this->dateTimeOrNull((int) ($secret['valid_from'] ?? 0)),
                        PaymentWebhookSecret::schema_fields_VALID_UNTIL => $this->dateTimeOrNull((int) ($secret['valid_until'] ?? 0)),
                        PaymentWebhookSecret::schema_fields_RETAIN_UNTIL => $this->dateTimeOrNull($retainUntil),
                    ])->save();
                }
            },
        );

        $record = $this->resolveActive($endpointCode);
        if (!$record instanceof WebhookEndpointRecord) {
            throw new \RuntimeException('payment_webhook_endpoint_persist_failed');
        }

        return $record;
    }

    public function disableEndpoint(string $endpointCode): void
    {
        if ($this->useMemory) {
            $this->replaceMemoryStatus($endpointCode, WebhookEndpointRecord::STATUS_DISABLED, 0);
            return;
        }

        $this->setPersistentStatus($endpointCode, WebhookEndpointRecord::STATUS_DISABLED, 0);
    }

    public function tombstoneEndpoint(string $endpointCode, int $retainUntil): void
    {
        if ($retainUntil <= 0) {
            throw new \InvalidArgumentException('payment_webhook_retain_until_required');
        }
        if ($this->useMemory) {
            $this->replaceMemoryStatus($endpointCode, WebhookEndpointRecord::STATUS_TOMBSTONE, $retainUntil);
            return;
        }

        $this->setPersistentStatus($endpointCode, WebhookEndpointRecord::STATUS_TOMBSTONE, $retainUntil);
    }

    public function resolveActive(string $endpointCode): ?WebhookEndpointRecord
    {
        $endpointCode = trim($endpointCode);
        if ($endpointCode === '') {
            return null;
        }
        if ($this->useMemory) {
            return $this->endpoints[$endpointCode] ?? null;
        }

        $endpoint = $this->loadEndpoint($endpointCode);
        if (!$endpoint instanceof PaymentWebhookEndpoint) {
            return null;
        }

        $secretRows = $this->newModel(PaymentWebhookSecret::class)
            ->where(PaymentWebhookSecret::schema_fields_ENDPOINT_CODE, $endpointCode)
            ->order(PaymentWebhookSecret::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        $secretRefs = [];
        foreach ($secretRows as $row) {
            $secretRefs[] = [
                'secret_version' => (string) ($row[PaymentWebhookSecret::schema_fields_SECRET_VERSION] ?? ''),
                'secret_ref' => (string) ($row[PaymentWebhookSecret::schema_fields_SECRET_REF] ?? ''),
                'status' => (string) ($row[PaymentWebhookSecret::schema_fields_STATUS] ?? ''),
                'valid_from' => $this->timestamp($row[PaymentWebhookSecret::schema_fields_VALID_FROM] ?? null, 0),
                'valid_until' => $this->timestamp(
                    $row[PaymentWebhookSecret::schema_fields_VALID_UNTIL] ?? null,
                    PHP_INT_MAX,
                ),
            ];
        }

        return new WebhookEndpointRecord(
            endpointCode: (string) $endpoint->getData(PaymentWebhookEndpoint::schema_fields_ENDPOINT_CODE),
            providerCode: (string) $endpoint->getData(PaymentWebhookEndpoint::schema_fields_PROVIDER_CODE),
            methodCode: (string) $endpoint->getData(PaymentWebhookEndpoint::schema_fields_METHOD_CODE),
            merchantAccount: (string) $endpoint->getData(PaymentWebhookEndpoint::schema_fields_MERCHANT_ACCOUNT),
            environment: (string) $endpoint->getData(PaymentWebhookEndpoint::schema_fields_ENVIRONMENT),
            status: (string) $endpoint->getData(PaymentWebhookEndpoint::schema_fields_STATUS),
            activeSecretVersion: (string) $endpoint->getData(PaymentWebhookEndpoint::schema_fields_ACTIVE_SECRET_VERSION),
            contextVersion: (string) $endpoint->getData(PaymentWebhookEndpoint::schema_fields_CONTEXT_VERSION),
            scopeSnapshot: $this->decodeJson(
                $endpoint->getData(PaymentWebhookEndpoint::schema_fields_SCOPE_SNAPSHOT_JSON),
            ),
            secretRefs: $secretRefs,
            allowNewCapture: (int) $endpoint->getData(PaymentWebhookEndpoint::schema_fields_ALLOW_NEW_CAPTURE) === 1,
            retainUntil: $this->timestamp(
                $endpoint->getData(PaymentWebhookEndpoint::schema_fields_RETAIN_UNTIL),
                0,
            ),
        );
    }

    public function resolveVerificationSecrets(WebhookEndpointRecord $record, int $receivedAt): array
    {
        return $record->secretsForTime($receivedAt);
    }

    public function resolveSecretMaterial(string $secretRef): ?string
    {
        if ($this->useMemory) {
            return $this->secretMaterialByRef[$secretRef] ?? null;
        }
        if (!SecretRefCipher::isRef($secretRef)) {
            return null;
        }

        try {
            return SecretRefCipher::reveal($secretRef);
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error('[PaymentWebhook] secret_ref reveal failed');
            }

            return null;
        }
    }

    /**
     * @param list<array<string, mixed>> $secrets
     * @param array<string, mixed> $scopeSnapshot
     */
    private function registerMemory(
        string $endpointCode,
        string $providerCode,
        string $methodCode,
        string $merchantAccount,
        string $environment,
        string $status,
        string $activeSecretVersion,
        string $contextVersion,
        array $secrets,
        array $scopeSnapshot,
        bool $allowNewCapture,
        int $retainUntil,
    ): WebhookEndpointRecord {
        $refs = [];
        foreach ($secrets as $secret) {
            $ref = (string) ($secret['secret_ref'] ?? '');
            if ($ref === '') {
                throw new \InvalidArgumentException('payment_webhook_secret_ref_required');
            }
            if (isset($secret['material']) && \is_string($secret['material'])) {
                $this->secretMaterialByRef[$ref] = $secret['material'];
            }
            $refs[] = [
                'secret_version' => (string) ($secret['secret_version'] ?? ''),
                'secret_ref' => $ref,
                'status' => (string) ($secret['status'] ?? PaymentWebhookSecret::STATUS_ACTIVE),
                'valid_from' => (int) ($secret['valid_from'] ?? 0),
                'valid_until' => (int) ($secret['valid_until'] ?? PHP_INT_MAX),
            ];
        }

        return $this->endpoints[$endpointCode] = new WebhookEndpointRecord(
            endpointCode: $endpointCode,
            providerCode: $providerCode,
            methodCode: $methodCode,
            merchantAccount: $merchantAccount,
            environment: $environment,
            status: $status,
            activeSecretVersion: $activeSecretVersion,
            contextVersion: $contextVersion,
            scopeSnapshot: $scopeSnapshot,
            secretRefs: $refs,
            allowNewCapture: $allowNewCapture,
            retainUntil: $retainUntil,
        );
    }

    private function replaceMemoryStatus(string $endpointCode, string $status, int $retainUntil): void
    {
        $record = $this->endpoints[$endpointCode] ?? null;
        if (!$record instanceof WebhookEndpointRecord) {
            return;
        }
        $this->endpoints[$endpointCode] = new WebhookEndpointRecord(
            endpointCode: $record->endpointCode,
            providerCode: $record->providerCode,
            methodCode: $record->methodCode,
            merchantAccount: $record->merchantAccount,
            environment: $record->environment,
            status: $status,
            activeSecretVersion: $record->activeSecretVersion,
            contextVersion: $record->contextVersion,
            scopeSnapshot: $record->scopeSnapshot,
            secretRefs: $record->secretRefs,
            allowNewCapture: false,
            retainUntil: $retainUntil > 0 ? $retainUntil : $record->retainUntil,
        );
    }

    private function setPersistentStatus(string $endpointCode, string $status, int $retainUntil): void
    {
        $endpoint = $this->loadEndpoint($endpointCode);
        if (!$endpoint instanceof PaymentWebhookEndpoint) {
            return;
        }
        $endpoint->setData(PaymentWebhookEndpoint::schema_fields_STATUS, $status)
            ->setData(PaymentWebhookEndpoint::schema_fields_ALLOW_NEW_CAPTURE, 0)
            ->setData(
                PaymentWebhookEndpoint::schema_fields_RETAIN_UNTIL,
                $retainUntil > 0 ? $this->dateTime($retainUntil) : $endpoint->getData(PaymentWebhookEndpoint::schema_fields_RETAIN_UNTIL),
            )
            ->setData(PaymentWebhookEndpoint::schema_fields_UPDATED_AT, $this->dateTime(time()))
            ->save();
    }

    private function loadEndpoint(string $endpointCode): ?PaymentWebhookEndpoint
    {
        $model = $this->newModel(PaymentWebhookEndpoint::class);
        $model->where(PaymentWebhookEndpoint::schema_fields_ENDPOINT_CODE, $endpointCode)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function loadSecret(string $endpointCode, string $secretVersion): ?PaymentWebhookSecret
    {
        $model = $this->newModel(PaymentWebhookSecret::class);
        $model->where(PaymentWebhookSecret::schema_fields_ENDPOINT_CODE, $endpointCode)
            ->where(PaymentWebhookSecret::schema_fields_SECRET_VERSION, $secretVersion)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function transactionRunner(): DatabaseTransactionRunnerInterface
    {
        return $this->transactions ??= $this->objectManager->getInstance(
            DatabaseTransactionRunnerInterface::class,
        );
    }

    private function assertEndpointStatus(string $status): void
    {
        if (!\in_array($status, [
            WebhookEndpointRecord::STATUS_ACTIVE,
            WebhookEndpointRecord::STATUS_DISABLED,
            WebhookEndpointRecord::STATUS_TOMBSTONE,
        ], true)) {
            throw new \InvalidArgumentException('payment_webhook_endpoint_status_invalid');
        }
    }

    private function dateTime(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function dateTimeOrNull(int $timestamp): ?string
    {
        return $timestamp > 0 && $timestamp < PHP_INT_MAX
            ? $this->dateTime($timestamp)
            : null;
    }

    private function timestamp(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $timestamp = strtotime((string) $value);

        return $timestamp === false ? $default : $timestamp;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        $decoded = json_decode((string) ($value ?? ''), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
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
}
