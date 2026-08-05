<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Throwable;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectAuthorizationAuditInterface;
use Weline\Acl\Api\Authorization\ObjectAuthorizationResult;
use Weline\Acl\Api\Authorization\ObjectAuthorizationServiceInterface;
use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Auth\BackendUserDirectoryInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Model\PaymentOutbox;
use Weline\Payment\Model\PaymentReconciliationAudit;
use Weline\Payment\Model\PaymentRefund;
use Weline\Payment\Model\PaymentWebhookInbox;
use Weline\Payment\Queue\PaymentInboxConsumer;

/**
 * Persistent Payment invariant reconciliation (MOD-P2F-007).
 *
 * Read paths inspect ORM facts. Repair is fail-closed, scope-bound, two-person
 * authorized, idempotent, audited and restricted to deterministic outbox
 * effects. It never rewrites Attempt or Intent state.
 */
final class PaymentReconciliationService
{
    public const MODE_DRY_RUN = 'dry-run';
    public const MODE_REPAIR = 'repair';

    public const ERROR_REPAIR_DISABLED = 'payment_reconcile_repair_disabled';
    public const ERROR_SCOPE_REQUIRED = 'payment_reconcile_scope_required';
    public const ERROR_ACTOR_INVALID = 'payment_reconcile_actor_invalid';
    public const ERROR_APPROVER_INVALID = 'payment_reconcile_approver_invalid';
    public const ERROR_APPROVAL_REQUIRED = 'payment_reconcile_approval_required';
    public const ERROR_IDEMPOTENCY_REQUIRED = 'payment_reconcile_idempotency_required';
    public const ERROR_REPAIR_ACL = 'payment_reconcile_repair_acl_denied';
    public const ERROR_SCAN_TRUNCATED = 'payment_reconcile_scan_truncated';

    public const INV_SUCCEEDED_MISSING_OUTBOX = 'succeeded_attempt_missing_effect_outbox';
    public const INV_PAID_MISSING_INVOICE = 'paid_order_missing_invoice_effect';
    public const INV_INBOX_STALE = 'inbox_received_not_applied';
    public const INV_REFUND_SLA = 'refund_pending_unknown_over_sla';
    public const INV_LEASE_EXPIRED = 'attempt_reservation_lease_expired';
    public const INV_OUTBOX_STALE = 'outbox_pending_stale';
    public const INV_OUTBOX_DEAD = 'outbox_dead';

    public const TOPIC_REPAIR = 'payment.reconcile.repair';
    public const RETENTION_DAYS = 90;
    public const INBOX_STALE_SECONDS = 300;
    public const OUTBOX_STALE_SECONDS = 300;
    public const REFUND_SLA_SECONDS = 86400;

    private const SCAN_LIMIT = 5000;
    private const REPORT_DIFF_LIMIT = 200;

    /** @var list<string> */
    private const REQUIRED_EFFECTS = [
        PaymentInboxConsumer::EFFECT_INVOICE,
        PaymentInboxConsumer::EFFECT_FULFILLMENT,
        PaymentInboxConsumer::EFFECT_NOTIFY_PAID,
    ];

    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
        private readonly ObjectAuthorizationServiceInterface $authorization,
        private readonly ObjectAuthorizationAuditInterface $authorizationAudit,
        private readonly BackendUserDirectoryInterface $users,
    ) {
    }

    /**
     * Dashboard/read-only entry. It does not write reconciliation audit.
     *
     * @return array<string, mixed>
     */
    public function inspect(ScopeIdentity $scope): array
    {
        return $this->report($this->snapshot($scope), self::MODE_DRY_RUN);
    }

    /**
     * CLI dry-run entry. It writes evidence only, never Payment business facts.
     *
     * @return array<string, mixed>
     */
    public function dryRun(ScopeIdentity $scope): array
    {
        $report = $this->inspect($scope);
        $auditCode = 'pra_' . substr(hash(
            'sha256',
            $this->scopeCode($scope) . '|dry-run|' . microtime(true) . '|' . bin2hex(random_bytes(8)),
        ), 0, 40);
        $report['audit_code'] = $auditCode;
        $this->persistAudit(
            auditCode: $auditCode,
            mode: self::MODE_DRY_RUN,
            scopeCode: $this->scopeCode($scope),
            report: $report,
        );

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function repair(
        ScopeIdentity $scope,
        int $actorUserId,
        int $actorExpectedGrantVersion,
        int $approverUserId,
        int $approverExpectedGrantVersion,
        string $approvalReference,
        string $idempotencyKey,
        bool $enabled = false,
    ): array {
        if (!$enabled) {
            return $this->error(self::ERROR_REPAIR_DISABLED);
        }
        if ($scope->isGlobal()) {
            return $this->error(self::ERROR_SCOPE_REQUIRED);
        }

        $actor = $this->enabledUser($actorUserId);
        if (!$actor instanceof BackendUserContext) {
            return $this->error(self::ERROR_ACTOR_INVALID);
        }
        $approver = $this->enabledUser($approverUserId);
        if (!$approver instanceof BackendUserContext || $approverUserId === $actorUserId) {
            return $this->error(self::ERROR_APPROVER_INVALID);
        }

        $approvalReference = trim($approvalReference);
        if ($approvalReference === '' || strlen($approvalReference) > 255) {
            return $this->error(self::ERROR_APPROVAL_REQUIRED);
        }
        $idempotencyKey = trim($idempotencyKey);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $idempotencyKey) !== 1) {
            return $this->error(self::ERROR_IDEMPOTENCY_REQUIRED);
        }

        $actorAuth = $this->authorizeSubmit(
            $actor,
            $scope,
            $actorExpectedGrantVersion,
            'payment_reconcile_repair_actor_submit',
        );
        if (!$actorAuth->allowed) {
            return $this->error(self::ERROR_REPAIR_ACL, ['authorization_phase' => 'actor']);
        }
        $approverAuth = $this->authorizeSubmit(
            $approver,
            $scope,
            $approverExpectedGrantVersion,
            'payment_reconcile_repair_approver_submit',
        );
        if (!$approverAuth->allowed) {
            return $this->error(self::ERROR_REPAIR_ACL, ['authorization_phase' => 'approver']);
        }

        $scopeCode = $this->scopeCode($scope);
        $beforeSnapshot = $this->snapshot($scope);
        $before = $this->report($beforeSnapshot, self::MODE_DRY_RUN);
        if (!empty($beforeSnapshot['truncated'])) {
            return $this->error(self::ERROR_SCAN_TRUNCATED, [
                'diff_count' => $before['diff_count'],
                'scan_limit' => self::SCAN_LIMIT,
            ]);
        }

        $existing = $this->findAuditByIdempotency($scopeCode, $idempotencyKey);
        if ($existing instanceof PaymentReconciliationAudit) {
            return $this->replayAudit($existing);
        }

        $auditCode = 'pra_' . substr(hash('sha256', $scopeCode . '|repair|' . $idempotencyKey), 0, 40);
        $approvalHash = hash('sha256', $approvalReference);
        $auditPrototype = $this->newModel(PaymentReconciliationAudit::class);

        try {
            $result = $this->transactions->runWrite(
                $auditPrototype->getConnection(),
                function () use (
                    $scope,
                    $scopeCode,
                    $beforeSnapshot,
                    $before,
                    $actor,
                    $actorAuth,
                    $approver,
                    $approverAuth,
                    $approvalHash,
                    $idempotencyKey,
                    $auditCode,
                ): array {
                    $existing = $this->findAuditByIdempotency($scopeCode, $idempotencyKey, true);
                    if ($existing instanceof PaymentReconciliationAudit) {
                        return $this->replayAudit($existing);
                    }

                    $repaired = [];
                    $handledAttempts = [];
                    foreach ($beforeSnapshot['diffs'] as $diff) {
                        if (($diff['code'] ?? '') !== self::INV_SUCCEEDED_MISSING_OUTBOX) {
                            continue;
                        }
                        $attemptCode = trim((string)($diff['attempt_code'] ?? ''));
                        if ($attemptCode === '' || isset($handledAttempts[$attemptCode])) {
                            continue;
                        }
                        $handledAttempts[$attemptCode] = true;
                        $attempt = $this->loadAttemptForUpdate($attemptCode, $scopeCode);
                        if (!$attempt instanceof PaymentAttempt
                            || (string)$attempt->getData(PaymentAttempt::schema_fields_STATUS)
                                !== PaymentAttempt::STATUS_SUCCEEDED
                        ) {
                            continue;
                        }

                        $missingEffects = is_array($diff['missing_effects'] ?? null)
                            ? $diff['missing_effects']
                            : [];
                        foreach ($missingEffects as $effectType) {
                            $effectType = trim((string)$effectType);
                            if (!in_array($effectType, self::REQUIRED_EFFECTS, true)) {
                                continue;
                            }
                            $effectKey = 'attempt:' . $attemptCode . ':' . $effectType;
                            if ($this->effectExists($effectKey, true)) {
                                continue;
                            }
                            $this->insertEffect($attempt, $effectType, $effectKey);
                            $repaired[] = $effectKey;
                        }
                    }

                    $after = $this->inspect($scope);
                    $result = [
                        'ok' => true,
                        'mode' => self::MODE_REPAIR,
                        'scope' => $scopeCode,
                        'audit_code' => $auditCode,
                        'actor_user_id' => $actor->getId(),
                        'approver_user_id' => $approver->getId(),
                        'diff_count_before' => (int)$before['diff_count'],
                        'diff_count_after' => (int)$after['diff_count'],
                        'diffs_after' => $after['diffs'],
                        'diffs_after_truncated' => $after['diffs_truncated'],
                        'repaired_count' => count($repaired),
                        'repaired_effects' => $repaired,
                        'idempotent' => $repaired === [],
                        'replayed' => false,
                        'retention_days' => self::RETENTION_DAYS,
                    ];
                    $this->persistAudit(
                        auditCode: $auditCode,
                        mode: self::MODE_REPAIR,
                        scopeCode: $scopeCode,
                        report: $result,
                        actorUserId: $actor->getId(),
                        approverUserId: $approver->getId(),
                        actorGrantVersion: $actorAuth->matchedGrantVersion,
                        approverGrantVersion: $approverAuth->matchedGrantVersion,
                        approvalReferenceHash: $approvalHash,
                        idempotencyKey: $idempotencyKey,
                        diffCount: (int)$before['diff_count'],
                        repairedCount: count($repaired),
                    );

                    return $result;
                },
            );
        } catch (Throwable $throwable) {
            $existing = $this->findAuditByIdempotency($scopeCode, $idempotencyKey);
            if ($existing instanceof PaymentReconciliationAudit) {
                return $this->replayAudit($existing);
            }
            throw $throwable;
        }

        if (!empty($result['repaired_count'])) {
            $result['urgent'] = [
                'topic' => self::TOPIC_REPAIR,
                'notify_users' => [$actor->getId(), $approver->getId()],
                'emitted' => $this->emitRepairUrgent(
                    $scope,
                    $auditCode,
                    [$actor->getId(), $approver->getId()],
                    (int)$result['repaired_count'],
                ),
            ];
        } else {
            $result['urgent'] = null;
        }

        return $result;
    }

    /**
     * @return list<array{code: string, description: string, repairable: bool}>
     */
    public function invariantCatalog(): array
    {
        return [
            [
                'code' => self::INV_SUCCEEDED_MISSING_OUTBOX,
                'description' => (string)__('成功的支付尝试缺少后续 effect outbox'),
                'repairable' => true,
            ],
            [
                'code' => self::INV_PAID_MISSING_INVOICE,
                'description' => (string)__('已支付订单缺少发票 effect'),
                'repairable' => true,
            ],
            [
                'code' => self::INV_INBOX_STALE,
                'description' => (string)__('支付回调 inbox 已接收但超时未应用'),
                'repairable' => false,
            ],
            [
                'code' => self::INV_REFUND_SLA,
                'description' => (string)__('退款通道 pending 或 unknown 超过 24 小时'),
                'repairable' => false,
            ],
            [
                'code' => self::INV_LEASE_EXPIRED,
                'description' => (string)__('非终态支付尝试的库存预占租约已过期'),
                'repairable' => false,
            ],
            [
                'code' => self::INV_OUTBOX_STALE,
                'description' => (string)__('支付 outbox 处于 pending 且超时未处理'),
                'repairable' => false,
            ],
            [
                'code' => self::INV_OUTBOX_DEAD,
                'description' => (string)__('支付 outbox 已进入 dead 终态'),
                'repairable' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function correlationChain(): array
    {
        return [
            'request',
            'checkout_group',
            'order',
            'payment_intent',
            'payment_attempt',
            'provider_event',
            'inbox/outbox',
            'refund/invoice/fulfillment',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ScopeIdentity $scope): array
    {
        $scopeCode = $this->scopeCode($scope);
        [$attempts, $attemptsTruncated] = $this->baseRows(
            PaymentAttempt::class,
            PaymentAttempt::schema_fields_ID,
            $scope->isGlobal() ? null : [PaymentAttempt::schema_fields_SCOPE, $scopeCode],
        );
        [$intents, $intentsTruncated] = $this->baseRows(
            PaymentIntent::class,
            PaymentIntent::schema_fields_ID,
            $scope->isGlobal() ? null : [PaymentIntent::schema_fields_SCOPE, $scopeCode],
        );

        $attemptCodes = $this->values($attempts, PaymentAttempt::schema_fields_ATTEMPT_CODE);
        $intentCodes = $this->values($intents, PaymentIntent::schema_fields_INTENT_CODE);
        [$outbox, $outboxTruncated] = $this->linkedRows(
            PaymentOutbox::class,
            PaymentOutbox::schema_fields_ID,
            [PaymentOutbox::schema_fields_ATTEMPT_CODE => $attemptCodes],
            $scope->isGlobal(),
        );
        [$inbox, $inboxTruncated] = $this->linkedRows(
            PaymentWebhookInbox::class,
            PaymentWebhookInbox::schema_fields_ID,
            [
                PaymentWebhookInbox::schema_fields_ATTEMPT_CODE => $attemptCodes,
                PaymentWebhookInbox::schema_fields_INTENT_CODE => $intentCodes,
            ],
            $scope->isGlobal(),
        );
        [$refunds, $refundsTruncated] = $this->linkedRows(
            PaymentRefund::class,
            PaymentRefund::schema_fields_ID,
            [
                PaymentRefund::schema_fields_ATTEMPT_CODE => $attemptCodes,
                PaymentRefund::schema_fields_INTENT_CODE => $intentCodes,
            ],
            $scope->isGlobal(),
        );

        $intentByCode = [];
        foreach ($intents as $intent) {
            $intentByCode[(string)($intent[PaymentIntent::schema_fields_INTENT_CODE] ?? '')] = $intent;
        }
        $inboxByAttempt = [];
        foreach ($inbox as $row) {
            $code = trim((string)($row[PaymentWebhookInbox::schema_fields_ATTEMPT_CODE] ?? ''));
            if ($code !== '') {
                $inboxByAttempt[$code] = $row;
            }
        }
        $effectKeys = [];
        foreach ($outbox as $row) {
            $key = trim((string)($row[PaymentOutbox::schema_fields_EFFECT_KEY] ?? ''));
            if ($key !== '') {
                $effectKeys[$key] = true;
            }
        }

        $diffs = [];
        $now = time();
        $succeededCount = 0;
        foreach ($attempts as $attempt) {
            $attemptCode = trim((string)($attempt[PaymentAttempt::schema_fields_ATTEMPT_CODE] ?? ''));
            $intentCode = trim((string)($attempt[PaymentAttempt::schema_fields_INTENT_CODE] ?? ''));
            $status = (string)($attempt[PaymentAttempt::schema_fields_STATUS] ?? '');
            if ($status === PaymentAttempt::STATUS_SUCCEEDED) {
                $succeededCount++;
                $missing = [];
                foreach (self::REQUIRED_EFFECTS as $effectType) {
                    if (!isset($effectKeys['attempt:' . $attemptCode . ':' . $effectType])) {
                        $missing[] = $effectType;
                    }
                }
                if ($missing !== []) {
                    $correlation = $this->correlation(
                        $attempt,
                        $intentByCode[$intentCode] ?? [],
                        $inboxByAttempt[$attemptCode] ?? [],
                    );
                    $diffs[] = [
                        'code' => self::INV_SUCCEEDED_MISSING_OUTBOX,
                        'attempt_code' => $attemptCode,
                        'intent_code' => $intentCode,
                        'payable_type' => $attempt[PaymentAttempt::schema_fields_PAYABLE_TYPE] ?? null,
                        'payable_id' => $attempt[PaymentAttempt::schema_fields_PAYABLE_ID] ?? null,
                        'missing_effects' => $missing,
                        'correlation' => $correlation,
                    ];
                    if (in_array(PaymentInboxConsumer::EFFECT_INVOICE, $missing, true)
                        && str_contains(
                            strtolower((string)($attempt[PaymentAttempt::schema_fields_PAYABLE_TYPE] ?? '')),
                            'order',
                        )
                    ) {
                        $diffs[] = [
                            'code' => self::INV_PAID_MISSING_INVOICE,
                            'attempt_code' => $attemptCode,
                            'intent_code' => $intentCode,
                            'order_id' => $attempt[PaymentAttempt::schema_fields_PAYABLE_ID] ?? null,
                            'missing_effects' => [PaymentInboxConsumer::EFFECT_INVOICE],
                            'correlation' => $correlation,
                        ];
                    }
                }
            }

            $leaseExpiresAt = strtotime(
                (string)($attempt[PaymentAttempt::schema_fields_RESERVATION_EXPIRES_AT] ?? ''),
            ) ?: 0;
            if ($leaseExpiresAt > 0
                && $leaseExpiresAt < $now
                && in_array($status, [
                    PaymentAttempt::STATUS_CREATED,
                    PaymentAttempt::STATUS_PROVIDER_PENDING,
                    PaymentAttempt::STATUS_REQUIRES_ACTION,
                    PaymentAttempt::STATUS_PROCESSING,
                ], true)
            ) {
                $diffs[] = [
                    'code' => self::INV_LEASE_EXPIRED,
                    'attempt_code' => $attemptCode,
                    'intent_code' => $intentCode,
                    'age_seconds' => $now - $leaseExpiresAt,
                ];
            }
        }

        foreach ($inbox as $row) {
            if ((string)($row[PaymentWebhookInbox::schema_fields_STATUS] ?? '')
                !== PaymentWebhookInbox::STATUS_RECEIVED
            ) {
                continue;
            }
            $receivedAt = strtotime(
                (string)($row[PaymentWebhookInbox::schema_fields_RECEIVED_AT] ?? ''),
            ) ?: $now;
            if (($now - $receivedAt) > self::INBOX_STALE_SECONDS) {
                $diffs[] = [
                    'code' => self::INV_INBOX_STALE,
                    'inbox_code' => $row[PaymentWebhookInbox::schema_fields_INBOX_CODE] ?? null,
                    'attempt_code' => $row[PaymentWebhookInbox::schema_fields_ATTEMPT_CODE] ?? null,
                    'intent_code' => $row[PaymentWebhookInbox::schema_fields_INTENT_CODE] ?? null,
                    'provider_event_id' => $row[PaymentWebhookInbox::schema_fields_PROVIDER_EVENT_ID] ?? null,
                    'age_seconds' => $now - $receivedAt,
                ];
            }
        }

        foreach ($refunds as $row) {
            $channelStatus = (string)($row[PaymentRefund::schema_fields_CHANNEL_STATUS] ?? '');
            if (!in_array($channelStatus, [
                PaymentRefund::CHANNEL_SUBMITTED,
                PaymentRefund::CHANNEL_PENDING,
                PaymentRefund::CHANNEL_UNKNOWN,
            ], true)) {
                continue;
            }
            $updatedAt = strtotime((string)($row[PaymentRefund::schema_fields_UPDATED_AT] ?? '')) ?: $now;
            if (($now - $updatedAt) > self::REFUND_SLA_SECONDS) {
                $diffs[] = [
                    'code' => self::INV_REFUND_SLA,
                    'refund_code' => $row[PaymentRefund::schema_fields_REFUND_CODE] ?? null,
                    'attempt_code' => $row[PaymentRefund::schema_fields_ATTEMPT_CODE] ?? null,
                    'channel_status' => $channelStatus,
                    'age_seconds' => $now - $updatedAt,
                ];
            }
        }

        foreach ($outbox as $row) {
            $status = (string)($row[PaymentOutbox::schema_fields_STATUS] ?? '');
            $createdAt = strtotime((string)($row[PaymentOutbox::schema_fields_CREATED_AT] ?? '')) ?: $now;
            if ($status === PaymentOutbox::STATUS_DEAD) {
                $diffs[] = [
                    'code' => self::INV_OUTBOX_DEAD,
                    'outbox_code' => $row[PaymentOutbox::schema_fields_OUTBOX_CODE] ?? null,
                    'effect_key' => $row[PaymentOutbox::schema_fields_EFFECT_KEY] ?? null,
                ];
            } elseif ($status === PaymentOutbox::STATUS_PENDING
                && ($now - $createdAt) > self::OUTBOX_STALE_SECONDS
            ) {
                $diffs[] = [
                    'code' => self::INV_OUTBOX_STALE,
                    'outbox_code' => $row[PaymentOutbox::schema_fields_OUTBOX_CODE] ?? null,
                    'effect_key' => $row[PaymentOutbox::schema_fields_EFFECT_KEY] ?? null,
                    'age_seconds' => $now - $createdAt,
                ];
            }
        }

        return [
            'scope' => $scopeCode,
            'diffs' => $diffs,
            'truncated' => $attemptsTruncated
                || $intentsTruncated
                || $outboxTruncated
                || $inboxTruncated
                || $refundsTruncated,
            'source_counts' => [
                'attempts' => count($attempts),
                'intents' => count($intents),
                'outbox' => count($outbox),
                'inbox' => count($inbox),
                'refunds' => count($refunds),
            ],
            'attempts_succeeded' => $succeededCount,
            'outbox_rows' => $outbox,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function report(array $snapshot, string $mode): array
    {
        $diffs = $snapshot['diffs'];
        $byCode = [];
        foreach ($diffs as $diff) {
            $code = (string)($diff['code'] ?? 'unknown');
            $byCode[$code] = ($byCode[$code] ?? 0) + 1;
        }

        $outboxPending = 0;
        $outboxDead = 0;
        foreach ($snapshot['outbox_rows'] as $row) {
            $status = (string)($row[PaymentOutbox::schema_fields_STATUS] ?? '');
            $outboxPending += $status === PaymentOutbox::STATUS_PENDING ? 1 : 0;
            $outboxDead += $status === PaymentOutbox::STATUS_DEAD ? 1 : 0;
        }

        return [
            'ok' => $diffs === [] && empty($snapshot['truncated']),
            'mode' => $mode,
            'scope' => $snapshot['scope'],
            'invariant_count' => count($this->invariantCatalog()),
            'invariants' => $this->invariantCatalog(),
            'diff_count' => count($diffs),
            'diffs' => array_slice($diffs, 0, self::REPORT_DIFF_LIMIT),
            'diffs_truncated' => count($diffs) > self::REPORT_DIFF_LIMIT,
            'scan_truncated' => (bool)$snapshot['truncated'],
            'scan_limit' => self::SCAN_LIMIT,
            'metrics' => [
                'attempts_succeeded' => (int)$snapshot['attempts_succeeded'],
                'outbox_pending' => $outboxPending,
                'outbox_dead' => $outboxDead,
                'diff_total' => count($diffs),
                'diff_by_code' => $byCode,
            ] + $snapshot['source_counts'],
            'correlation_chain' => self::correlationChain(),
            'retention_days' => self::RETENTION_DAYS,
            'repair_enabled' => false,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function authorizeSubmit(
        BackendUserContext $user,
        ScopeIdentity $scope,
        int $expectedGrantVersion,
        string $phase,
    ): ObjectAuthorizationResult {
        $result = $this->authorization->authorizeForSubmit(
            $user->getRoleId(),
            ObjectAction::RECONCILE,
            $scope,
            $expectedGrantVersion,
        );
        $this->authorizationAudit->record(
            $user->getId(),
            $user->getRoleId(),
            ObjectAction::RECONCILE,
            $scope,
            $result->allowed,
            $result->reason,
            $result->matchedGrantVersion,
            $phase,
        );

        return $result;
    }

    private function enabledUser(int $userId): ?BackendUserContext
    {
        if ($userId <= 0) {
            return null;
        }
        $user = $this->users->find($userId);

        return $user instanceof BackendUserContext && $user->getIsEnabled() ? $user : null;
    }

    private function loadAttemptForUpdate(string $attemptCode, string $scopeCode): ?PaymentAttempt
    {
        $attempt = $this->newModel(PaymentAttempt::class)
            ->where(PaymentAttempt::schema_fields_ATTEMPT_CODE, $attemptCode)
            ->where(PaymentAttempt::schema_fields_SCOPE, $scopeCode);
        if (!$this->isSqlite($attempt)) {
            $attempt->additional('FOR UPDATE');
        }
        $attempt->find()->fetch();

        return $attempt->getId() ? $attempt : null;
    }

    private function effectExists(string $effectKey, bool $lockingRead = false): bool
    {
        $outbox = $this->newModel(PaymentOutbox::class)
            ->where(PaymentOutbox::schema_fields_EFFECT_KEY, $effectKey);
        if ($lockingRead && !$this->isSqlite($outbox)) {
            $outbox->additional('FOR UPDATE');
        }
        $outbox->find()->fetch();

        return (bool)$outbox->getId();
    }

    private function insertEffect(PaymentAttempt $attempt, string $effectType, string $effectKey): void
    {
        $attemptCode = (string)$attempt->getData(PaymentAttempt::schema_fields_ATTEMPT_CODE);
        $intentCode = (string)$attempt->getData(PaymentAttempt::schema_fields_INTENT_CODE);
        $inboxCode = $this->latestAppliedInboxCode($attemptCode);
        $this->newModel(PaymentOutbox::class)->setData([
            PaymentOutbox::schema_fields_OUTBOX_CODE => 'po_' . substr(hash('sha256', $effectKey), 0, 40),
            PaymentOutbox::schema_fields_EFFECT_KEY => $effectKey,
            PaymentOutbox::schema_fields_INBOX_CODE => $inboxCode !== '' ? $inboxCode : null,
            PaymentOutbox::schema_fields_INTENT_CODE => $intentCode,
            PaymentOutbox::schema_fields_ATTEMPT_CODE => $attemptCode,
            PaymentOutbox::schema_fields_EFFECT_TYPE => $effectType,
            PaymentOutbox::schema_fields_STATUS => PaymentOutbox::STATUS_PENDING,
            PaymentOutbox::schema_fields_PAYLOAD_JSON => json_encode([
                'payable_type' => $attempt->getData(PaymentAttempt::schema_fields_PAYABLE_TYPE),
                'payable_id' => $attempt->getData(PaymentAttempt::schema_fields_PAYABLE_ID),
                'schema_version' => '1',
                'reconciliation_audit' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PaymentOutbox::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    private function latestAppliedInboxCode(string $attemptCode): string
    {
        $inbox = $this->newModel(PaymentWebhookInbox::class)
            ->where(PaymentWebhookInbox::schema_fields_ATTEMPT_CODE, $attemptCode)
            ->where(PaymentWebhookInbox::schema_fields_STATUS, PaymentWebhookInbox::STATUS_APPLIED)
            ->order(PaymentWebhookInbox::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return trim((string)$inbox->getData(PaymentWebhookInbox::schema_fields_INBOX_CODE));
    }

    private function findAuditByIdempotency(
        string $scopeCode,
        string $idempotencyKey,
        bool $lockingRead = false,
    ): ?PaymentReconciliationAudit {
        $audit = $this->newModel(PaymentReconciliationAudit::class)
            ->where(PaymentReconciliationAudit::schema_fields_SCOPE, $scopeCode)
            ->where(PaymentReconciliationAudit::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey);
        if ($lockingRead && !$this->isSqlite($audit)) {
            $audit->additional('FOR UPDATE');
        }
        $audit->find()->fetch();

        return $audit->getId() ? $audit : null;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function persistAudit(
        string $auditCode,
        string $mode,
        string $scopeCode,
        array $report,
        ?int $actorUserId = null,
        ?int $approverUserId = null,
        int $actorGrantVersion = 0,
        int $approverGrantVersion = 0,
        ?string $approvalReferenceHash = null,
        ?string $idempotencyKey = null,
        ?int $diffCount = null,
        int $repairedCount = 0,
    ): void {
        $this->newModel(PaymentReconciliationAudit::class)->setData([
            PaymentReconciliationAudit::schema_fields_AUDIT_CODE => $auditCode,
            PaymentReconciliationAudit::schema_fields_MODE => $mode,
            PaymentReconciliationAudit::schema_fields_SCOPE => $scopeCode,
            PaymentReconciliationAudit::schema_fields_ACTOR_USER_ID => $actorUserId,
            PaymentReconciliationAudit::schema_fields_APPROVER_USER_ID => $approverUserId,
            PaymentReconciliationAudit::schema_fields_ACTOR_GRANT_VERSION => $actorGrantVersion,
            PaymentReconciliationAudit::schema_fields_APPROVER_GRANT_VERSION => $approverGrantVersion,
            PaymentReconciliationAudit::schema_fields_APPROVAL_REFERENCE_HASH => $approvalReferenceHash,
            PaymentReconciliationAudit::schema_fields_IDEMPOTENCY_KEY => $idempotencyKey,
            PaymentReconciliationAudit::schema_fields_DIFF_COUNT => $diffCount ?? (int)($report['diff_count'] ?? 0),
            PaymentReconciliationAudit::schema_fields_REPAIRED_COUNT => $repairedCount,
            PaymentReconciliationAudit::schema_fields_STATUS => PaymentReconciliationAudit::STATUS_COMPLETED,
            PaymentReconciliationAudit::schema_fields_REPORT_JSON => json_encode(
                $report,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            PaymentReconciliationAudit::schema_fields_RETAIN_UNTIL => date(
                'Y-m-d H:i:s',
                time() + (self::RETENTION_DAYS * 86400),
            ),
            PaymentReconciliationAudit::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function replayAudit(PaymentReconciliationAudit $audit): array
    {
        $report = json_decode(
            (string)$audit->getData(PaymentReconciliationAudit::schema_fields_REPORT_JSON),
            true,
        );
        if (!is_array($report)) {
            $report = [
                'ok' => true,
                'mode' => self::MODE_REPAIR,
                'audit_code' => $audit->getData(PaymentReconciliationAudit::schema_fields_AUDIT_CODE),
                'repaired_count' => (int)$audit->getData(
                    PaymentReconciliationAudit::schema_fields_REPAIRED_COUNT,
                ),
            ];
        }
        $report['replayed'] = true;
        $report['idempotent'] = true;

        return $report;
    }

    /**
     * @param list<int> $notifyUsers
     */
    private function emitRepairUrgent(
        ScopeIdentity $scope,
        string $auditCode,
        array $notifyUsers,
        int $repairedCount,
    ): bool {
        if (!function_exists('w_msg') || $notifyUsers === []) {
            return false;
        }
        try {
            $scopeHash = hash('sha256', $scope->canonicalKey());
            w_msg(
                self::TOPIC_REPAIR,
                'urgent',
                (string)__('支付对账已执行修复'),
                (string)__('支付对账在 Scope %{1} 补齐 %{2} 条唯一 effect，请复核审计 %{3}。', [
                    $this->scopeCode($scope),
                    $repairedCount,
                    $auditCode,
                ]),
                [
                    'icon' => 'ri-alarm-warning-line',
                    'notify_users' => $notifyUsers,
                    'scope_hash' => $scopeHash,
                    'scope' => $this->scopeCode($scope),
                    'dedupe_key' => 'payment-reconcile-repair:' . $auditCode,
                    'source_module' => 'Weline_Payment',
                    'metadata' => [
                        'scoped' => true,
                        'require_authorized_recipients' => true,
                        'scope_kind' => $scope->scopeKind,
                        'scope_identity' => $scope->toArray(),
                        'scope_hash' => $scopeHash,
                        'dedupe_key' => 'payment-reconcile-repair:' . $auditCode,
                        'audit_code' => $auditCode,
                        'repaired_count' => $repairedCount,
                        'source_module' => 'Weline_Payment',
                    ],
                ],
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array{0:string,1:mixed}|null $where
     * @return array{0:list<array<string,mixed>>,1:bool}
     */
    private function baseRows(string $modelClass, string $idField, ?array $where): array
    {
        $model = $this->newModel($modelClass);
        if ($where !== null) {
            $model->where($where[0], $where[1]);
        }
        $rows = $model->order($idField, 'ASC')
            ->limit(self::SCAN_LIMIT + 1)
            ->select()
            ->fetchArray();
        $rows = is_array($rows) ? array_values($rows) : [];
        $truncated = count($rows) > self::SCAN_LIMIT;
        if ($truncated) {
            array_pop($rows);
        }

        return [$rows, $truncated];
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<string,list<string>> $links
     * @return array{0:list<array<string,mixed>>,1:bool}
     */
    private function linkedRows(
        string $modelClass,
        string $idField,
        array $links,
        bool $global,
    ): array {
        if ($global) {
            return $this->baseRows($modelClass, $idField, null);
        }

        $rowsById = [];
        $truncated = false;
        foreach ($links as $field => $values) {
            foreach (array_chunk(array_values(array_unique($values)), 200) as $chunk) {
                if ($chunk === []) {
                    continue;
                }
                $rows = $this->newModel($modelClass)
                    ->where($field, $chunk, 'IN')
                    ->order($idField, 'ASC')
                    ->limit(self::SCAN_LIMIT + 1)
                    ->select()
                    ->fetchArray();
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $id = (string)($row[$idField] ?? hash('sha256', json_encode($row) ?: ''));
                    $rowsById[$id] = $row;
                    if (count($rowsById) > self::SCAN_LIMIT) {
                        $truncated = true;
                        break 3;
                    }
                }
            }
        }
        if ($truncated) {
            array_pop($rowsById);
        }

        return [array_values($rowsById), $truncated];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function values(array $rows, string $field): array
    {
        $values = [];
        foreach ($rows as $row) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $inbox
     * @return array<string,string|null>
     */
    private function correlation(array $attempt, array $intent, array $inbox): array
    {
        return [
            'request' => null,
            'checkout_group' => $this->nullableString(
                $intent[PaymentIntent::schema_fields_PAYMENT_GROUP_CODE] ?? null,
            ),
            'order' => str_contains(
                strtolower((string)($attempt[PaymentAttempt::schema_fields_PAYABLE_TYPE] ?? '')),
                'order',
            ) ? $this->nullableString($attempt[PaymentAttempt::schema_fields_PAYABLE_ID] ?? null) : null,
            'payment_intent' => $this->nullableString(
                $attempt[PaymentAttempt::schema_fields_INTENT_CODE] ?? null,
            ),
            'payment_attempt' => $this->nullableString(
                $attempt[PaymentAttempt::schema_fields_ATTEMPT_CODE] ?? null,
            ),
            'provider_event' => $this->nullableString(
                $inbox[PaymentWebhookInbox::schema_fields_PROVIDER_EVENT_ID] ?? null,
            ),
            'inbox' => $this->nullableString($inbox[PaymentWebhookInbox::schema_fields_INBOX_CODE] ?? null),
        ];
    }

    private function scopeCode(ScopeIdentity $scope): string
    {
        return $scope->isGlobal() ? 'global' : $scope->toLegacyScopeString();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function error(string $code, array $extra = []): array
    {
        return [
            'ok' => false,
            'mode' => self::MODE_REPAIR,
            'error_code' => $code,
        ] + $extra;
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

    private function isSqlite(Model $model): bool
    {
        return strtolower((string)$model->getConnection()
            ->getConnector()
            ->getConfigProvider()
            ->getDbType()) === 'sqlite';
    }
}
