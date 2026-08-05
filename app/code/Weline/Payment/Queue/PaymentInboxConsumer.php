<?php

declare(strict_types=1);

namespace Weline\Payment\Queue;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Api\InventoryReservationCommitCapabilityInterface;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Model\PaymentOutbox;
use Weline\Payment\Model\PaymentWebhookInbox;
use Weline\Payment\Service\PaymentCallbackReceiver;
use Weline\Payment\Service\PaymentConnectorGuard;
use Weline\Payment\Service\PaymentIntentOrchestrator;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

/**
 * Phase-B inbox consumer（MOD-P2F-004 / TASK-P2F-004）。
 * same default connector → lock inbox → versioned CAS → unique inventory commit → effect outbox → applied。
 * 禁止生成 inventory-command outbox。
 */
final class PaymentInboxConsumer implements QueueConsumerInterface
{
    public const ERROR_DISABLED = 'payment_inbox_consumer_disabled';
    public const ERROR_CONNECTOR = PaymentConnectorGuard::ERROR_CONNECTOR_MISMATCH;
    public const ERROR_INTENT_REQUIRED = 'payment_inbox_intent_required';
    public const ERROR_TRANSACTION = 'payment_inbox_transaction_failed';
    public const ERROR_SCHEMA_VERSION = 'payment_inbox_schema_version_unsupported';
    public const ERROR_INVENTORY_REQUIRED = 'payment_inventory_commit_capability_required';

    public const EFFECT_INVOICE = 'invoice:create:v1';
    public const EFFECT_NOTIFY_PAID = 'notification:paid:v1';
    public const EFFECT_FULFILLMENT = 'fulfillment:action:v1';
    public const EFFECT_ASSET_COMMIT = 'asset:commit:v1';
    public const EFFECT_ASSET_RELEASE = 'asset:release:v1';

    private bool $enabled = true;
    private ?string $failurePoint = null;

    /**
     * @var array{
     *   outbox: array<string, array<string, mixed>>,
     *   inventory_commits: array<string, array<string, mixed>>
     * }
     */
    private array $memory = [
        'outbox' => [],
        'inventory_commits' => [],
    ];

    public function __construct(
        private readonly PaymentCallbackReceiver $receiver,
        private readonly PaymentIntentOrchestrator $orchestrator,
        private readonly PaymentConnectorGuard $connectors,
        private readonly ?InventoryReservationCommitCapabilityInterface $inventory = null,
        private readonly ?DatabaseTransactionRunnerInterface $transactions = null,
        private readonly ?ObjectManager $objectManager = null,
    ) {
    }

    public static function forTesting(
        PaymentCallbackReceiver $receiver,
        PaymentIntentOrchestrator $orchestrator,
        ?PaymentConnectorGuard $connectors = null,
        ?InventoryReservationCommitCapabilityInterface $inventory = null,
    ): self {
        return new self(
            $receiver,
            $orchestrator,
            $connectors ?? PaymentConnectorGuard::forTesting(),
            $inventory,
        );
    }

    public function name(): string
    {
        return (string) __('Payment Webhook Inbox 消费');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string) __('消费已验签的 Payment webhook inbox，并原子提交支付、库存和后续 effect');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $content = trim($queue->getContent());
        if ($content === '') {
            return true;
        }
        $payload = json_decode($content, true);

        return \is_array($payload)
            && (
                trim((string) ($payload['inbox_code'] ?? '')) !== ''
                || (int) ($payload['limit'] ?? 0) > 0
            );
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $content = trim($queue->getContent());
        $payload = $content === '' ? [] : json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        $results = trim((string) ($payload['inbox_code'] ?? '')) !== ''
            ? [$this->applyOne((string) $payload['inbox_code'])]
            : $this->run(max(1, (int) ($payload['limit'] ?? 20)));
        $failed = array_values(array_filter(
            $results,
            static fn (array $result): bool => empty($result['ok']),
        ));
        if ($failed !== []) {
            throw new \RuntimeException((string) ($failed[0]['error_code'] ?? self::ERROR_TRANSACTION));
        }

        $applied = count(array_filter(
            $results,
            static fn (array $result): bool => empty($result['ignored']) && empty($result['replayed']),
        ));
        $ignored = count(array_filter(
            $results,
            static fn (array $result): bool => !empty($result['ignored']),
        ));
        $replayed = count(array_filter(
            $results,
            static fn (array $result): bool => !empty($result['replayed']),
        ));

        return sprintf(
            'QUEUE_DONE: payment_inbox applied=%d ignored=%d replayed=%d',
            $applied,
            $ignored,
            $replayed,
        );
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Test-only durable failure seam. Supported points:
     * after_state, after_inventory, after_effects, before_inbox_mark.
     */
    public function setFailurePoint(?string $failurePoint): void
    {
        $this->failurePoint = $failurePoint;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function run(int $limit = 20): array
    {
        if (!$this->enabled) {
            return [[
                'ok' => false,
                'error_code' => self::ERROR_DISABLED,
                'message' => 'Inbox consumer disabled',
            ]];
        }

        $results = [];
        foreach ($this->receiver->listReceivedInbox($limit) as $inbox) {
            $results[] = $this->applyOne((string) $inbox['inbox_code']);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function applyOne(string $inboxCode): array
    {
        if (!$this->enabled) {
            return ['ok' => false, 'error_code' => self::ERROR_DISABLED, 'inbox_code' => $inboxCode];
        }

        // Connector check MUST happen before the transaction and before any state write.
        try {
            $this->connectors->assertSameDefaultConnector();
        } catch (\RuntimeException $exception) {
            return [
                'ok' => false,
                'error_code' => self::ERROR_CONNECTOR,
                'inbox_code' => $inboxCode,
                'message' => $exception->getMessage(),
                'fingerprints' => $this->connectors->fingerprints(),
            ];
        }

        if (!$this->orchestrator->isPersistent()) {
            return $this->applyMemory($inboxCode);
        }

        $transactionModel = $this->newModel(PaymentWebhookInbox::class);
        try {
            return $this->transactionRunner()->run(
                $transactionModel->getConnection(),
                fn (): array => $this->applyPersistentTransaction($inboxCode),
            );
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error(
                    '[PaymentInboxConsumer] transaction rolled back',
                    ['error_code' => self::ERROR_TRANSACTION, 'inbox_code' => $inboxCode],
                    'payment',
                );
            }

            return [
                'ok' => false,
                'error_code' => self::ERROR_TRANSACTION,
                'inbox_code' => $inboxCode,
                'message' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function applyPersistentTransaction(string $inboxCode): array
    {
        $inbox = $this->loadInboxForUpdate($inboxCode);
        if (!$inbox instanceof PaymentWebhookInbox) {
            return ['ok' => false, 'error_code' => 'inbox_not_found', 'inbox_code' => $inboxCode];
        }
        $status = (string) $inbox->getData(PaymentWebhookInbox::schema_fields_STATUS);
        if ($status === PaymentWebhookInbox::STATUS_APPLIED) {
            return ['ok' => true, 'replayed' => true, 'inbox_code' => $inboxCode];
        }
        if ($status === PaymentWebhookInbox::STATUS_IGNORED) {
            return ['ok' => true, 'ignored' => true, 'inbox_code' => $inboxCode];
        }
        if ($status !== PaymentWebhookInbox::STATUS_RECEIVED) {
            return ['ok' => false, 'error_code' => 'inbox_not_receivable', 'inbox_code' => $inboxCode];
        }
        if ((string) $inbox->getData(PaymentWebhookInbox::schema_fields_SCHEMA_VERSION) !== '1') {
            return [
                'ok' => false,
                'error_code' => self::ERROR_SCHEMA_VERSION,
                'inbox_code' => $inboxCode,
            ];
        }

        $intentCode = trim((string) $inbox->getData(PaymentWebhookInbox::schema_fields_INTENT_CODE));
        if ($intentCode === '') {
            return ['ok' => false, 'error_code' => self::ERROR_INTENT_REQUIRED, 'inbox_code' => $inboxCode];
        }
        $transition = (string) $inbox->getData(PaymentWebhookInbox::schema_fields_STATUS_TRANSITION);
        $expectedAttemptCode = $inbox->getData(PaymentWebhookInbox::schema_fields_ATTEMPT_CODE);
        $applied = $this->orchestrator->applyWebhookTransition(
            $intentCode,
            $transition,
            null,
            \is_string($expectedAttemptCode) ? $expectedAttemptCode : null,
        );
        if (empty($applied['ok'])) {
            return [
                'ok' => false,
                'error_code' => $applied['error_code'] ?? 'payment_webhook_transition_failed',
                'inbox_code' => $inboxCode,
            ];
        }
        $this->failAt('after_state');

        $attempt = \is_array($applied['attempt'] ?? null) ? $applied['attempt'] : [];
        $inventoryCommitted = false;
        $effects = [];
        if (empty($applied['ignored'])
            && ($attempt['status'] ?? '') === 'succeeded'
        ) {
            $reservationUuid = trim((string) ($attempt['reservation_uuid'] ?? ''));
            if ($reservationUuid !== '') {
                $inventory = $this->inventoryCapability();
                if (!$inventory instanceof InventoryReservationCommitCapabilityInterface) {
                    throw new \RuntimeException(self::ERROR_INVENTORY_REQUIRED);
                }
                $idempotencyKey = 'attempt:' . $attempt['attempt_code'] . ':inventory:commit:v1';
                $requestHash = hash('sha256', $idempotencyKey . '|' . $reservationUuid);
                $inventory->commit($reservationUuid, $idempotencyKey, $requestHash);
                $inventoryCommitted = true;
            }
            $this->failAt('after_inventory');

            $effectTypes = [
                self::EFFECT_INVOICE,
                self::EFFECT_FULFILLMENT,
                self::EFFECT_NOTIFY_PAID,
            ];
            $intent = is_array($applied['intent'] ?? null) ? $applied['intent'] : [];
            if ($this->hasAssetAllocations($intent)) {
                $effectTypes[] = self::EFFECT_ASSET_COMMIT;
            }
            foreach ($effectTypes as $effectType) {
                $effects[] = $this->enqueueDurableEffect(
                    (string) ($attempt['attempt_code'] ?? ''),
                    $intentCode,
                    $inboxCode,
                    $effectType,
                    $intent,
                );
            }
            $this->failAt('after_effects');
        } elseif (empty($applied['ignored'])
            && ($attempt['status'] ?? '') === 'failed'
            && $this->hasAssetAllocations(
                is_array($applied['intent'] ?? null) ? $applied['intent'] : [],
            )
        ) {
            $effects[] = $this->enqueueDurableEffect(
                (string) ($attempt['attempt_code'] ?? ''),
                $intentCode,
                $inboxCode,
                self::EFFECT_ASSET_RELEASE,
                is_array($applied['intent'] ?? null) ? $applied['intent'] : [],
            );
            $this->failAt('after_effects');
        }

        $this->failAt('before_inbox_mark');
        $ignored = !empty($applied['ignored']);
        $inbox->setData(
            PaymentWebhookInbox::schema_fields_STATUS,
            $ignored ? PaymentWebhookInbox::STATUS_IGNORED : PaymentWebhookInbox::STATUS_APPLIED,
        )->setData(
            PaymentWebhookInbox::schema_fields_CONSUMER_VERSION,
            (int) $inbox->getData(PaymentWebhookInbox::schema_fields_CONSUMER_VERSION) + 1,
        )->setData(
            PaymentWebhookInbox::schema_fields_IGNORE_REASON,
            $ignored ? 'stale_transition' : null,
        )->setData(
            PaymentWebhookInbox::schema_fields_APPLIED_AT,
            date('Y-m-d H:i:s'),
        )->save();

        return [
            'ok' => true,
            'ignored' => $ignored,
            'audit' => $ignored ? 'stale_ignored' : null,
            'inbox_code' => $inboxCode,
            'intent_code' => $intentCode,
            'attempt_code' => $attempt['attempt_code'] ?? null,
            'attempt_version' => $attempt['version'] ?? null,
            'inventory_committed' => $inventoryCommitted,
            'effects' => $effects,
            'inventory_command_outbox_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function applyMemory(string $inboxCode): array
    {
        $inbox = $this->receiver->getInbox($inboxCode);
        if ($inbox === null) {
            return ['ok' => false, 'error_code' => 'inbox_not_found', 'inbox_code' => $inboxCode];
        }
        if (($inbox['status'] ?? '') === PaymentWebhookInbox::STATUS_APPLIED) {
            return ['ok' => true, 'replayed' => true, 'inbox_code' => $inboxCode];
        }
        if (($inbox['status'] ?? '') === PaymentWebhookInbox::STATUS_IGNORED) {
            return ['ok' => true, 'ignored' => true, 'inbox_code' => $inboxCode];
        }
        if (($inbox['status'] ?? '') !== PaymentWebhookInbox::STATUS_RECEIVED) {
            return ['ok' => false, 'error_code' => 'inbox_not_receivable', 'inbox_code' => $inboxCode];
        }

        $intentCode = trim((string) ($inbox['intent_code'] ?? ''));
        if ($intentCode === '') {
            return ['ok' => false, 'error_code' => self::ERROR_INTENT_REQUIRED, 'inbox_code' => $inboxCode];
        }

        $applied = $this->orchestrator->applyWebhookTransition(
            $intentCode,
            (string) ($inbox['status_transition'] ?? 'paid'),
            null,
            \is_string($inbox['attempt_code'] ?? null) ? $inbox['attempt_code'] : null,
        );
        if (empty($applied['ok'])) {
            return [
                'ok' => false,
                'error_code' => $applied['error_code'],
                'inbox_code' => $inboxCode,
            ];
        }

        if (!empty($applied['ignored'])) {
            $this->receiver->updateInbox($inboxCode, [
                'status' => PaymentWebhookInbox::STATUS_IGNORED,
                'consumer_version' => (int) ($inbox['consumer_version'] ?? 0) + 1,
                'applied_at' => time(),
                'ignore_reason' => 'stale_transition',
            ]);

            return [
                'ok' => true,
                'ignored' => true,
                'inbox_code' => $inboxCode,
                'audit' => 'stale_ignored',
            ];
        }

        $attempt = \is_array($applied['attempt'] ?? null) ? $applied['attempt'] : [];
        $inventoryCommitted = false;
        if (($attempt['status'] ?? '') === 'succeeded' && $this->inventory !== null) {
            $reservationUuid = (string) ($attempt['reservation_uuid'] ?? '');
            if ($reservationUuid !== '') {
                $idempotencyKey = 'attempt:' . $attempt['attempt_code'] . ':inventory:commit:v1';
                $requestHash = hash('sha256', $idempotencyKey . '|' . $reservationUuid);
                $this->inventory->commit($reservationUuid, $idempotencyKey, $requestHash);
                if (!isset($this->memory['inventory_commits'][$idempotencyKey])) {
                    $this->memory['inventory_commits'][$idempotencyKey] = [
                        'reservation_uuid' => $reservationUuid,
                        'idempotency_key' => $idempotencyKey,
                        'attempt_code' => $attempt['attempt_code'],
                    ];
                }
                $inventoryCommitted = true;
            }
        }

        $effects = [];
        $intent = is_array($applied['intent'] ?? null) ? $applied['intent'] : [];
        if (($attempt['status'] ?? '') === 'succeeded') {
            $effectTypes = [
                self::EFFECT_INVOICE,
                self::EFFECT_FULFILLMENT,
                self::EFFECT_NOTIFY_PAID,
            ];
            if ($this->hasAssetAllocations($intent)) {
                $effectTypes[] = self::EFFECT_ASSET_COMMIT;
            }
            foreach ($effectTypes as $effectType) {
                $effects[] = $this->enqueueMemoryEffect(
                    (string) $attempt['attempt_code'],
                    $intentCode,
                    $inboxCode,
                    $effectType,
                );
            }
        } elseif (($attempt['status'] ?? '') === 'failed'
            && $this->hasAssetAllocations($intent)
        ) {
            $effects[] = $this->enqueueMemoryEffect(
                (string) $attempt['attempt_code'],
                $intentCode,
                $inboxCode,
                self::EFFECT_ASSET_RELEASE,
            );
        }

        $this->receiver->updateInbox($inboxCode, [
            'status' => PaymentWebhookInbox::STATUS_APPLIED,
            'consumer_version' => (int) ($inbox['consumer_version'] ?? 0) + 1,
            'ignore_reason' => null,
            'applied_at' => time(),
        ]);

        return [
            'ok' => true,
            'inbox_code' => $inboxCode,
            'intent_code' => $intentCode,
            'attempt_code' => $attempt['attempt_code'] ?? null,
            'attempt_version' => $attempt['version'] ?? null,
            'inventory_committed' => $inventoryCommitted,
            'effects' => $effects,
            'inventory_command_outbox_count' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $intent
     * @return array<string, mixed>
     */
    private function enqueueDurableEffect(
        string $attemptCode,
        string $intentCode,
        string $inboxCode,
        string $effectType,
        array $intent,
    ): array {
        $effectKey = PaymentEffectRecord::buildKey(
            $intentCode,
            $attemptCode,
            $effectType,
        );
        $outbox = $this->newModel(PaymentOutbox::class);
        $outbox->where(PaymentOutbox::schema_fields_EFFECT_KEY, $effectKey)
            ->find()
            ->fetch();
        if ($outbox->getId()) {
            return $this->outboxToArray($outbox) + ['replayed' => true];
        }

        $outbox->setData([
            PaymentOutbox::schema_fields_OUTBOX_CODE => 'po_' . substr(hash('sha256', $effectKey), 0, 40),
            PaymentOutbox::schema_fields_EFFECT_KEY => $effectKey,
            PaymentOutbox::schema_fields_INBOX_CODE => $inboxCode,
            PaymentOutbox::schema_fields_INTENT_CODE => $intentCode,
            PaymentOutbox::schema_fields_ATTEMPT_CODE => $attemptCode,
            PaymentOutbox::schema_fields_EFFECT_TYPE => $effectType,
            PaymentOutbox::schema_fields_STATUS => PaymentOutbox::STATUS_PENDING,
            PaymentOutbox::schema_fields_PAYLOAD_JSON => json_encode([
                'payable_type' => $intent['payable_type'] ?? null,
                'payable_id' => $intent['payable_id'] ?? null,
                'schema_version' => '1',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PaymentOutbox::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        return $this->outboxToArray($outbox) + ['replayed' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function enqueueMemoryEffect(
        string $attemptCode,
        string $intentCode,
        string $inboxCode,
        string $effectType,
    ): array {
        $effectKey = PaymentEffectRecord::buildKey(
            $intentCode,
            $attemptCode,
            $effectType,
        );
        if (isset($this->memory['outbox'][$effectKey])) {
            return $this->memory['outbox'][$effectKey] + ['replayed' => true];
        }
        $row = [
            'outbox_code' => 'po_' . substr(hash('sha256', $effectKey), 0, 40),
            'effect_key' => $effectKey,
            'inbox_code' => $inboxCode,
            'intent_code' => $intentCode,
            'attempt_code' => $attemptCode,
            'effect_type' => $effectType,
            'status' => PaymentOutbox::STATUS_PENDING,
            'created_at' => time(),
            'replayed' => false,
        ];
        $this->memory['outbox'][$effectKey] = $row;

        return $row;
    }

    private function loadInboxForUpdate(string $inboxCode): ?PaymentWebhookInbox
    {
        $model = $this->newModel(PaymentWebhookInbox::class)
            ->where(PaymentWebhookInbox::schema_fields_INBOX_CODE, $inboxCode);
        if (!$this->isSqliteModel($model)) {
            $model->additional('FOR UPDATE');
        }
        $model->find()->fetch();

        return $model->getId() ? $model : null;
    }

    /** @param array<string, mixed> $intent */
    private function hasAssetAllocations(array $intent): bool
    {
        return is_array($intent['asset_allocations'] ?? null)
            && $intent['asset_allocations'] !== [];
    }

    private function failAt(string $point): void
    {
        if ($this->failurePoint === $point) {
            throw new \RuntimeException('payment_inbox_controlled_failure:' . $point);
        }
    }

    private function inventoryCapability(): ?InventoryReservationCommitCapabilityInterface
    {
        if ($this->inventory instanceof InventoryReservationCommitCapabilityInterface) {
            return $this->inventory;
        }
        try {
            $inventory = $this->manager()->getInstance(
                InventoryReservationCommitCapabilityInterface::class,
            );

            return $inventory instanceof InventoryReservationCommitCapabilityInterface ? $inventory : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function transactionRunner(): DatabaseTransactionRunnerInterface
    {
        return $this->transactions
            ?? $this->manager()->getInstance(DatabaseTransactionRunnerInterface::class);
    }

    private function manager(): ObjectManager
    {
        return $this->objectManager ?? ObjectManager::getInstance();
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T
     */
    private function newModel(string $class): Model
    {
        /** @var T $model */
        $model = $this->manager()->getInstance($class, [], false);

        return $model;
    }

    private function isSqliteModel(Model $model): bool
    {
        return strtolower((string) $model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }

    /**
     * @return array<string, mixed>
     */
    private function outboxToArray(PaymentOutbox $outbox): array
    {
        return [
            'outbox_code' => (string) $outbox->getData(PaymentOutbox::schema_fields_OUTBOX_CODE),
            'effect_key' => (string) $outbox->getData(PaymentOutbox::schema_fields_EFFECT_KEY),
            'inbox_code' => (string) $outbox->getData(PaymentOutbox::schema_fields_INBOX_CODE),
            'intent_code' => (string) $outbox->getData(PaymentOutbox::schema_fields_INTENT_CODE),
            'attempt_code' => (string) $outbox->getData(PaymentOutbox::schema_fields_ATTEMPT_CODE),
            'effect_type' => (string) $outbox->getData(PaymentOutbox::schema_fields_EFFECT_TYPE),
            'status' => (string) $outbox->getData(PaymentOutbox::schema_fields_STATUS),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function effectOutbox(): array
    {
        if ($this->orchestrator->isPersistent()) {
            return $this->newModel(PaymentOutbox::class)
                ->order(PaymentOutbox::schema_fields_ID, 'ASC')
                ->select()
                ->limit(200)
                ->fetchArray();
        }

        return array_values($this->memory['outbox']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inventoryCommits(): array
    {
        return array_values($this->memory['inventory_commits']);
    }

    public function inventoryCommandOutboxCount(): int
    {
        return 0;
    }
}
