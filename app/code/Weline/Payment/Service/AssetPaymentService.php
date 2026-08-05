<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Throwable;
use Weline\CustomerAsset\Api\CustomerAssetFacadeInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Api\OrderAssetAllocationSnapshotSinkInterface;
use Weline\Payment\Api\PaymentAssetFacadeInterface;
use Weline\Payment\Model\PaymentAllocation;
use Weline\SystemConfig\Api\ConfigReader as SystemConfig;

/**
 * Payment-owned asset allocation application service.
 *
 * Pure allocation helpers remain public for compatibility. New payment starts
 * use startWithAssets(), which joins CustomerAsset reserve, PaymentAllocation
 * persistence and Payment intent creation in one default-connector boundary.
 */
final class AssetPaymentService implements PaymentAssetFacadeInterface
{
    public const ERROR_CAPABILITY_REQUIRED = 'payment_customer_asset_capability_required';
    public const ERROR_PAYER_REQUIRED = 'payment_asset_customer_payer_required';
    public const ERROR_PAYER_MISMATCH = 'payment_asset_customer_payer_mismatch';
    public const ERROR_ALLOCATION_EXCEEDS = 'payment_asset_allocation_exceeds_payable';
    public const ERROR_ALLOCATION_DUPLICATE = 'payment_asset_allocation_duplicate_source';
    public const ERROR_CONVERSION = 'payment_asset_exchange_ratio_invalid';
    public const ERROR_CONVERSION_MISMATCH = 'payment_asset_conversion_mismatch';
    public const ERROR_START_ABORTED = 'payment_asset_start_aborted';

    /** @var list<string> */
    private const BUILT_IN_ASSETS = [
        AssetAllocationService::ASSET_CREDIT,
        AssetAllocationService::ASSET_POINTS,
        AssetAllocationService::ASSET_WCOIN,
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $memoryAllocations = null;

    /** @var array<string, array<string, mixed>>|null */
    private readonly ?array $policyOverride;

    private ?string $failNextTerminalEffect = null;

    public function __construct(
        private readonly ?AssetAllocationService $allocationService = null,
        private readonly ?PaymentScopeConfigService $scopeConfigService = null,
        private ?SystemConfig $systemConfig = null,
        private ?RuntimeProviderResolver $providerResolver = null,
        private readonly ?WriteIntentTransactionCoordinatorInterface $transactionCoordinator = null,
        private ?CustomerAssetFacadeInterface $customerAssets = null,
        private ?OrderAssetAllocationSnapshotSinkInterface $snapshotSink = null,
        ?array $policyOverride = null,
        bool $useMemory = false,
    ) {
        $this->policyOverride = $policyOverride;
        if ($useMemory) {
            $this->memoryAllocations = [];
        }
    }

    /**
     * Explicit unit-test seam. Production never uses in-memory allocations.
     *
     * @param array<string, mixed> $policy
     */
    public static function forTesting(
        CustomerAssetFacadeInterface $customerAssets,
        array $policy,
    ): self {
        return new self(
            customerAssets: $customerAssets,
            policyOverride: $policy,
            useMemory: true,
        );
    }

    /** Explicit unit-test failure seam; production callers must not use it. */
    public function failNextTerminalEffect(?string $effectType): void
    {
        $this->failNextTerminalEffect = $effectType !== null
            ? trim($effectType)
            : null;
    }

    /** @return array<string, array<string, mixed>> */
    public function getDefaultAssetPolicy(): array
    {
        $policy = [];
        foreach (self::BUILT_IN_ASSETS as $assetCode) {
            $policy[$assetCode] = [
                'enabled' => false,
                'roles' => [
                    AssetAllocationService::ROLE_PAYMENT => false,
                    AssetAllocationService::ROLE_DISCOUNT => false,
                ],
                'exchange_ratio' => '0',
                'max_discount_ratio' => '1',
                'allowed_payable_types' => [],
                'refund_strategy' => 'allocation',
            ];
        }

        return $policy;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array<string, mixed>>
     */
    public function buildAssetPolicy(array $config = []): array
    {
        $policy = $this->getDefaultAssetPolicy();
        $configuredAssets = is_array($config['assets'] ?? null) ? $config['assets'] : $config;

        foreach ($configuredAssets as $assetCode => $assetConfig) {
            if (!is_array($assetConfig)) {
                continue;
            }

            $assetCode = $this->getAllocationService()->normalizeAssetCode((string) $assetCode);
            $roles = is_array($assetConfig['roles'] ?? null) ? $assetConfig['roles'] : [];
            $enabled = !empty($assetConfig['enabled']);
            $exchangeRatio = $this->normalizeRatio($assetConfig['exchange_ratio'] ?? '0');
            $maxDiscountRatio = $this->normalizeRatio(
                $assetConfig['max_discount_ratio'] ?? '1',
                allowAboveOne: false,
            );
            $policy[$assetCode] = [
                'enabled' => $enabled && $exchangeRatio !== '0',
                'roles' => [
                    AssetAllocationService::ROLE_PAYMENT =>
                        $enabled && !empty($roles[AssetAllocationService::ROLE_PAYMENT]),
                    AssetAllocationService::ROLE_DISCOUNT =>
                        $enabled && !empty($roles[AssetAllocationService::ROLE_DISCOUNT]),
                ],
                'exchange_ratio' => $exchangeRatio,
                'max_discount_ratio' => $maxDiscountRatio,
                'allowed_payable_types' => $this->normalizeList(
                    $assetConfig['allowed_payable_types'] ?? [],
                ),
                'refund_strategy' => (string) ($assetConfig['refund_strategy'] ?? 'allocation'),
            ];
        }

        return $policy;
    }

    /** @return array<string, array<string, mixed>> */
    public function getPolicyForScope(array $context = []): array
    {
        if ($this->policyOverride !== null) {
            return $this->buildAssetPolicy($this->policyOverride);
        }

        $scope = $this->getScopeConfigService()->resolveScope($context);
        $map = $this->getSystemConfig()->getConfigMapByModule(
            PaymentScopeConfigService::MODULE_WELINE_PAYMENT,
            SystemConfig::area_BACKEND,
            $scope['scope'],
        );
        $config = [];
        foreach (self::BUILT_IN_ASSETS as $assetCode) {
            $prefix = 'payment/asset/' . $assetCode . '/';
            foreach ($map as $key => $value) {
                $key = (string) $key;
                if (str_starts_with($key, $prefix)) {
                    $config[$assetCode][substr($key, strlen($prefix))] = $value;
                }
            }
            if (!isset($config[$assetCode])) {
                continue;
            }
            $config[$assetCode]['roles'] = [
                AssetAllocationService::ROLE_PAYMENT =>
                    $this->toBool($config[$assetCode]['allow_payment'] ?? false),
                AssetAllocationService::ROLE_DISCOUNT =>
                    $this->toBool($config[$assetCode]['allow_discount'] ?? false),
            ];
            $config[$assetCode]['enabled'] =
                $this->toBool($config[$assetCode]['enabled'] ?? false);
        }

        return $this->buildAssetPolicy($config);
    }

    public function startWithAssets(
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        callable $beginPayment,
    ): array {
        $requests = $command->getAssetRequests();
        if ($requests === []) {
            $payment = $beginPayment($snapshot);
            return [
                'payment' => $payment,
                'allocations' => [],
                'cash_snapshot' => $snapshot,
            ];
        }

        $prepared = $this->prepareAllocations($command, $snapshot, $requests);
        $cashSnapshot = $this->cashSnapshot($snapshot, $prepared);
        $paymentFailure = null;
        $work = function () use (
            $prepared,
            $cashSnapshot,
            $beginPayment,
            &$paymentFailure,
        ): array {
            $reserved = [];
            foreach ($prepared as $allocation) {
                $assetReservation = $this->getCustomerAssets()->reserve([
                    'customer_id' => $allocation['customer_id'],
                    'website_id' => $allocation['website_id'],
                    'asset_code' => $allocation['asset_code'],
                    'namespace' => $allocation['namespace'],
                    'amount_minor' => $allocation['asset_amount_minor'],
                    'event_id' => $allocation['reserve_event_id'],
                ]);
                $allocation['reservation_id'] = (string) (
                    $assetReservation['reservation']['reservation_id'] ?? ''
                );
                if ($allocation['reservation_id'] === '') {
                    throw new \RuntimeException('payment_asset_reservation_id_missing');
                }
                $allocation['reserved_amount_minor'] = $allocation['amount_minor'];
                $allocation['status'] = PaymentAllocation::STATUS_RESERVED;
                $reserved[] = $this->persistPreparedAllocation($allocation);
            }

            $payment = $beginPayment($cashSnapshot);
            if (empty($payment['ok'])) {
                $paymentFailure = $payment;
                throw new \RuntimeException(self::ERROR_START_ABORTED);
            }
            $intent = is_array($payment['intent'] ?? null) ? $payment['intent'] : [];
            $attempt = is_array($payment['attempt'] ?? null) ? $payment['attempt'] : [];
            $intentCode = trim((string) ($intent['intent_code'] ?? ''));
            if ($intentCode === '') {
                throw new \RuntimeException('payment_asset_intent_code_missing');
            }
            $attemptCode = trim((string) ($attempt['attempt_code'] ?? ''));
            foreach ($reserved as $index => $allocation) {
                $reserved[$index] = $this->linkAllocation(
                    $allocation,
                    $intentCode,
                    $attemptCode !== '' ? $attemptCode : null,
                );
            }

            return [
                'payment' => $payment,
                'allocations' => $reserved,
                'cash_snapshot' => $cashSnapshot,
            ];
        };

        try {
            return $this->isMemory()
                ? $work()
                : $this->transactions()->runWrite(
                    $this->newAllocation()->getConnection(),
                    $work,
                );
        } catch (Throwable $exception) {
            if ($this->isMemory()) {
                $this->compensateMemoryReservations($prepared);
            }
            if ($paymentFailure !== null) {
                return [
                    'payment' => $paymentFailure,
                    'allocations' => [],
                    'cash_snapshot' => $cashSnapshot,
                ];
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listByIntent(string $intentCode): array
    {
        $intentCode = trim($intentCode);
        if ($intentCode === '') {
            return [];
        }
        if ($this->isMemory()) {
            return array_values(array_filter(
                $this->memoryAllocations ?? [],
                static fn (array $row): bool =>
                    (string) ($row['intent_code'] ?? '') === $intentCode,
            ));
        }
        $rows = $this->newAllocation()->clear()
            ->where(PaymentAllocation::schema_fields_INTENT_CODE, $intentCode)
            ->order(PaymentAllocation::schema_fields_ID, 'asc')
            ->select()
            ->fetchArray();

        return array_map($this->normalizeStoredAllocation(...), $rows);
    }

    public function listByAttempt(string $attemptCode): array
    {
        $attemptCode = trim($attemptCode);
        if ($attemptCode === '') {
            return [];
        }
        if ($this->isMemory()) {
            return array_values(array_filter(
                $this->memoryAllocations ?? [],
                static fn (array $row): bool =>
                    (string) ($row['attempt_code'] ?? '') === $attemptCode,
            ));
        }
        $rows = $this->newAllocation()->clear()
            ->where(PaymentAllocation::schema_fields_ATTEMPT_CODE, $attemptCode)
            ->order(PaymentAllocation::schema_fields_ID, 'asc')
            ->select()
            ->fetchArray();

        return array_map($this->normalizeStoredAllocation(...), $rows);
    }

    public function applyTerminalEffect(PaymentEffectRecord $effect): array
    {
        if ($this->failNextTerminalEffect === $effect->effectType) {
            $this->failNextTerminalEffect = null;
            throw new \RuntimeException('payment_asset_effect_controlled_failure');
        }
        $operation = match ($effect->effectType) {
            'asset:commit:v1' => 'commit',
            'asset:release:v1' => 'release',
            default => throw new \InvalidArgumentException(
                'payment_asset_terminal_effect_unsupported:' . $effect->effectType,
            ),
        };
        $allocations = $effect->attemptCode !== ''
            ? $this->listByAttempt($effect->attemptCode)
            : $this->listByIntent($effect->intentCode);
        if ($allocations === []) {
            return [
                'ok' => true,
                'not_applicable' => true,
                'operation' => $operation,
                'allocations' => [],
            ];
        }

        $settled = [];
        foreach ($allocations as $allocation) {
            $settled[] = $this->applyAllocationSettlement(
                $allocation,
                $operation,
                $effect->effectKey,
            );
        }
        $snapshot = ['ok' => true, 'not_configured' => true];
        if ($operation === 'commit') {
            $sink = $this->getSnapshotSink();
            if ($sink instanceof OrderAssetAllocationSnapshotSinkInterface) {
                $snapshot = $sink->recordCommittedAllocations(
                    $effect->payableType,
                    $effect->payableId,
                    $effect->intentCode,
                    $effect->attemptCode !== '' ? $effect->attemptCode : null,
                    $settled,
                    $effect->effectKey,
                );
                if (empty($snapshot['ok'])) {
                    throw new \RuntimeException(
                        (string) (
                            $snapshot['error_code']
                            ?? 'payment_asset_order_snapshot_failed'
                        ),
                    );
                }
            }
        }

        return [
            'ok' => true,
            'not_applicable' => false,
            'operation' => $operation,
            'allocations' => $settled,
            'snapshot' => $snapshot,
        ];
    }

    public function returnCommittedAllocations(
        string $refundCaseUuid,
        array $allocations,
        string $effectKey,
    ): array {
        if ($this->failNextTerminalEffect === 'asset:return:v1') {
            $this->failNextTerminalEffect = null;
            throw new \RuntimeException('payment_asset_effect_controlled_failure');
        }
        $refundCaseUuid = trim($refundCaseUuid);
        $effectKey = trim($effectKey);
        if ($refundCaseUuid === '' || strlen($refundCaseUuid) > 36 || $effectKey === '') {
            throw new \InvalidArgumentException('payment_asset_refund_identity_invalid');
        }
        if ($allocations === []) {
            return [
                'ok' => true,
                'not_applicable' => true,
                'allocations' => [],
            ];
        }
        $work = function () use ($refundCaseUuid, $allocations, $effectKey): array {
            $returned = [];
            $seen = [];
            foreach ($allocations as $requested) {
                if (!is_array($requested)) {
                    throw new \InvalidArgumentException(
                        'payment_asset_refund_allocation_invalid',
                    );
                }
                $allocationCode = trim((string) ($requested['allocation_code'] ?? ''));
                $reservationId = trim((string) ($requested['reservation_id'] ?? ''));
                $paymentDelta = $this->positiveInteger(
                    $requested['payment_refund_amount_minor'] ?? null,
                    'payment_refund_amount_minor',
                );
                $assetDelta = $this->positiveInteger(
                    $requested['asset_return_amount_minor'] ?? null,
                    'asset_return_amount_minor',
                );
                $targetPayment = $this->positiveInteger(
                    $requested['cumulative_payment_refunded_minor'] ?? null,
                    'cumulative_payment_refunded_minor',
                );
                if ($allocationCode === '' || $reservationId === '') {
                    throw new \InvalidArgumentException(
                        'payment_asset_refund_allocation_identity_required',
                    );
                }
                if (isset($seen[$allocationCode])) {
                    throw new \LogicException(
                        'payment_asset_refund_allocation_duplicate:' . $allocationCode,
                    );
                }
                $seen[$allocationCode] = true;
                $stored = $this->findByCode($allocationCode);
                if ($stored === null
                    || !hash_equals(
                        (string) ($stored['reservation_id'] ?? ''),
                        $reservationId,
                    )
                ) {
                    throw new \LogicException(
                        'payment_asset_refund_allocation_not_found:' . $allocationCode,
                    );
                }
                if (!in_array((string) ($stored['status'] ?? ''), [
                    PaymentAllocation::STATUS_COMMITTED,
                    PaymentAllocation::STATUS_PARTIALLY_REFUNDED,
                    PaymentAllocation::STATUS_REFUNDED,
                ], true)) {
                    throw new \LogicException(
                        'payment_asset_refund_allocation_not_committed:'
                        . $allocationCode,
                    );
                }
                $currentPayment = (int) ($stored['refunded_amount_minor'] ?? 0);
                if ($targetPayment > (int) ($stored['amount_minor'] ?? 0)
                    || ($currentPayment !== $targetPayment
                        && $currentPayment + $paymentDelta !== $targetPayment)
                ) {
                    throw new \LogicException(
                        'payment_asset_refund_cumulative_conflict:' . $allocationCode,
                    );
                }
                $eventId = 'payasset:return:' . hash(
                    'sha256',
                    $effectKey . '|' . $allocationCode,
                );
                $this->getCustomerAssets()->returnCommitted(
                    $reservationId,
                    $assetDelta,
                    $eventId,
                );
                if ($currentPayment === $targetPayment) {
                    $returned[] = $stored + ['replayed' => true];
                    continue;
                }
                $returned[] = $this->markAllocationRefunded(
                    $stored,
                    $refundCaseUuid,
                    $targetPayment,
                ) + ['replayed' => false];
            }

            return [
                'ok' => true,
                'not_applicable' => false,
                'allocations' => $returned,
            ];
        };

        return $this->isMemory()
            ? $work()
            : $this->transactions()->runWrite(
                $this->newAllocation()->getConnection(),
                $work,
            );
    }

    /**
     * Pure helper retained for existing callers.
     *
     * @param array<string, mixed> $payable
     * @param list<array<string, mixed>> $assetRequests
     * @param array<string, mixed> $policy
     * @return list<array<string, mixed>>
     */
    public function reserveAllocations(
        array $payable,
        array $assetRequests,
        array $policy = [],
    ): array {
        $policy = $this->buildAssetPolicy($policy);
        $allocations = [];

        foreach ($assetRequests as $request) {
            $assetCode = $this->getAllocationService()->normalizeAssetCode(
                (string) ($request['asset_code'] ?? ''),
            );
            $role = $this->getAllocationService()->normalizeRole(
                (string) ($request['role'] ?? ''),
            );
            $this->assertAssetRoleAllowed($assetCode, $role, $policy);
            $this->assertAssetPayableAllowed($assetCode, $payable, $policy);
            $allocations[] = $this->getAllocationService()->reserve($request, $payable);
        }

        $this->getAllocationService()->assertNoDualRole($allocations);

        return $allocations;
    }

    /** @param list<array<string, mixed>> $allocations @param array<string, int> $amountsByAllocationCode */
    public function commitAllocations(array $allocations, array $amountsByAllocationCode = []): array
    {
        return $this->mapAllocations($allocations, $amountsByAllocationCode, 'commit');
    }

    /** @param list<array<string, mixed>> $allocations @param array<string, int> $amountsByAllocationCode */
    public function releaseAllocations(array $allocations, array $amountsByAllocationCode = []): array
    {
        return $this->mapAllocations($allocations, $amountsByAllocationCode, 'release');
    }

    /** @param list<array<string, mixed>> $allocations @param array<string, int> $amountsByAllocationCode */
    public function refundAllocations(array $allocations, array $amountsByAllocationCode = []): array
    {
        return $this->mapAllocations($allocations, $amountsByAllocationCode, 'refund');
    }

    /** @param list<array<string, mixed>> $allocations */
    public function calculateExternalPayableAmountMinor(
        array $snapshot,
        array $allocations,
    ): int {
        $amountMinor = (int) (
            $snapshot['amount_minor']
            ?? $snapshot['amounts']['payable_amount_minor']
            ?? 0
        );
        foreach ($allocations as $allocation) {
            $normalized = $this->getAllocationService()->normalizeAllocation(
                $allocation,
                $allocation,
            );
            $amountMinor -= $normalized['amount_minor'];
        }
        if ($amountMinor < 0) {
            throw new \LogicException(self::ERROR_ALLOCATION_EXCEEDS);
        }

        return $amountMinor;
    }

    /** @param list<array<string, mixed>> $allocations */
    public function groupAllocationsByRole(array $allocations): array
    {
        $grouped = [
            AssetAllocationService::ROLE_PAYMENT => [],
            AssetAllocationService::ROLE_DISCOUNT => [],
        ];
        foreach ($allocations as $allocation) {
            $normalized = $this->getAllocationService()->normalizeAllocation(
                $allocation,
                $allocation,
            );
            $grouped[$normalized['role']][] = $normalized;
        }

        return $grouped;
    }

    /** @param array<string, array<string, mixed>> $policy */
    public function assertAssetRoleAllowed(string $assetCode, string $role, array $policy): void
    {
        $assetCode = $this->getAllocationService()->normalizeAssetCode($assetCode);
        $role = $this->getAllocationService()->normalizeRole($role);
        $assetPolicy = $policy[$assetCode] ?? null;
        if (!is_array($assetPolicy)
            || empty($assetPolicy['enabled'])
            || empty($assetPolicy['roles'][$role])
        ) {
            throw new \LogicException('payment_asset_role_disabled:' . $assetCode . ':' . $role);
        }
    }

    /** @param array<string, mixed> $payable @param array<string, array<string, mixed>> $policy */
    public function assertAssetPayableAllowed(
        string $assetCode,
        array $payable,
        array $policy,
    ): void {
        $assetCode = $this->getAllocationService()->normalizeAssetCode($assetCode);
        $assetPolicy = $policy[$assetCode] ?? null;
        if (!is_array($assetPolicy)) {
            throw new \LogicException('payment_asset_policy_missing:' . $assetCode);
        }
        $allowed = $this->normalizeList($assetPolicy['allowed_payable_types'] ?? []);
        if ($allowed === []) {
            return;
        }
        $payableType = strtolower(trim((string) ($payable['payable_type'] ?? '')));
        if (!in_array($payableType, array_map('strtolower', $allowed), true)) {
            throw new \LogicException(
                'payment_asset_payable_type_not_allowed:' . $assetCode . ':' . $payableType,
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $requests
     * @return list<array<string, mixed>>
     */
    private function prepareAllocations(
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
        array $requests,
    ): array {
        $payer = $snapshot->getArray(PayableSnapshot::FIELD_PAYER);
        $customerId = trim((string) ($payer['actor_id'] ?? ''));
        if (strtolower(trim((string) ($payer['actor_type'] ?? ''))) !== 'customer'
            || $customerId === ''
            || $customerId === 'anonymous'
        ) {
            throw new \LogicException(self::ERROR_PAYER_REQUIRED);
        }
        $actor = $command->getActor();
        if ($actor === null
            || strtolower(trim($actor->getActorType())) !== 'customer'
            || !hash_equals($customerId, trim($actor->getActorId()))
        ) {
            throw new \LogicException(self::ERROR_PAYER_MISMATCH);
        }

        $scope = $snapshot->getArray('scope');
        $websiteId = (int) ($scope['website_id'] ?? $command->getWebsiteId());
        $policy = $this->getPolicyForScope($scope + [
            'website_id' => $websiteId,
            'store_id' => $command->getStoreId(),
        ]);
        $payable = $snapshot->getData();
        $payable['payable_type'] = $snapshot->getPayableType();
        $payable['payable_id'] = $snapshot->getPayableId();
        $seen = [];
        $prepared = [];
        $total = 0;
        foreach ($requests as $index => $request) {
            $assetCode = $this->getAllocationService()->normalizeAssetCode(
                (string) ($request['asset_code'] ?? ''),
            );
            $role = $this->getAllocationService()->normalizeRole(
                (string) ($request['role'] ?? ''),
            );
            $this->assertAssetRoleAllowed($assetCode, $role, $policy);
            $this->assertAssetPayableAllowed($assetCode, $payable, $policy);
            $sourceCode = strtolower(trim((string) ($request['source_code'] ?? $assetCode)));
            $duplicateKey = implode('|', [$assetCode, $sourceCode]);
            if (isset($seen[$duplicateKey])) {
                throw new \LogicException(self::ERROR_ALLOCATION_DUPLICATE . ':' . $duplicateKey);
            }
            $seen[$duplicateKey] = true;

            $assetAmountMinor = $this->positiveInteger(
                $request['asset_amount_minor'] ?? $request['amount_minor'] ?? null,
                'asset_amount_minor',
            );
            $ratio = (string) ($policy[$assetCode]['exchange_ratio'] ?? '0');
            $paymentAmountMinor = $this->multiplyRatio($assetAmountMinor, $ratio);
            if ($paymentAmountMinor <= 0) {
                throw new \LogicException(self::ERROR_CONVERSION);
            }
            if (array_key_exists('asset_amount_minor', $request)
                && array_key_exists('amount_minor', $request)
                && $this->positiveInteger($request['amount_minor'], 'amount_minor')
                    !== $paymentAmountMinor
            ) {
                throw new \LogicException(self::ERROR_CONVERSION_MISMATCH);
            }

            $allocationCode = 'asset_' . substr(hash('sha256', implode('|', [
                $command->getRequestHash(),
                (string) $index,
                $assetCode,
                $sourceCode,
                $role,
                (string) $assetAmountMinor,
                (string) $paymentAmountMinor,
            ])), 0, 40);
            $reserveEventId = 'payasset:reserve:' . hash(
                'sha256',
                $command->getRequestHash() . '|' . $allocationCode,
            );
            $normalized = $this->getAllocationService()->normalizeAllocation([
                ...$request,
                'allocation_code' => $allocationCode,
                'source_code' => $sourceCode,
                'amount_minor' => $paymentAmountMinor,
                'currency_code' => $snapshot->getCurrencyCode(),
                'precision' => $snapshot->getPrecision(),
                'status' => PaymentAllocation::STATUS_DRAFT,
                'metadata' => [
                    'asset_amount_minor' => $assetAmountMinor,
                    'exchange_ratio' => $ratio,
                ],
            ], $payable);
            $normalized += [
                'environment' => 'sandbox',
                'customer_id' => $customerId,
                'website_id' => $websiteId,
                'namespace' => strtolower(trim((string) ($request['namespace'] ?? 'live'))),
                'asset_amount_minor' => $assetAmountMinor,
                'reservation_id' => '',
                'reserve_event_id' => $reserveEventId,
                'request_hash' => $command->getRequestHash(),
                'version' => 0,
                'cas_token' => bin2hex(random_bytes(32)),
            ];
            if (!in_array($normalized['namespace'], ['live', 'sandbox'], true)) {
                throw new \LogicException('payment_asset_namespace_invalid');
            }
            $prepared[] = $normalized;
            $total += $paymentAmountMinor;

            if ($role === AssetAllocationService::ROLE_DISCOUNT) {
                $max = $this->multiplyRatioFloor(
                    $snapshot->getAmountMinor(),
                    (string) ($policy[$assetCode]['max_discount_ratio'] ?? '1'),
                );
                if ($paymentAmountMinor > $max) {
                    throw new \LogicException('payment_asset_discount_limit_exceeded');
                }
            }
        }
        $this->getAllocationService()->assertNoDualRole($prepared);
        if ($total > $snapshot->getAmountMinor()) {
            throw new \LogicException(self::ERROR_ALLOCATION_EXCEEDS);
        }

        return $prepared;
    }

    /** @param list<array<string, mixed>> $allocations */
    private function cashSnapshot(PayableSnapshot $snapshot, array $allocations): PayableSnapshot
    {
        $assetTotal = array_sum(array_map(
            static fn (array $allocation): int => (int) $allocation['amount_minor'],
            $allocations,
        ));
        $cash = $snapshot->getAmountMinor() - $assetTotal;
        if ($cash < 0) {
            throw new \LogicException(self::ERROR_ALLOCATION_EXCEEDS);
        }
        $data = $snapshot->getData();
        $amounts = is_array($data['amounts'] ?? null) ? $data['amounts'] : [];
        $amounts['original_payable_amount_minor'] = $snapshot->getAmountMinor();
        $amounts['asset_amount_minor'] = $assetTotal;
        $amounts['payable_amount_minor'] = $cash;
        $data[PayableSnapshot::FIELD_AMOUNT_MINOR] = $cash;
        $data['amounts'] = $amounts;
        $data['asset_allocations'] = array_map(
            static fn (array $allocation): array => [
                'allocation_code' => $allocation['allocation_code'],
                'asset_code' => $allocation['asset_code'],
                'source_code' => $allocation['source_code'],
                'role' => $allocation['role'],
                'asset_amount_minor' => $allocation['asset_amount_minor'],
                'amount_minor' => $allocation['amount_minor'],
                'currency_code' => $allocation['currency_code'],
                'namespace' => $allocation['namespace'],
            ],
            $allocations,
        );

        return PayableSnapshot::fromArray($data);
    }

    /** @param array<string, mixed> $allocation @return array<string, mixed> */
    private function persistPreparedAllocation(array $allocation): array
    {
        $existing = $this->findByCode((string) $allocation['allocation_code']);
        if ($existing !== null) {
            if (!hash_equals(
                (string) ($existing['request_hash'] ?? ''),
                (string) $allocation['request_hash'],
            ) || (string) ($existing['reservation_id'] ?? '')
                !== (string) $allocation['reservation_id']
            ) {
                throw new \LogicException('payment_asset_allocation_idempotency_conflict');
            }
            return $existing;
        }
        $now = gmdate('Y-m-d H:i:s');
        $row = [
            ...$allocation,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($this->isMemory()) {
            $this->memoryAllocations[$row['allocation_code']] = $row;
            return $row;
        }

        $this->newAllocation()->clear()->setData([
            PaymentAllocation::schema_fields_ALLOCATION_CODE => $row['allocation_code'],
            PaymentAllocation::schema_fields_ENVIRONMENT => $row['environment'],
            PaymentAllocation::schema_fields_PAYABLE_TYPE => $row['payable_type'],
            PaymentAllocation::schema_fields_PAYABLE_ID => $row['payable_id'],
            PaymentAllocation::schema_fields_INTENT_CODE => null,
            PaymentAllocation::schema_fields_ATTEMPT_CODE => null,
            PaymentAllocation::schema_fields_SOURCE_TYPE => PaymentAllocation::SOURCE_ASSET,
            PaymentAllocation::schema_fields_SOURCE_CODE => $row['source_code'],
            PaymentAllocation::schema_fields_ASSET_CODE => $row['asset_code'],
            PaymentAllocation::schema_fields_CUSTOMER_ID => $row['customer_id'],
            PaymentAllocation::schema_fields_WEBSITE_ID => $row['website_id'],
            PaymentAllocation::schema_fields_NAMESPACE => $row['namespace'],
            PaymentAllocation::schema_fields_RESERVATION_ID => $row['reservation_id'],
            PaymentAllocation::schema_fields_RESERVE_EVENT_ID => $row['reserve_event_id'],
            PaymentAllocation::schema_fields_ROLE => $row['role'],
            PaymentAllocation::schema_fields_AMOUNT_MINOR => $row['amount_minor'],
            PaymentAllocation::schema_fields_ASSET_AMOUNT_MINOR => $row['asset_amount_minor'],
            PaymentAllocation::schema_fields_CURRENCY_CODE => $row['currency_code'],
            PaymentAllocation::schema_fields_PRECISION => $row['precision'],
            PaymentAllocation::schema_fields_RESERVED_AMOUNT_MINOR =>
                $row['reserved_amount_minor'],
            PaymentAllocation::schema_fields_COMMITTED_AMOUNT_MINOR => 0,
            PaymentAllocation::schema_fields_RELEASED_AMOUNT_MINOR => 0,
            PaymentAllocation::schema_fields_REFUNDED_AMOUNT_MINOR => 0,
            PaymentAllocation::schema_fields_STATUS => PaymentAllocation::STATUS_RESERVED,
            PaymentAllocation::schema_fields_REQUEST_HASH => $row['request_hash'],
            PaymentAllocation::schema_fields_VERSION => 0,
            PaymentAllocation::schema_fields_CAS_TOKEN => $row['cas_token'],
            PaymentAllocation::schema_fields_ALLOCATION_SNAPSHOT => json_encode(
                $row,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            PaymentAllocation::schema_fields_METADATA_JSON => json_encode(
                $row['metadata'] ?? [],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
            PaymentAllocation::schema_fields_CREATED_AT => $now,
            PaymentAllocation::schema_fields_UPDATED_AT => $now,
        ])->save();

        return $this->findByCode((string) $row['allocation_code'])
            ?? throw new \RuntimeException('payment_asset_allocation_write_not_visible');
    }

    /** @param array<string, mixed> $allocation @return array<string, mixed> */
    private function linkAllocation(
        array $allocation,
        string $intentCode,
        ?string $attemptCode,
    ): array {
        if (($allocation['intent_code'] ?? null) !== null
            && ((string) $allocation['intent_code'] !== $intentCode
                || (string) ($allocation['attempt_code'] ?? '') !== (string) $attemptCode)
        ) {
            throw new \LogicException('payment_asset_allocation_link_conflict');
        }
        $nextVersion = (int) ($allocation['version'] ?? 0) + 1;
        $nextToken = bin2hex(random_bytes(32));
        $updatedAt = gmdate('Y-m-d H:i:s');
        if ($this->isMemory()) {
            $allocation['intent_code'] = $intentCode;
            $allocation['attempt_code'] = $attemptCode;
            $allocation['version'] = $nextVersion;
            $allocation['cas_token'] = $nextToken;
            $allocation['updated_at'] = $updatedAt;
            $this->memoryAllocations[$allocation['allocation_code']] = $allocation;
            return $allocation;
        }
        $this->newAllocation()->getQuery(false)
            ->where(PaymentAllocation::schema_fields_ALLOCATION_CODE, $allocation['allocation_code'])
            ->where(PaymentAllocation::schema_fields_VERSION, $allocation['version'])
            ->where(PaymentAllocation::schema_fields_CAS_TOKEN, $allocation['cas_token'])
            ->update([
                PaymentAllocation::schema_fields_INTENT_CODE => $intentCode,
                PaymentAllocation::schema_fields_ATTEMPT_CODE => $attemptCode,
                PaymentAllocation::schema_fields_VERSION => $nextVersion,
                PaymentAllocation::schema_fields_CAS_TOKEN => $nextToken,
                PaymentAllocation::schema_fields_UPDATED_AT => $updatedAt,
            ])
            ->fetch();
        $saved = $this->findByCode((string) $allocation['allocation_code']);
        if ($saved === null || !hash_equals($nextToken, (string) $saved['cas_token'])) {
            throw new \RuntimeException('payment_asset_allocation_cas_conflict');
        }

        return $saved;
    }

    /** @param list<array<string, mixed>> $prepared */
    private function compensateMemoryReservations(array $prepared): void
    {
        foreach ($prepared as $allocation) {
            $stored = $this->memoryAllocations[$allocation['allocation_code']] ?? null;
            if (!is_array($stored) || ($stored['status'] ?? '') !== PaymentAllocation::STATUS_RESERVED) {
                continue;
            }
            try {
                $this->getCustomerAssets()->release(
                    (string) $stored['reservation_id'],
                    (string) $stored['reserve_event_id'] . ':abort',
                );
                $stored['released_amount_minor'] = (int) $stored['amount_minor'];
                $stored['status'] = PaymentAllocation::STATUS_RELEASED;
                $this->memoryAllocations[$stored['allocation_code']] = $stored;
            } catch (Throwable) {
                // Test seam only. Durable production rollback does not compensate.
            }
        }
    }

    /**
     * @param array<string, mixed> $allocation
     * @return array<string, mixed>
     */
    private function applyAllocationSettlement(
        array $allocation,
        string $operation,
        string $effectKey,
    ): array {
        $targetStatus = $operation === 'commit'
            ? PaymentAllocation::STATUS_COMMITTED
            : PaymentAllocation::STATUS_RELEASED;
        $eventId = 'payasset:' . $operation . ':' . hash(
            'sha256',
            $effectKey . '|' . $allocation['allocation_code'],
        );
        if ($operation === 'commit') {
            $this->getCustomerAssets()->commit(
                (string) $allocation['reservation_id'],
                $eventId,
            );
        } else {
            $this->getCustomerAssets()->release(
                (string) $allocation['reservation_id'],
                $eventId,
            );
        }
        if ((string) ($allocation['status'] ?? '') === $targetStatus) {
            return $allocation;
        }
        if ((string) ($allocation['status'] ?? '') !== PaymentAllocation::STATUS_RESERVED) {
            throw new \LogicException(
                'payment_asset_allocation_terminal_conflict:'
                . (string) ($allocation['status'] ?? ''),
            );
        }

        $nextVersion = (int) ($allocation['version'] ?? 0) + 1;
        $nextToken = bin2hex(random_bytes(32));
        $updatedAt = gmdate('Y-m-d H:i:s');
        $amountField = $operation === 'commit'
            ? 'committed_amount_minor'
            : 'released_amount_minor';
        if ($this->isMemory()) {
            $current = $this->memoryAllocations[$allocation['allocation_code']] ?? null;
            if (!is_array($current)
                || (int) ($current['version'] ?? -1) !== (int) $allocation['version']
                || !hash_equals(
                    (string) ($current['cas_token'] ?? ''),
                    (string) $allocation['cas_token'],
                )
            ) {
                throw new \RuntimeException('payment_asset_allocation_cas_conflict');
            }
            $current[$amountField] = (int) $current['amount_minor'];
            $current['status'] = $targetStatus;
            $current['version'] = $nextVersion;
            $current['cas_token'] = $nextToken;
            $current['updated_at'] = $updatedAt;
            $this->memoryAllocations[$allocation['allocation_code']] = $current;
            return $current;
        }

        $schemaAmountField = $operation === 'commit'
            ? PaymentAllocation::schema_fields_COMMITTED_AMOUNT_MINOR
            : PaymentAllocation::schema_fields_RELEASED_AMOUNT_MINOR;
        $this->newAllocation()->getQuery(false)
            ->where(
                PaymentAllocation::schema_fields_ALLOCATION_CODE,
                $allocation['allocation_code'],
            )
            ->where(PaymentAllocation::schema_fields_VERSION, $allocation['version'])
            ->where(PaymentAllocation::schema_fields_CAS_TOKEN, $allocation['cas_token'])
            ->where(
                PaymentAllocation::schema_fields_STATUS,
                PaymentAllocation::STATUS_RESERVED,
            )
            ->update([
                $schemaAmountField => $allocation['amount_minor'],
                PaymentAllocation::schema_fields_STATUS => $targetStatus,
                PaymentAllocation::schema_fields_VERSION => $nextVersion,
                PaymentAllocation::schema_fields_CAS_TOKEN => $nextToken,
                PaymentAllocation::schema_fields_UPDATED_AT => $updatedAt,
            ])
            ->fetch();
        $saved = $this->findByCode((string) $allocation['allocation_code']);
        if ($saved === null || !hash_equals($nextToken, (string) $saved['cas_token'])) {
            throw new \RuntimeException('payment_asset_allocation_cas_conflict');
        }

        return $saved;
    }

    /** @param array<string, mixed> $allocation @return array<string, mixed> */
    private function markAllocationRefunded(
        array $allocation,
        string $refundCaseUuid,
        int $targetPaymentMinor,
    ): array {
        $targetStatus = $targetPaymentMinor === (int) $allocation['amount_minor']
            ? PaymentAllocation::STATUS_REFUNDED
            : PaymentAllocation::STATUS_PARTIALLY_REFUNDED;
        $nextVersion = (int) ($allocation['version'] ?? 0) + 1;
        $nextToken = bin2hex(random_bytes(32));
        $updatedAt = gmdate('Y-m-d H:i:s');
        if ($this->isMemory()) {
            $current = $this->memoryAllocations[$allocation['allocation_code']] ?? null;
            if (!is_array($current)
                || (int) ($current['version'] ?? -1) !== (int) $allocation['version']
                || !hash_equals(
                    (string) ($current['cas_token'] ?? ''),
                    (string) $allocation['cas_token'],
                )
            ) {
                throw new \RuntimeException('payment_asset_allocation_cas_conflict');
            }
            $current['refund_code'] = $refundCaseUuid;
            $current['refunded_amount_minor'] = $targetPaymentMinor;
            $current['status'] = $targetStatus;
            $current['version'] = $nextVersion;
            $current['cas_token'] = $nextToken;
            $current['updated_at'] = $updatedAt;
            $this->memoryAllocations[$allocation['allocation_code']] = $current;
            return $current;
        }

        $this->newAllocation()->getQuery(false)
            ->where(
                PaymentAllocation::schema_fields_ALLOCATION_CODE,
                $allocation['allocation_code'],
            )
            ->where(PaymentAllocation::schema_fields_VERSION, $allocation['version'])
            ->where(PaymentAllocation::schema_fields_CAS_TOKEN, $allocation['cas_token'])
            ->update([
                PaymentAllocation::schema_fields_REFUND_CODE => $refundCaseUuid,
                PaymentAllocation::schema_fields_REFUNDED_AMOUNT_MINOR =>
                    $targetPaymentMinor,
                PaymentAllocation::schema_fields_STATUS => $targetStatus,
                PaymentAllocation::schema_fields_VERSION => $nextVersion,
                PaymentAllocation::schema_fields_CAS_TOKEN => $nextToken,
                PaymentAllocation::schema_fields_UPDATED_AT => $updatedAt,
            ])
            ->fetch();
        $saved = $this->findByCode((string) $allocation['allocation_code']);
        if ($saved === null || !hash_equals($nextToken, (string) $saved['cas_token'])) {
            throw new \RuntimeException('payment_asset_allocation_cas_conflict');
        }

        return $saved;
    }

    /** @return array<string, mixed>|null */
    private function findByCode(string $allocationCode): ?array
    {
        if ($this->isMemory()) {
            return $this->memoryAllocations[$allocationCode] ?? null;
        }
        $model = $this->newAllocation()->clear()
            ->where(PaymentAllocation::schema_fields_ALLOCATION_CODE, $allocationCode)
            ->find()
            ->fetch();

        return $model->getId() ? $this->normalizeStoredAllocation($model->getData()) : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeStoredAllocation(array $row): array
    {
        foreach ([
            'website_id',
            'amount_minor',
            'asset_amount_minor',
            'precision',
            'reserved_amount_minor',
            'committed_amount_minor',
            'released_amount_minor',
            'refunded_amount_minor',
            'version',
        ] as $field) {
            $row[$field] = (int) ($row[$field] ?? 0);
        }
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        $row['metadata'] = is_array($metadata) ? $metadata : [];

        return $row;
    }

    /** @param list<array<string, mixed>> $allocations @param array<string, int> $amountsByAllocationCode */
    private function mapAllocations(
        array $allocations,
        array $amountsByAllocationCode,
        string $operation,
    ): array {
        $mapped = [];
        foreach ($allocations as $allocation) {
            $normalized = $this->getAllocationService()->normalizeAllocation(
                $allocation,
                $allocation,
            );
            $amountMinor = $amountsByAllocationCode[$normalized['allocation_code']] ?? null;
            $mapped[] = match ($operation) {
                'commit' => $this->getAllocationService()->commit($normalized, $amountMinor),
                'release' => $this->getAllocationService()->release($normalized, $amountMinor),
                'refund' => $this->getAllocationService()->refund($normalized, $amountMinor),
                default => throw new \InvalidArgumentException(
                    'payment_asset_operation_invalid:' . $operation,
                ),
            };
        }

        return $mapped;
    }

    private function normalizeRatio(mixed $value, bool $allowAboveOne = true): string
    {
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
        }
        $value = trim((string) $value);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.(\d{1,8}))?$/', $value)) {
            throw new \InvalidArgumentException(self::ERROR_CONVERSION);
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $normalized = $fraction === '' ? $integer : $integer . '.' . $fraction;
        if (!$allowAboveOne && (float) $normalized > 1.0) {
            throw new \InvalidArgumentException(self::ERROR_CONVERSION);
        }

        return $normalized;
    }

    private function multiplyRatio(int $amount, string $ratio): int
    {
        [$numerator, $scale] = $this->ratioParts($ratio);
        if ($numerator <= 0 || $amount > intdiv(PHP_INT_MAX - intdiv($scale, 2), $numerator)) {
            throw new \OverflowException(self::ERROR_CONVERSION);
        }

        return intdiv($amount * $numerator + intdiv($scale, 2), $scale);
    }

    private function multiplyRatioFloor(int $amount, string $ratio): int
    {
        [$numerator, $scale] = $this->ratioParts($ratio);
        if ($numerator < 0 || ($numerator > 0 && $amount > intdiv(PHP_INT_MAX, $numerator))) {
            throw new \OverflowException(self::ERROR_CONVERSION);
        }

        return intdiv($amount * $numerator, $scale);
    }

    /** @return array{0:int,1:int} */
    private function ratioParts(string $ratio): array
    {
        $ratio = $this->normalizeRatio($ratio);
        $parts = explode('.', $ratio, 2);
        $fraction = $parts[1] ?? '';
        $scale = 10 ** strlen($fraction);
        $numerator = (int) ($parts[0] . $fraction);

        return [$numerator, $scale];
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (is_float($value)
            || (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value)))
        ) {
            throw new \InvalidArgumentException($field . '_must_be_integer_minor_unit');
        }
        $amount = (int) $value;
        if ($amount <= 0) {
            throw new \InvalidArgumentException($field . '_must_be_positive');
        }

        return $amount;
    }

    private function getCustomerAssets(): CustomerAssetFacadeInterface
    {
        if ($this->customerAssets instanceof CustomerAssetFacadeInterface) {
            return $this->customerAssets;
        }
        $resolver = $this->providerResolver;
        if (!$resolver instanceof RuntimeProviderResolver) {
            $resolver = ObjectManager::getInstance(RuntimeProviderResolver::class);
            $this->providerResolver = $resolver;
        }
        $provider = $resolver->resolve(CustomerAssetFacadeInterface::class);
        if (!$provider instanceof CustomerAssetFacadeInterface) {
            throw new \LogicException(self::ERROR_CAPABILITY_REQUIRED);
        }
        $this->customerAssets = $provider;

        return $provider;
    }

    private function getSnapshotSink(): ?OrderAssetAllocationSnapshotSinkInterface
    {
        if ($this->snapshotSink instanceof OrderAssetAllocationSnapshotSinkInterface) {
            return $this->snapshotSink;
        }
        $resolver = $this->providerResolver;
        if (!$resolver instanceof RuntimeProviderResolver) {
            try {
                $resolver = ObjectManager::getInstance(RuntimeProviderResolver::class);
                $this->providerResolver = $resolver;
            } catch (Throwable) {
                return null;
            }
        }
        $resolution = $resolver->resolveDetailed(
            OrderAssetAllocationSnapshotSinkInterface::class,
        );
        if ($resolution->status === 'not_configured') {
            return null;
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof OrderAssetAllocationSnapshotSinkInterface
        ) {
            throw new \RuntimeException(
                $resolution->errorCode !== ''
                    ? $resolution->errorCode
                    : 'payment_asset_snapshot_provider_unavailable',
            );
        }
        $this->snapshotSink = $resolution->provider;

        return $this->snapshotSink;
    }

    private function transactions(): WriteIntentTransactionCoordinatorInterface
    {
        $transactions = $this->transactionCoordinator
            ?? ObjectManager::getInstance(WriteIntentTransactionCoordinatorInterface::class);
        if (!$transactions instanceof WriteIntentTransactionCoordinatorInterface) {
            throw new \LogicException('payment_asset_transaction_coordinator_unavailable');
        }

        return $transactions;
    }

    private function newAllocation(): PaymentAllocation
    {
        /** @var PaymentAllocation $allocation */
        $allocation = ObjectManager::create(PaymentAllocation::class, [], false);
        return $allocation;
    }

    private function isMemory(): bool
    {
        return $this->memoryAllocations !== null;
    }

    private function getAllocationService(): AssetAllocationService
    {
        return $this->allocationService ?? new AssetAllocationService();
    }

    private function getScopeConfigService(): PaymentScopeConfigService
    {
        return $this->scopeConfigService ?? new PaymentScopeConfigService();
    }

    private function getSystemConfig(): SystemConfig
    {
        if ($this->systemConfig === null) {
            $this->systemConfig = ObjectManager::getInstance(SystemConfig::class);
        }

        return $this->systemConfig;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /** @return list<string> */
    private function normalizeList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string) $value));
        if (!is_array($items)) {
            return [];
        }
        $normalized = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }
}
