<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Subscription\Model\SubscriptionBillingAttempt;

/** Durable billing Attempt journal with one active pending/unknown row per period. */
final class SubscriptionBillingAttemptStore
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $attempts = null;

    /** @var array<string, string> */
    private array $latestByPeriod = [];

    /** @var (\Closure(): SubscriptionBillingAttempt)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): SubscriptionBillingAttempt)|null $recordFactory */
    public function __construct(?callable $recordFactory = null, bool $useMemory = false)
    {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->attempts = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /** @return array<string, mixed> */
    public function start(string $periodKey, string $subscriptionId, string $workerId): array
    {
        $periodKey = trim($periodKey);
        $subscriptionId = trim($subscriptionId);
        $workerId = trim($workerId);
        if ($periodKey === '' || strlen($periodKey) > 160
            || $subscriptionId === '' || strlen($subscriptionId) > 64
            || $workerId === '' || strlen($workerId) > 128
        ) {
            throw new \InvalidArgumentException(__('Subscription Billing Attempt identity 非法'));
        }

        $latest = $this->latestForPeriod($periodKey);
        if ($latest !== null && \in_array(
            (string) $latest['status'],
            [self::STATUS_PENDING, self::STATUS_UNKNOWN, self::STATUS_SUCCEEDED],
            true,
        )) {
            return $latest + ['replayed' => true];
        }

        $attemptNo = $latest === null ? 1 : ((int) $latest['attempt_no'] + 1);
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'attempt_id' => 'subatt_' . bin2hex(random_bytes(12)),
            'period_key' => $periodKey,
            'subscription_id' => $subscriptionId,
            'attempt_no' => $attemptNo,
            'worker_id' => $workerId,
            'status' => self::STATUS_PENDING,
            'active_guard' => SubscriptionBillingAttempt::ACTIVE_GUARD,
            'order_ref' => null,
            'payment_intent_code' => null,
            'payment_attempt_code' => null,
            'payment_status' => null,
            'error_code' => null,
            'attempt_version' => 1,
            'cas_token' => bin2hex(random_bytes(32)),
            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ];

        if ($this->attempts !== null) {
            $this->attempts[$row['attempt_id']] = $row;
            $this->latestByPeriod[$periodKey] = $row['attempt_id'];
            return $row;
        }

        try {
            $this->newRecord()->clear()->setData($this->recordData($row))->save();
        } catch (Throwable $exception) {
            $winner = $this->latestForPeriod($periodKey);
            if ($winner !== null && \in_array(
                (string) $winner['status'],
                [self::STATUS_PENDING, self::STATUS_UNKNOWN, self::STATUS_SUCCEEDED],
                true,
            )) {
                return $winner + ['replayed' => true];
            }
            throw $exception;
        }

        return $this->get((string) $row['attempt_id']);
    }

    /** @return array<string, mixed> */
    public function bindOrder(string $attemptId, string $orderRef): array
    {
        $orderRef = trim($orderRef);
        if ($orderRef === '' || strlen($orderRef) > 64) {
            throw new \InvalidArgumentException(__('Subscription Billing Attempt Order reference 非法'));
        }
        $row = $this->get($attemptId);
        $existing = trim((string) ($row['order_ref'] ?? ''));
        if ($existing !== '' && !hash_equals($existing, $orderRef)) {
            throw new SubscriptionConflictException(
                'subscription_attempt_order_conflict',
                __('Billing Attempt 已绑定不同 Order：%{1}', [$attemptId]),
                ['attempt_id' => $attemptId, 'order_ref' => $existing],
            );
        }
        if ($existing !== '') {
            return $row;
        }
        return $this->casUpdate($row, ['order_ref' => $orderRef]);
    }

    /**
     * @param array{status:string,terminal?:bool,intent_code?:?string,payment_attempt_code?:?string,error_code?:?string} $payment
     * @return array<string, mixed>
     */
    public function recordPayment(string $attemptId, array $payment): array
    {
        $paymentStatus = strtolower(trim((string) ($payment['status'] ?? '')));
        $terminal = !empty($payment['terminal']);
        $status = match (true) {
            \in_array($paymentStatus, ['succeeded', 'paid', 'captured', 'authorized'], true)
                => self::STATUS_SUCCEEDED,
            $paymentStatus === self::STATUS_UNKNOWN => self::STATUS_UNKNOWN,
            $terminal && \in_array($paymentStatus, ['failed', 'closed', 'cancelled'], true)
                => self::STATUS_FAILED,
            default => self::STATUS_PENDING,
        };

        return $this->casUpdate($this->get($attemptId), [
            'status' => $status,
            'payment_intent_code' => $this->nullableString($payment['intent_code'] ?? null, 64),
            'payment_attempt_code' => $this->nullableString($payment['payment_attempt_code'] ?? null, 64),
            'payment_status' => $this->nullableString($paymentStatus, 32),
            'error_code' => $this->nullableString($payment['error_code'] ?? null, 128),
        ]);
    }

    /** @return array<string, mixed> */
    public function succeed(string $attemptId, string $orderRef): array
    {
        $row = $this->bindOrder($attemptId, $orderRef);
        return $this->casUpdate($row, [
            'status' => self::STATUS_SUCCEEDED,
            'payment_status' => self::STATUS_SUCCEEDED,
            'error_code' => null,
        ]);
    }

    /** @return array<string, mixed> */
    public function fail(string $attemptId, string $errorCode): array
    {
        return $this->casUpdate($this->get($attemptId), [
            'status' => self::STATUS_FAILED,
            'payment_status' => self::STATUS_FAILED,
            'error_code' => $this->nullableString($errorCode, 128),
        ]);
    }

    /** @return array<string, mixed> */
    public function unknown(
        string $attemptId,
        string $errorCode,
        ?string $intentCode = null,
        ?string $paymentAttemptCode = null,
    ): array {
        return $this->casUpdate($this->get($attemptId), [
            'status' => self::STATUS_UNKNOWN,
            'payment_status' => self::STATUS_UNKNOWN,
            'payment_intent_code' => $this->nullableString($intentCode, 64),
            'payment_attempt_code' => $this->nullableString($paymentAttemptCode, 64),
            'error_code' => $this->nullableString($errorCode, 128),
        ]);
    }

    /** @return array<string, mixed> */
    public function get(string $attemptId): array
    {
        $attemptId = trim($attemptId);
        $row = $this->find($attemptId);
        if ($row === null) {
            throw new SubscriptionConflictException(
                'subscription_attempt_not_found',
                __('Billing Attempt 不存在：%{1}', [$attemptId]),
                ['attempt_id' => $attemptId],
            );
        }
        return $row;
    }

    public function latestForPeriod(string $periodKey): ?array
    {
        $periodKey = trim($periodKey);
        if ($this->attempts !== null) {
            $id = $this->latestByPeriod[$periodKey] ?? null;
            return $id !== null ? ($this->attempts[$id] ?? null) : null;
        }
        $model = $this->newRecord();
        $rows = $model->clear()
            ->where(SubscriptionBillingAttempt::schema_fields_PERIOD_KEY, $periodKey)
            ->order(SubscriptionBillingAttempt::schema_fields_ATTEMPT_NO, 'DESC')
            ->select()
            ->fetchArray();
        return $rows === [] ? null : $this->normalize($rows[0]);
    }

    public function count(): int
    {
        if ($this->attempts !== null) {
            return count($this->attempts);
        }
        return count($this->newRecord()->clear()->select()->fetchArray());
    }

    /** @return array<string, mixed>|null */
    private function find(string $attemptId): ?array
    {
        if ($this->attempts !== null) {
            return $this->attempts[$attemptId] ?? null;
        }
        $model = $this->newRecord();
        $model->clear()
            ->where(SubscriptionBillingAttempt::schema_fields_ATTEMPT_ID, $attemptId)
            ->find()
            ->fetch();
        return $model->getId() ? $this->normalize($model->getData()) : null;
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $patch @return array<string, mixed> */
    private function casUpdate(array $current, array $patch): array
    {
        $attemptId = (string) $current['attempt_id'];
        $next = $current;
        foreach ([
            'status',
            'order_ref',
            'payment_intent_code',
            'payment_attempt_code',
            'payment_status',
            'error_code',
        ] as $field) {
            if (array_key_exists($field, $patch)) {
                $next[$field] = $patch[$field];
            }
        }
        if (!\in_array($next['status'], [
            self::STATUS_PENDING,
            self::STATUS_UNKNOWN,
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
        ], true)) {
            throw new \InvalidArgumentException(__('Subscription Billing Attempt status 非法'));
        }
        $terminal = \in_array($next['status'], [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true);
        $next['active_guard'] = $terminal ? null : SubscriptionBillingAttempt::ACTIVE_GUARD;
        $next['finished_at'] = $terminal ? gmdate('Y-m-d H:i:s') : null;
        $next['attempt_version'] = (int) $current['attempt_version'] + 1;
        $next['cas_token'] = bin2hex(random_bytes(32));
        $next['updated_at'] = gmdate('Y-m-d H:i:s');

        if ($this->attempts !== null) {
            $this->attempts[$attemptId] = $next;
            return $next;
        }

        $candidate = $this->newRecord()->clear();
        $candidate->getQuery(false)
            ->where(SubscriptionBillingAttempt::schema_fields_ATTEMPT_ID, $attemptId)
            ->where(SubscriptionBillingAttempt::schema_fields_VERSION, (int) $current['attempt_version'])
            ->where(SubscriptionBillingAttempt::schema_fields_CAS_TOKEN, (string) $current['cas_token'])
            ->update($this->recordMutableData($next))
            ->fetch();

        $saved = $this->get($attemptId);
        if (!hash_equals((string) $next['cas_token'], (string) $saved['cas_token'])) {
            throw new SubscriptionConflictException(
                'subscription_attempt_concurrent_update',
                __('Billing Attempt 并发更新冲突：%{1}', [$attemptId]),
                ['attempt_id' => $attemptId, 'actual_version' => $saved['attempt_version']],
            );
        }
        return $saved;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function recordData(array $row): array
    {
        return [
            SubscriptionBillingAttempt::schema_fields_ATTEMPT_ID => $row['attempt_id'],
            SubscriptionBillingAttempt::schema_fields_PERIOD_KEY => $row['period_key'],
            SubscriptionBillingAttempt::schema_fields_SUBSCRIPTION_ID => $row['subscription_id'],
            SubscriptionBillingAttempt::schema_fields_ATTEMPT_NO => $row['attempt_no'],
            SubscriptionBillingAttempt::schema_fields_WORKER_ID => $row['worker_id'],
            ...$this->recordMutableData($row),
            SubscriptionBillingAttempt::schema_fields_STARTED_AT => $row['started_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function recordMutableData(array $row): array
    {
        return [
            SubscriptionBillingAttempt::schema_fields_STATUS => $row['status'],
            SubscriptionBillingAttempt::schema_fields_ACTIVE_GUARD => $row['active_guard'],
            SubscriptionBillingAttempt::schema_fields_ORDER_REF => $row['order_ref'],
            SubscriptionBillingAttempt::schema_fields_PAYMENT_INTENT_CODE => $row['payment_intent_code'],
            SubscriptionBillingAttempt::schema_fields_PAYMENT_ATTEMPT_CODE => $row['payment_attempt_code'],
            SubscriptionBillingAttempt::schema_fields_PAYMENT_STATUS => $row['payment_status'],
            SubscriptionBillingAttempt::schema_fields_ERROR_CODE => $row['error_code'],
            SubscriptionBillingAttempt::schema_fields_VERSION => $row['attempt_version'],
            SubscriptionBillingAttempt::schema_fields_CAS_TOKEN => $row['cas_token'],
            SubscriptionBillingAttempt::schema_fields_UPDATED_AT => $row['updated_at'],
            SubscriptionBillingAttempt::schema_fields_FINISHED_AT => $row['finished_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalize(array $row): array
    {
        return [
            'attempt_id' => (string) ($row[SubscriptionBillingAttempt::schema_fields_ATTEMPT_ID] ?? ''),
            'period_key' => (string) ($row[SubscriptionBillingAttempt::schema_fields_PERIOD_KEY] ?? ''),
            'subscription_id' => (string) ($row[SubscriptionBillingAttempt::schema_fields_SUBSCRIPTION_ID] ?? ''),
            'attempt_no' => (int) ($row[SubscriptionBillingAttempt::schema_fields_ATTEMPT_NO] ?? 0),
            'worker_id' => (string) ($row[SubscriptionBillingAttempt::schema_fields_WORKER_ID] ?? ''),
            'status' => (string) ($row[SubscriptionBillingAttempt::schema_fields_STATUS] ?? ''),
            'active_guard' => $row[SubscriptionBillingAttempt::schema_fields_ACTIVE_GUARD] ?? null,
            'order_ref' => $row[SubscriptionBillingAttempt::schema_fields_ORDER_REF] ?? null,
            'payment_intent_code' => $row[SubscriptionBillingAttempt::schema_fields_PAYMENT_INTENT_CODE] ?? null,
            'payment_attempt_code' => $row[SubscriptionBillingAttempt::schema_fields_PAYMENT_ATTEMPT_CODE] ?? null,
            'payment_status' => $row[SubscriptionBillingAttempt::schema_fields_PAYMENT_STATUS] ?? null,
            'error_code' => $row[SubscriptionBillingAttempt::schema_fields_ERROR_CODE] ?? null,
            'attempt_version' => (int) ($row[SubscriptionBillingAttempt::schema_fields_VERSION] ?? 0),
            'cas_token' => (string) ($row[SubscriptionBillingAttempt::schema_fields_CAS_TOKEN] ?? ''),
            'started_at' => (string) ($row[SubscriptionBillingAttempt::schema_fields_STARTED_AT] ?? ''),
            'updated_at' => (string) ($row[SubscriptionBillingAttempt::schema_fields_UPDATED_AT] ?? ''),
            'finished_at' => $row[SubscriptionBillingAttempt::schema_fields_FINISHED_AT] ?? null,
        ];
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(__('Subscription Billing Attempt 字段过长'));
        }
        return $value;
    }

    private function newRecord(): SubscriptionBillingAttempt
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(SubscriptionBillingAttempt::class, [], false);
    }
}

