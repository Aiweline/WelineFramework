<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\CustomerAsset\Api\CustomerAssetConflictInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PayableSnapshot;
use Weline\Payment\Api\Data\PaymentOperationResult;
use Weline\Payment\Api\Data\PaymentQueryCommand;
use Weline\Payment\Api\Data\PaymentResumeCommand;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Api\PaymentAssetFacadeInterface;
use Weline\Payment\Api\PaymentFacadeV2Interface;

/**
 * Payment Facade V2（MOD-P2F-001 / MOD-P2F-002）。
 * start 第一事务只写 Intent/Attempt/outbox；Provider 由 Consumer 事务外执行。
 */
final class PaymentFacadeV2 implements PaymentFacadeV2Interface
{
    public const ERROR_ENTRY_CLOSED = 'payment_entry_closed';
    public const ERROR_METHOD_REQUIRED = 'payment_method_required';
    public const ERROR_IDEMPOTENCY_REQUIRED = 'payment_idempotency_required';
    public const ERROR_REQUEST_HASH_REQUIRED = 'payment_request_hash_required';
    public const ERROR_PAYABLE_REQUIRED = 'payment_payable_required';
    public const ERROR_NOT_ELIGIBLE = 'payment_not_eligible';
    public const ERROR_SCOPE_MISMATCH = 'payment_scope_mismatch';
    public const ERROR_RETURN_URL = 'payment_return_url_not_allowed';
    public const ERROR_IDEMPOTENCY_CONFLICT = 'payment_idempotency_conflict';
    public const ERROR_INTENT_NOT_FOUND = 'payment_intent_not_found';
    public const ERROR_RESUME_NOT_READY = 'payment_resume_not_ready';
    public const ERROR_ORCHESTRATOR_UNAVAILABLE = 'payment_orchestrator_unavailable';
    public const ERROR_ASSET_ORCHESTRATOR_UNAVAILABLE = 'payment_asset_orchestrator_unavailable';

    public const STATUS_LOCAL_ACCEPTED = PaymentIntentOrchestrator::STATUS_LOCAL_ACCEPTED;
    public const STATUS_AWAITING_PROVIDER = PaymentIntentOrchestrator::STATUS_AWAITING_PROVIDER;
    public const STATUS_FAILED = PaymentIntentOrchestrator::STATUS_FAILED;
    public const STATUS_CLOSED = 'closed';
    public const STATUS_UNAVAILABLE = 'unavailable';

    private bool $entryEnabled = false;

    /** @var array<string, string> */
    private array $merchantAccounts = [];

    private ?PaymentIntentOrchestrator $orchestrator = null;

    public function __construct(
        private readonly PayableResolverRegistry $resolverRegistry,
        ?PaymentIntentOrchestrator $orchestrator,
        private ?PaymentAssetFacadeInterface $assetPayments = null,
    ) {
        $this->orchestrator = $orchestrator;
    }

    public static function forTesting(
        PayableResolverRegistry $registry,
        ?PaymentIntentOrchestrator $orchestrator = null,
        ?PaymentAssetFacadeInterface $assetPayments = null,
    ): self {
        return new self(
            $registry,
            $orchestrator ?? PaymentIntentOrchestrator::forTesting(),
            $assetPayments,
        );
    }

    public function orchestrator(): PaymentIntentOrchestrator
    {
        if ($this->orchestrator === null) {
            throw new \RuntimeException(__('PaymentFacadeV2 orchestrator 不可用'));
        }

        return $this->orchestrator;
    }

    public function setEntryEnabled(bool $enabled): void
    {
        $this->entryEnabled = $enabled;
    }

    public function isEntryEnabled(): bool
    {
        return $this->entryEnabled;
    }

    public function setMerchantAccount(string $methodCode, string $accountCode): void
    {
        $methodCode = strtolower(trim($methodCode));
        if ($methodCode === '') {
            throw new \InvalidArgumentException(self::ERROR_METHOD_REQUIRED);
        }
        $this->merchantAccounts[$methodCode] = trim($accountCode);
    }

    public function start(PaymentStartCommand $command): PaymentOperationResult
    {
        if (!$this->entryEnabled) {
            return $this->error(self::ERROR_ENTRY_CLOSED, self::STATUS_CLOSED, true);
        }
        $orch = $this->orchestrator;
        if ($orch === null) {
            return $this->error(
                self::ERROR_ORCHESTRATOR_UNAVAILABLE,
                self::STATUS_UNAVAILABLE,
                false,
            );
        }

        $payableType = $command->getPayableType();
        $payableId = $command->getPayableId();
        $methodCode = $command->getMethodCode();
        $idem = $command->getIdempotencyKey();
        $hash = $command->getRequestHash();

        if ($payableType === '' || $payableId === '') {
            return $this->error(self::ERROR_PAYABLE_REQUIRED);
        }
        if ($methodCode === '') {
            return $this->error(self::ERROR_METHOD_REQUIRED);
        }
        if ($idem === '') {
            return $this->error(self::ERROR_IDEMPOTENCY_REQUIRED);
        }
        if ($hash === '') {
            return $this->error(self::ERROR_REQUEST_HASH_REQUIRED);
        }

        $returnUrl = $command->getReturnUrl();
        if ($returnUrl !== null) {
            $allowed = $command->getAllowedReturnUrls();
            if ($allowed === [] || !\in_array($returnUrl, $allowed, true)) {
                return $this->error(self::ERROR_RETURN_URL);
            }
        }

        $actor = $command->getActor();
        $snapshot = $this->resolverRegistry->resolveSnapshot($payableType, $payableId, $actor);
        $resolver = $this->resolverRegistry->getResolver($payableType);

        if (!$resolver->canPay($snapshot, $actor ?? Actor::fromArray([
            Actor::FIELD_ACTOR_TYPE => 'guest',
            Actor::FIELD_ACTOR_ID => 'anonymous',
        ]))) {
            return $this->error(
                self::ERROR_NOT_ELIGIBLE,
                self::STATUS_FAILED,
                true,
                payableType: $payableType,
                payableId: $payableId,
            );
        }

        $scope = $this->extractScope($snapshot);
        if ((int) $scope['website_id'] !== $command->getWebsiteId()
            || (int) $scope['store_id'] !== $command->getStoreId()
        ) {
            return $this->error(
                self::ERROR_SCOPE_MISMATCH,
                self::STATUS_FAILED,
                true,
                payableType: $payableType,
                payableId: $payableId,
                scope: $scope,
            );
        }

        // Freeze scope into snapshot copy used by orchestrator.
        $frozen = PayableSnapshot::fromArray(array_merge($snapshot->getData(), [
            'scope' => $scope,
            PayableSnapshot::FIELD_VERSION => $snapshot->getVersion() !== ''
                ? $snapshot->getVersion()
                : $this->computeSnapshotVersion($snapshot, $scope),
        ]));
        $command = PaymentStartCommand::create(
            payableType: $payableType,
            payableId: $payableId,
            methodCode: $methodCode,
            idempotencyKey: $idem,
            requestHash: $this->computeEffectiveRequestHash($command, $frozen),
            actor: $command->getActor(),
            websiteId: $command->getWebsiteId(),
            storeId: $command->getStoreId(),
            returnUrl: $command->getReturnUrl(),
            allowedReturnUrls: $command->getAllowedReturnUrls(),
            assetRequests: $command->getAssetRequests(),
        );

        $merchantAccount = $this->merchantAccounts[$methodCode] ?? ('acct_' . $methodCode);
        try {
            if ($command->getAssetRequests() !== []) {
                $assetPayments = $this->assetPayments();
                if (!$assetPayments instanceof PaymentAssetFacadeInterface) {
                    return $this->error(
                        self::ERROR_ASSET_ORCHESTRATOR_UNAVAILABLE,
                        self::STATUS_UNAVAILABLE,
                        false,
                        payableType: $payableType,
                        payableId: $payableId,
                        scope: $scope,
                    );
                }
                $started = $assetPayments->startWithAssets(
                    $command,
                    $frozen,
                    static fn (PayableSnapshot $cashSnapshot): array =>
                        $cashSnapshot->getAmountMinor() === 0
                            ? $orch->beginZeroAmount(
                                $command,
                                $cashSnapshot,
                                $merchantAccount,
                            )
                            : $orch->beginStart(
                                $command,
                                $cashSnapshot,
                                $merchantAccount,
                            ),
                );
                $begun = $started['payment'];
            } else {
                $begun = $orch->beginStart($command, $frozen, $merchantAccount);
            }
        } catch (CustomerAssetConflictInterface $exception) {
            return $this->error(
                $exception->getErrorCode(),
                self::STATUS_FAILED,
                true,
                payableType: $payableType,
                payableId: $payableId,
                scope: $scope,
            );
        } catch (\InvalidArgumentException|\LogicException|\OverflowException $exception) {
            return $this->error(
                $exception->getMessage(),
                self::STATUS_FAILED,
                true,
                payableType: $payableType,
                payableId: $payableId,
                scope: $scope,
            );
        }
        if (!$begun['ok']) {
            return $this->error(
                (string) $begun['error_code'],
                self::STATUS_FAILED,
                true,
                payableType: $payableType,
                payableId: $payableId,
                scope: $scope,
            );
        }

        /** @var array<string, mixed> $intent */
        $intent = $begun['intent'];

        return $orch->toOperationResult($intent, $begun['attempt'] ?? null);
    }

    public function resume(PaymentResumeCommand $command): PaymentOperationResult
    {
        if (!$this->entryEnabled) {
            return $this->error(self::ERROR_ENTRY_CLOSED, self::STATUS_CLOSED, true);
        }
        $orch = $this->orchestrator;
        if ($orch === null) {
            return $this->error(
                self::ERROR_ORCHESTRATOR_UNAVAILABLE,
                self::STATUS_UNAVAILABLE,
                false,
            );
        }
        $intentCode = $command->getIntentCode();
        $intent = $orch->getIntent($intentCode);
        if ($intent === null) {
            return $this->error(self::ERROR_INTENT_NOT_FOUND);
        }

        if (($intent['status'] ?? '') === self::STATUS_LOCAL_ACCEPTED) {
            $intent['status'] = self::STATUS_AWAITING_PROVIDER;
            $orch->updateIntent($intent);
            $attempt = $orch->getAttempt((string) ($intent['current_attempt_code'] ?? ''));

            return $orch->toOperationResult($intent, $attempt);
        }

        return $this->error(
            self::ERROR_RESUME_NOT_READY,
            payableType: (string) ($intent['payable_type'] ?? ''),
            payableId: (string) ($intent['payable_id'] ?? ''),
        );
    }

    public function query(PaymentQueryCommand $command): PaymentOperationResult
    {
        $orch = $this->orchestrator;
        if ($orch === null) {
            return $this->error(
                self::ERROR_ORCHESTRATOR_UNAVAILABLE,
                self::STATUS_UNAVAILABLE,
                false,
            );
        }
        $intentCode = $command->getIntentCode();
        $intent = null;
        if ($intentCode !== null && $intentCode !== '') {
            $intent = $orch->getIntent($intentCode);
        } else {
            $type = $command->getPayableType();
            $id = $command->getPayableId();
            if ($type === null || $id === null) {
                return $this->error(self::ERROR_PAYABLE_REQUIRED);
            }
            $intent = $orch->findIntentByPayable($type, $id);
        }

        if ($intent === null) {
            return $this->error(self::ERROR_INTENT_NOT_FOUND);
        }

        return $orch->toOperationResult($intent);
    }

    /**
     * @return array{website_id:int,store_id:int,currency:string,locale?:string}
     */
    private function extractScope(PayableSnapshot $snapshot): array
    {
        $scope = $snapshot->getArray('scope');
        if ($scope === []) {
            $scope = [
                'website_id' => (int) ($snapshot->getData('website_id') ?? 0),
                'store_id' => (int) ($snapshot->getData('store_id') ?? 0),
                'currency' => $snapshot->getCurrencyCode(),
            ];
        }

        return [
            'website_id' => (int) ($scope['website_id'] ?? 0),
            'store_id' => (int) ($scope['store_id'] ?? 0),
            'currency' => strtoupper((string) ($scope['currency'] ?? $snapshot->getCurrencyCode())),
            'locale' => (string) ($scope['locale'] ?? $snapshot->getString(PayableSnapshot::FIELD_LANGUAGE_CODE)),
        ];
    }

    /**
     * @param array{website_id:int,store_id:int,currency:string,locale?:string} $scope
     */
    private function computeSnapshotVersion(PayableSnapshot $snapshot, array $scope): string
    {
        $payload = [
            'payable_type' => $snapshot->getPayableType(),
            'payable_id' => $snapshot->getPayableId(),
            'amount_minor' => $snapshot->getAmountMinor(),
            'currency' => $snapshot->getCurrencyCode(),
            'items' => $snapshot->getItems(),
            'scope' => $scope,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function computeEffectiveRequestHash(
        PaymentStartCommand $command,
        PayableSnapshot $snapshot,
    ): string {
        $actor = $command->getActor();
        $allowedReturnUrls = $command->getAllowedReturnUrls();
        sort($allowedReturnUrls);
        $payload = [
            'caller_request_hash' => $command->getRequestHash(),
            'payable_type' => $command->getPayableType(),
            'payable_id' => $command->getPayableId(),
            'method_code' => $command->getMethodCode(),
            'website_id' => $command->getWebsiteId(),
            'store_id' => $command->getStoreId(),
            'return_url' => $command->getReturnUrl(),
            'allowed_return_urls' => $allowedReturnUrls,
            'actor_type' => $actor?->getActorType(),
            'actor_id' => $actor?->getActorId(),
            'snapshot_version' => $snapshot->getVersion(),
            'asset_requests' => $command->getAssetRequests(),
        ];

        return hash(
            'sha256',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function error(
        string $code,
        string $status = self::STATUS_FAILED,
        bool $terminal = true,
        ?string $payableType = null,
        ?string $payableId = null,
        array $scope = [],
    ): PaymentOperationResult {
        return PaymentOperationResult::create(
            intentCode: null,
            attemptCode: null,
            status: $status,
            terminal: $terminal,
            nextActionType: PaymentOperationResult::NEXT_NONE,
            errorCode: $code,
            scope: $scope,
            payableType: $payableType,
            payableId: $payableId,
        );
    }

    private function assetPayments(): ?PaymentAssetFacadeInterface
    {
        if ($this->assetPayments instanceof PaymentAssetFacadeInterface) {
            return $this->assetPayments;
        }
        try {
            $service = ObjectManager::getInstance(PaymentAssetFacadeInterface::class);
            if ($service instanceof PaymentAssetFacadeInterface) {
                $this->assetPayments = $service;
            }
        } catch (\Throwable) {
            return null;
        }

        return $this->assetPayments;
    }
}
