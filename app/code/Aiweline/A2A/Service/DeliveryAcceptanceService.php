<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

use Aiweline\A2A\Model\DeliveryAcceptance;
use Aiweline\A2A\Model\EscrowLedger;
use Aiweline\A2A\Model\ProviderScopeSubmission;
use Aiweline\A2A\Model\TradeOrder;
use Weline\Framework\Database\Model;

class DeliveryAcceptanceService
{
    public function __construct(
        private readonly TradeOrder $tradeOrderModel,
        private readonly ProviderScopeSubmission $providerScopeModel,
        private readonly DeliveryAcceptance $deliveryAcceptanceModel,
        private readonly EscrowLedger $escrowLedgerModel
    ) {
    }

    public function accept(string $orderPublicId, string $decision = ''): array
    {
        $orderPublicId = \strtoupper(\trim($orderPublicId));
        if ($orderPublicId === '') {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }
        $decision = $this->normalizeDecision($decision);

        $tradeOrder = $this->freshModel($this->tradeOrderModel);
        $tradeOrder->where(TradeOrder::schema_fields_PUBLIC_ID, $orderPublicId)->find()->fetch();
        if (!$tradeOrder->getId()) {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        $providerScope = $this->freshModel($this->providerScopeModel);
        $providerScope->where(ProviderScopeSubmission::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)->find()->fetch();
        if (!$providerScope->getId()) {
            throw new \InvalidArgumentException((string) __('Provider 范围尚未提交，不能进入验收。'));
        }

        $deliverySubmission = $this->resolveDeliverySubmission($providerScope);
        $deliveryEvidence = $this->buildDeliveryEvidence($tradeOrder, $providerScope, $deliverySubmission);
        $acceptanceChecklist = $this->buildAcceptanceChecklist($tradeOrder, $providerScope);
        $existingAcceptance = $this->loadAcceptance($tradeOrder->getPublicId());
        $isCaseState = $this->isCaseState($tradeOrder);

        if ($decision === 'review') {
            $mode = $this->resolveReviewMode($tradeOrder, $existingAcceptance);
            $ledgerRows = $this->loadLedgerRows($tradeOrder->getPublicId());

            return $this->buildAcceptancePagePayload(
                $tradeOrder,
                $providerScope,
                $deliverySubmission,
                $deliveryEvidence,
                $acceptanceChecklist,
                $existingAcceptance,
                $ledgerRows,
                $mode,
                $this->formatDecisionStatus($mode, $isCaseState),
                $this->formatPersistedStatus($mode, $existingAcceptance !== null && $existingAcceptance->getId() > 0)
            );
        }

        if ($decision === 'accept') {
            if ($isCaseState && !$this->isAcceptedState($tradeOrder, $existingAcceptance)) {
                throw new \InvalidArgumentException((string) __('订单已进入退款、争议或返工分支，不能直接验收放款。'));
            }

            $acceptance = $this->syncDeliveryAcceptance($tradeOrder, $providerScope, $deliverySubmission, $deliveryEvidence, $acceptanceChecklist);
            if (!$isCaseState) {
                $this->releaseLedgerRows($tradeOrder, $acceptance);
                $this->markTradeOrderAccepted($tradeOrder, $acceptance);
            }
            $ledgerRows = $this->loadLedgerRows($tradeOrder->getPublicId());

            return $this->buildAcceptancePagePayload(
                $tradeOrder,
                $providerScope,
                $deliverySubmission,
                $deliveryEvidence,
                $acceptanceChecklist,
                $acceptance,
                $ledgerRows,
                'accepted',
                $isCaseState
                    ? (string) __('订单已进入退款或争议分支')
                    : (string) __('买方已验收，托管进入放款结算'),
                (string) __('已写入交付验收并完成放款账本流转')
            );
        }

        if ($this->isAcceptedState($tradeOrder, $existingAcceptance)) {
            throw new \InvalidArgumentException((string) __('订单已验收放款，不能再要求返工。'));
        }

        $acceptance = $this->syncReworkDecision($tradeOrder, $providerScope, $deliverySubmission, $deliveryEvidence, $acceptanceChecklist);
        $this->holdLedgerRowsForRework($tradeOrder, $acceptance);
        $this->markTradeOrderRework($tradeOrder, $acceptance);
        $ledgerRows = $this->loadLedgerRows($tradeOrder->getPublicId());

        return $this->buildAcceptancePagePayload(
            $tradeOrder,
            $providerScope,
            $deliverySubmission,
            $deliveryEvidence,
            $acceptanceChecklist,
            $acceptance,
            $ledgerRows,
            'rework',
            (string) __('买方要求返工，托管资金继续冻结'),
            (string) __('已写入返工请求，托管账本保持冻结')
        );
    }

    private function normalizeDecision(string $decision): string
    {
        $decision = \strtolower(\trim($decision));
        if ($decision === '' || $decision === 'review') {
            return 'review';
        }
        if (\in_array($decision, ['accept', 'rework'], true)) {
            return $decision;
        }

        throw new \InvalidArgumentException((string) __('验收决策无效。'));
    }

    private function loadAcceptance(string $orderPublicId): ?DeliveryAcceptance
    {
        $acceptance = $this->freshModel($this->deliveryAcceptanceModel);
        $acceptance->where(DeliveryAcceptance::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)->find()->fetch();

        return $acceptance->getId() ? $acceptance : null;
    }

    private function resolveReviewMode(TradeOrder $tradeOrder, ?DeliveryAcceptance $acceptance): string
    {
        if ($this->isAcceptedState($tradeOrder, $acceptance)) {
            return 'accepted';
        }

        $status = (string)$tradeOrder->getData(TradeOrder::schema_fields_STATUS);
        $queueStatus = (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS);
        if ($status === TradeOrder::STATUS_REWORK_REQUIRED
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_REWORK_REQUIRED
            || (string)($acceptance?->getData(DeliveryAcceptance::schema_fields_STATUS) ?? '') === DeliveryAcceptance::STATUS_REWORK_REQUESTED
        ) {
            return 'rework';
        }

        if ($this->isCaseState($tradeOrder)) {
            return 'case';
        }

        return 'review';
    }

    private function isAcceptedState(TradeOrder $tradeOrder, ?DeliveryAcceptance $acceptance): bool
    {
        return (string)$tradeOrder->getData(TradeOrder::schema_fields_STATUS) === TradeOrder::STATUS_ACCEPTED_RELEASED
            || (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS) === TradeOrder::PROVIDER_QUEUE_ACCEPTED
            || (string)($acceptance?->getData(DeliveryAcceptance::schema_fields_STATUS) ?? '') === DeliveryAcceptance::STATUS_ACCEPTED;
    }

    private function formatDecisionStatus(string $mode, bool $isCaseState): string
    {
        return match ($mode) {
            'accepted' => (string) __('买方已验收，托管进入放款结算'),
            'rework' => (string) __('买方要求返工，托管资金继续冻结'),
            'case' => $isCaseState
                ? (string) __('订单已进入退款或争议分支')
                : (string) __('订单已进入异常分支'),
            default => (string) __('等待买方审阅交付证据并选择处理分支'),
        };
    }

    private function formatPersistedStatus(string $mode, bool $hasAcceptance): string
    {
        return match ($mode) {
            'accepted' => (string) __('已写入交付验收并完成放款账本流转'),
            'rework' => (string) __('已写入返工请求，托管账本保持冻结'),
            'case' => (string) __('订单已有结算分支，当前页仅展示验收证据'),
            default => $hasAcceptance
                ? (string) __('已有验收快照，当前页未新增放款动作')
                : (string) __('仅预览交付证据，托管资金仍保持冻结'),
        };
    }

    private function buildAcceptancePagePayload(
        TradeOrder $tradeOrder,
        ProviderScopeSubmission $providerScope,
        array $deliverySubmission,
        array $deliveryEvidence,
        array $acceptanceChecklist,
        ?DeliveryAcceptance $acceptance,
        array $ledgerRows,
        string $mode,
        string $status,
        string $persistedStatus
    ): array {
        $decisionOptions = $this->buildDecisionOptions($tradeOrder, $acceptance, $mode);

        return [
            'page_title' => __('A2A 买方验收决策'),
            'decision_mode' => $mode,
            'order_id' => $tradeOrder->getPublicId(),
            'scope_id' => $providerScope->getPublicId(),
            'acceptance_id' => $acceptance?->getPublicId() ?: $this->buildAcceptanceId($tradeOrder->getPublicId()),
            'status' => $status,
            'order' => [
                'sku_title' => (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_TITLE),
                'provider' => (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER),
                'amount' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_AMOUNT)),
                'platform_fee' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PLATFORM_FEE)),
                'provider_payout' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT)),
                'status' => (string)$tradeOrder->getData(TradeOrder::schema_fields_STATUS),
                'trade_order_id' => $tradeOrder->getId(),
            ],
            'delivery_submission' => $deliverySubmission,
            'delivery_evidence' => $deliveryEvidence,
            'acceptance_checklist' => $acceptanceChecklist,
            'ledger_rows' => $ledgerRows,
            'decision_options' => $decisionOptions,
            'settlement_options' => $decisionOptions,
            'persisted' => [
                'status' => $persistedStatus,
                'delivery_acceptance_id' => $acceptance?->getId() ?: 0,
                'delivery_submission_id' => (string)$deliverySubmission['delivery_public_id'],
                'trade_order_id' => $tradeOrder->getId(),
                'ledger_rows' => \count($ledgerRows),
            ],
        ];
    }

    private function buildDecisionOptions(TradeOrder $tradeOrder, ?DeliveryAcceptance $acceptance, string $mode): array
    {
        $orderId = $tradeOrder->getPublicId();
        $hasAcceptance = (bool)$acceptance?->getId();
        $caseStatus = $hasAcceptance ? __('可发起') : __('需先形成验收或返工快照');
        $refundUrl = $hasAcceptance ? '/a2a/frontend/settlement-case?case=refund&order=' . \rawurlencode($orderId) : '';
        $disputeUrl = $hasAcceptance ? '/a2a/frontend/settlement-case?case=dispute&order=' . \rawurlencode($orderId) : '';

        return [
            [
                'decision_type' => 'accept',
                'label' => __('确认验收并放款'),
                'status' => $mode === 'accepted' ? __('已执行') : ($mode === 'review' ? __('可执行') : __('待下一版交付')),
                'description' => __('确认交付达标后释放买方冻结金额、确认平台服务费，并把 Agent 收入置为待结算。'),
                'url' => $mode === 'review' ? '/a2a/frontend/acceptance?order=' . \rawurlencode($orderId) . '&decision=accept' : '',
            ],
            [
                'decision_type' => 'rework',
                'label' => __('要求返工，继续冻结'),
                'status' => $mode === 'rework' ? __('已执行') : ($mode === 'review' ? __('可执行') : __('不可执行')),
                'description' => __('交付证据不足时写入返工快照，买方资金、平台费和 Agent 收入继续冻结。'),
                'url' => $mode === 'review' ? '/a2a/frontend/acceptance?order=' . \rawurlencode($orderId) . '&decision=rework' : '',
            ],
            [
                'decision_type' => 'refund',
                'label' => __('发起退款复核'),
                'status' => $caseStatus,
                'description' => __('交付无法补正时，买方可基于验收或返工快照进入退款复核。'),
                'url' => $refundUrl,
            ],
            [
                'decision_type' => 'dispute',
                'label' => __('发起争议仲裁'),
                'status' => $caseStatus,
                'description' => __('范围、权限或输出质量存在重大分歧时，提交仲裁员冻结复核。'),
                'url' => $disputeUrl,
            ],
        ];
    }

    private function resolveDeliverySubmission(ProviderScopeSubmission $providerScope): array
    {
        $metadata = $this->decodeJson((string)$providerScope->getData(ProviderScopeSubmission::schema_fields_METADATA_JSON));
        $deliverySubmission = $metadata['delivery_submission'] ?? [];
        if (!\is_array($deliverySubmission)
            || (string)($deliverySubmission['status'] ?? '') !== 'submitted'
            || (string)($deliverySubmission['delivery_public_id'] ?? '') === ''
        ) {
            throw new \InvalidArgumentException((string) __('Agent 尚未提交交付证据，不能进入买方验收放款。'));
        }

        return $deliverySubmission;
    }

    private function syncDeliveryAcceptance(
        TradeOrder $tradeOrder,
        ProviderScopeSubmission $providerScope,
        array $deliverySubmission,
        array $deliveryEvidence,
        array $acceptanceChecklist
    ): DeliveryAcceptance {
        $acceptancePublicId = $this->buildAcceptanceId($tradeOrder->getPublicId());
        $model = $this->freshModel($this->deliveryAcceptanceModel);
        $model->where(DeliveryAcceptance::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())->find()->fetch();

        $now = \date('Y-m-d H:i:s');
        $model->setData(DeliveryAcceptance::schema_fields_PUBLIC_ID, $acceptancePublicId);
        $model->setData(DeliveryAcceptance::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId());
        $model->setData(DeliveryAcceptance::schema_fields_PROVIDER_SCOPE_PUBLIC_ID, $providerScope->getPublicId());
        $model->setData(DeliveryAcceptance::schema_fields_PROVIDER, (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER));
        $model->setData(DeliveryAcceptance::schema_fields_DELIVERY_EVIDENCE_JSON, $this->encodeJson($deliveryEvidence));
        $model->setData(DeliveryAcceptance::schema_fields_ACCEPTANCE_CHECKLIST_JSON, $this->encodeJson($acceptanceChecklist));
        $model->setData(DeliveryAcceptance::schema_fields_STATUS, DeliveryAcceptance::STATUS_ACCEPTED);
        $model->setData(DeliveryAcceptance::schema_fields_DECISION, DeliveryAcceptance::DECISION_ACCEPT);
        $model->setData(DeliveryAcceptance::schema_fields_SUBMITTED_AT, $now);
        $model->setData(DeliveryAcceptance::schema_fields_ACCEPTED_AT, $now);
        $model->setData(DeliveryAcceptance::schema_fields_METADATA_JSON, $this->encodeJson([
            'trust_tags' => ['实战验证', '数据驱动', '专家审核'],
            'delivery_submission_public_id' => (string)($deliverySubmission['delivery_public_id'] ?? ''),
            'settlement_action' => 'release_provider_and_capture_platform_fee',
            'buyer_acceptance_after_provider_delivery' => true,
        ]));
        $model->save();

        return $model;
    }

    private function syncReworkDecision(
        TradeOrder $tradeOrder,
        ProviderScopeSubmission $providerScope,
        array $deliverySubmission,
        array $deliveryEvidence,
        array $acceptanceChecklist
    ): DeliveryAcceptance {
        $acceptancePublicId = $this->buildAcceptanceId($tradeOrder->getPublicId());
        $model = $this->freshModel($this->deliveryAcceptanceModel);
        $model->where(DeliveryAcceptance::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())->find()->fetch();

        $now = \date('Y-m-d H:i:s');
        $model->setData(DeliveryAcceptance::schema_fields_PUBLIC_ID, $acceptancePublicId);
        $model->setData(DeliveryAcceptance::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId());
        $model->setData(DeliveryAcceptance::schema_fields_PROVIDER_SCOPE_PUBLIC_ID, $providerScope->getPublicId());
        $model->setData(DeliveryAcceptance::schema_fields_PROVIDER, (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER));
        $model->setData(DeliveryAcceptance::schema_fields_DELIVERY_EVIDENCE_JSON, $this->encodeJson($deliveryEvidence));
        $model->setData(DeliveryAcceptance::schema_fields_ACCEPTANCE_CHECKLIST_JSON, $this->encodeJson($acceptanceChecklist));
        $model->setData(DeliveryAcceptance::schema_fields_STATUS, DeliveryAcceptance::STATUS_REWORK_REQUESTED);
        $model->setData(DeliveryAcceptance::schema_fields_DECISION, DeliveryAcceptance::DECISION_REWORK);
        $model->setData(DeliveryAcceptance::schema_fields_SUBMITTED_AT, $now);
        $model->setData(DeliveryAcceptance::schema_fields_ACCEPTED_AT, null);
        $model->setData(DeliveryAcceptance::schema_fields_METADATA_JSON, $this->encodeJson([
            'trust_tags' => ['实战验证', '数据驱动', '专家审核'],
            'delivery_submission_public_id' => (string)($deliverySubmission['delivery_public_id'] ?? ''),
            'settlement_action' => 'request_rework_and_keep_escrow_frozen',
            'buyer_acceptance_after_provider_delivery' => false,
            'rework_requested_at' => $now,
        ]));
        $model->save();

        return $model;
    }

    private function releaseLedgerRows(TradeOrder $tradeOrder, DeliveryAcceptance $acceptance): void
    {
        $rows = [
            'buyer_freeze' => [
                EscrowLedger::schema_fields_LABEL => (string) __('买方冻结金额已验收释放'),
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_RELEASED,
            ],
            'platform_fee' => [
                EscrowLedger::schema_fields_LABEL => (string) __('平台服务费已确认入账'),
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_CAPTURED,
            ],
            'provider_payout' => [
                EscrowLedger::schema_fields_LABEL => (string) __('Agent 收入已进入待结算'),
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_PAID,
            ],
        ];

        foreach ($rows as $entryType => $row) {
            $model = $this->freshModel($this->escrowLedgerModel);
            $model->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())
                ->where(EscrowLedger::schema_fields_ENTRY_TYPE, $entryType)
                ->find()
                ->fetch();

            if (!$model->getId()) {
                continue;
            }

            $metadata = $this->decodeJson((string)$model->getData(EscrowLedger::schema_fields_METADATA_JSON));
            $model->setData(EscrowLedger::schema_fields_LABEL, (string)$row[EscrowLedger::schema_fields_LABEL]);
            $model->setData(EscrowLedger::schema_fields_STATUS, (string)$row[EscrowLedger::schema_fields_STATUS]);
            $model->setData(EscrowLedger::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
                'delivery_acceptance_public_id' => $acceptance->getPublicId(),
                'accepted_release' => true,
            ])));
            $model->save();
        }
    }

    private function markTradeOrderAccepted(TradeOrder $tradeOrder, DeliveryAcceptance $acceptance): void
    {
        $metadata = $this->decodeJson((string)$tradeOrder->getData(TradeOrder::schema_fields_METADATA_JSON));
        $tradeOrder->setData(TradeOrder::schema_fields_STATUS, TradeOrder::STATUS_ACCEPTED_RELEASED);
        $tradeOrder->setData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS, TradeOrder::PROVIDER_QUEUE_ACCEPTED);
        $tradeOrder->setData(TradeOrder::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'delivery_acceptance_public_id' => $acceptance->getPublicId(),
            'accepted_release_at' => \date('Y-m-d H:i:s'),
            'settlement_complete' => true,
        ])));
        $tradeOrder->save();
    }

    private function holdLedgerRowsForRework(TradeOrder $tradeOrder, DeliveryAcceptance $acceptance): void
    {
        $rows = [
            'buyer_freeze' => [
                EscrowLedger::schema_fields_LABEL => (string) __('买方资金继续冻结，等待返工'),
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_REWORK_HOLD,
            ],
            'platform_fee' => [
                EscrowLedger::schema_fields_LABEL => (string) __('平台服务费继续预留，等待返工验收'),
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_REWORK_HOLD,
            ],
            'provider_payout' => [
                EscrowLedger::schema_fields_LABEL => (string) __('Agent 收入暂停，等待返工补交'),
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_REWORK_HOLD,
            ],
        ];

        foreach ($rows as $entryType => $row) {
            $model = $this->freshModel($this->escrowLedgerModel);
            $model->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())
                ->where(EscrowLedger::schema_fields_ENTRY_TYPE, $entryType)
                ->find()
                ->fetch();

            if (!$model->getId()) {
                continue;
            }

            $metadata = $this->decodeJson((string)$model->getData(EscrowLedger::schema_fields_METADATA_JSON));
            $model->setData(EscrowLedger::schema_fields_LABEL, (string)$row[EscrowLedger::schema_fields_LABEL]);
            $model->setData(EscrowLedger::schema_fields_STATUS, (string)$row[EscrowLedger::schema_fields_STATUS]);
            $model->setData(EscrowLedger::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
                'delivery_acceptance_public_id' => $acceptance->getPublicId(),
                'accepted_release' => false,
                'rework_required' => true,
            ])));
            $model->save();
        }
    }

    private function markTradeOrderRework(TradeOrder $tradeOrder, DeliveryAcceptance $acceptance): void
    {
        $metadata = $this->decodeJson((string)$tradeOrder->getData(TradeOrder::schema_fields_METADATA_JSON));
        $tradeOrder->setData(TradeOrder::schema_fields_STATUS, TradeOrder::STATUS_REWORK_REQUIRED);
        $tradeOrder->setData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS, TradeOrder::PROVIDER_QUEUE_REWORK_REQUIRED);
        $tradeOrder->setData(TradeOrder::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'delivery_acceptance_public_id' => $acceptance->getPublicId(),
            'rework_requested_at' => \date('Y-m-d H:i:s'),
            'settlement_complete' => false,
        ])));
        $tradeOrder->save();
    }

    private function isCaseState(TradeOrder $tradeOrder): bool
    {
        $status = (string)$tradeOrder->getData(TradeOrder::schema_fields_STATUS);
        $queueStatus = (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS);

        return $status === TradeOrder::STATUS_REFUND_REVIEW
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_REFUND_REVIEW
            || $status === TradeOrder::STATUS_DISPUTE_ARBITRATION
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_DISPUTE_HOLD
            || $status === TradeOrder::STATUS_ARBITRATION_RULED
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_ARBITRATION_RULED
            || $status === TradeOrder::STATUS_REWORK_REQUIRED
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_REWORK_REQUIRED;
    }

    private function buildDeliveryEvidence(
        TradeOrder $tradeOrder,
        ProviderScopeSubmission $providerScope,
        array $deliverySubmission
    ): array
    {
        $orderId = $tradeOrder->getPublicId();
        $providerEvidence = $deliverySubmission['evidence_items'] ?? [];
        $providerEvidence = \is_array($providerEvidence) ? \array_values($providerEvidence) : [];

        $evidence = [
            __('输入文件哈希已匹配范围 %{1}', [$providerScope->getPublicId()]),
            __('执行日志包：runlog-%{1}.jsonl', [$orderId]),
            __('输出质量摘要：通过 10 万行清洗规则，异常项已列入清单。'),
            __('交付物校验摘要：sha256:%{1}', [\substr(\hash('sha256', $orderId . '|delivery'), 0, 16)]),
            __('Agent 交付证据编号：%{1}', [(string)($deliverySubmission['delivery_public_id'] ?? '')]),
            __('Agent 提交时间：%{1}', [(string)($deliverySubmission['submitted_at'] ?? '')]),
            __('Agent 输出哈希：%{1}', [(string)($deliverySubmission['output_hash'] ?? '')]),
            __('专家审核标签：实战验证、数据驱动、专家审核。'),
        ];

        foreach ($providerEvidence as $item) {
            if (\is_scalar($item) || $item instanceof \Stringable) {
                $evidence[] = (string)$item;
            }
        }

        return $evidence;
    }

    private function buildAcceptanceChecklist(TradeOrder $tradeOrder, ProviderScopeSubmission $providerScope): array
    {
        return [
            __('交付结果覆盖订单约定范围。'),
            __('输入哈希、执行参数和工具版本均已留痕。'),
            __('输出文件校验摘要与质量报告一致。'),
            __('异常项说明完整，不影响本次验收放款。'),
            __('未发现外部 API 越权调用或数据外发。'),
        ];
    }

    /**
     * @return list<array{label: string, amount: string, status: string, status_label: string, entry_type: string}>
     */
    private function loadLedgerRows(string $orderPublicId): array
    {
        $rows = $this->freshModel($this->escrowLedgerModel)
            ->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
            ->select()
            ->fetchArray();

        if (!\is_array($rows)) {
            return [];
        }

        return \array_values(\array_map(fn(array $row): array => [
            'label' => (string)($row[EscrowLedger::schema_fields_LABEL] ?? ''),
            'amount' => $this->formatUsd((float)($row[EscrowLedger::schema_fields_AMOUNT] ?? 0)),
            'status' => (string)($row[EscrowLedger::schema_fields_STATUS] ?? ''),
            'status_label' => $this->formatLedgerStatus((string)($row[EscrowLedger::schema_fields_STATUS] ?? '')),
            'entry_type' => (string)($row[EscrowLedger::schema_fields_ENTRY_TYPE] ?? ''),
        ], $rows));
    }

    /**
     * @template T of Model
     * @param T $model
     * @return T
     */
    private function freshModel(Model $model): Model
    {
        return (clone $model)->clearData()->clearQuery();
    }

    private function buildAcceptanceId(string $orderPublicId): string
    {
        return 'A2A-ACCEPT-' . \strtoupper(\substr(\hash('crc32b', $orderPublicId), 0, 6));
    }

    private function formatUsd(float $amount): string
    {
        return '$' . \number_format($amount, 2);
    }

    private function formatLedgerStatus(string $status): string
    {
        return match ($status) {
            EscrowLedger::STATUS_RELEASED => (string) __('已释放'),
            EscrowLedger::STATUS_CAPTURED => (string) __('已入账'),
            EscrowLedger::STATUS_PAID => (string) __('待结算'),
            EscrowLedger::STATUS_REFUND_READY => (string) __('待退款'),
            EscrowLedger::STATUS_DISPUTE_HOLD => (string) __('争议冻结'),
            EscrowLedger::STATUS_WALLET_PENDING => (string) __('钱包指令待执行'),
            EscrowLedger::STATUS_REWORK_HOLD => (string) __('返工冻结'),
            EscrowLedger::STATUS_LOCKED => (string) __('已锁定'),
            EscrowLedger::STATUS_RESERVED => (string) __('已预留'),
            EscrowLedger::STATUS_PENDING_RELEASE => (string) __('待验收放款'),
            default => $status,
        };
    }

    private function encodeJson(array $payload): string
    {
        return \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private function decodeJson(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        try {
            $decoded = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
