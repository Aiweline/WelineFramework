<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use GuoLaiRen\A2A\Model\DeliveryAcceptance;
use GuoLaiRen\A2A\Model\EscrowLedger;
use GuoLaiRen\A2A\Model\SettlementCase;
use GuoLaiRen\A2A\Model\TradeOrder;
use Weline\Framework\Database\Model;

class SettlementCaseService
{
    public function __construct(
        private readonly TradeOrder $tradeOrderModel,
        private readonly DeliveryAcceptance $deliveryAcceptanceModel,
        private readonly SettlementCase $settlementCaseModel,
        private readonly EscrowLedger $escrowLedgerModel
    ) {
    }

    public function open(string $orderPublicId, string $caseType, bool $submit = false, array $applicationInput = []): array
    {
        $orderPublicId = \strtoupper(\trim($orderPublicId));
        $caseType = \strtolower(\trim($caseType));
        if ($orderPublicId === '') {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }
        if (!\in_array($caseType, [SettlementCase::TYPE_REFUND, SettlementCase::TYPE_DISPUTE], true)) {
            throw new \InvalidArgumentException((string) __('结算分支类型无效。'));
        }

        $tradeOrder = $this->freshModel($this->tradeOrderModel);
        $tradeOrder->where(TradeOrder::schema_fields_PUBLIC_ID, $orderPublicId)->find()->fetch();
        if (!$tradeOrder->getId()) {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        $acceptance = $this->freshModel($this->deliveryAcceptanceModel);
        $acceptance->where(DeliveryAcceptance::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)->find()->fetch();
        if (!$acceptance->getId()) {
            throw new \InvalidArgumentException((string) __('订单尚未形成验收记录，不能发起结算分支。'));
        }

        $caseApplication = $this->buildCaseApplication($caseType, $applicationInput);
        $caseEvidence = $this->buildCaseEvidence($tradeOrder, $acceptance, $caseType, $caseApplication);
        $ledgerImpact = $this->buildLedgerImpact($caseType);
        $existingCase = $this->loadExistingSettlementCase($tradeOrder->getPublicId(), $caseType);
        $ledgerRows = $this->loadLedgerRows($tradeOrder->getPublicId());

        if (!$submit) {
            if ($existingCase instanceof SettlementCase) {
                $status = $this->formatCaseStatus(
                    $caseType,
                    (string)$existingCase->getData(SettlementCase::schema_fields_STATUS)
                );
                $existingApplication = $this->buildCaseApplication(
                    $caseType,
                    $this->loadStoredBuyerApplication($existingCase)
                );
                $existingEvidence = $this->decodeJson((string)$existingCase->getData(SettlementCase::schema_fields_EVIDENCE_JSON));
                $existingLedgerImpact = $this->decodeJson((string)$existingCase->getData(SettlementCase::schema_fields_LEDGER_IMPACT_JSON));

                return $this->buildPagePayload(
                    $tradeOrder,
                    $acceptance,
                    $caseType,
                    $existingApplication,
                    $existingEvidence !== [] ? $existingEvidence : $caseEvidence,
                    $existingLedgerImpact !== [] ? $existingLedgerImpact : $ledgerImpact,
                    $ledgerRows,
                    'submitted',
                    $status,
                    $existingCase,
                    __('已有结算分支，当前页只展示已提交申请和账本状态')
                );
            }

            return $this->buildPagePayload(
                $tradeOrder,
                $acceptance,
                $caseType,
                $caseApplication,
                $caseEvidence,
                $ledgerImpact,
                $ledgerRows,
                'preview',
                $this->formatPreviewStatus($caseType),
                null,
                __('仅预览申请，尚未创建结算分支或改写托管账本')
            );
        }

        $settlementCase = $this->syncSettlementCase(
            $tradeOrder,
            $acceptance,
            $caseType,
            $caseEvidence,
            $ledgerImpact,
            $caseApplication
        );
        $isRuled = (string)$settlementCase->getData(SettlementCase::schema_fields_STATUS) === SettlementCase::STATUS_ARBITRATION_RULED;
        if (!$isRuled) {
            $this->applyLedgerImpact($tradeOrder, $settlementCase, $caseType);
            $this->markTradeOrderCaseState($tradeOrder, $settlementCase, $caseType);
        }
        $ledgerRows = $this->loadLedgerRows($tradeOrder->getPublicId());

        return $this->buildPagePayload(
            $tradeOrder,
            $acceptance,
            $caseType,
            $caseApplication,
            $caseEvidence,
            $ledgerImpact,
            $ledgerRows,
            'submitted',
            $this->formatCaseStatus($caseType, (string)$settlementCase->getData(SettlementCase::schema_fields_STATUS)),
            $settlementCase,
            __('已写入结算分支并更新托管账本状态')
        );
    }

    private function buildPagePayload(
        TradeOrder $tradeOrder,
        DeliveryAcceptance $acceptance,
        string $caseType,
        array $caseApplication,
        array $caseEvidence,
        array $ledgerImpact,
        array $ledgerRows,
        string $caseMode,
        string|\Stringable $status,
        ?SettlementCase $settlementCase,
        string|\Stringable $persistedStatus
    ): array {
        $settlementCaseId = $settlementCase?->getId() ?: 0;

        return [
            'page_title' => __('A2A 结算分支与仲裁'),
            'order_id' => $tradeOrder->getPublicId(),
            'acceptance_id' => $acceptance->getPublicId(),
            'case_id' => $settlementCase?->getPublicId() ?: $this->buildCaseId($tradeOrder->getPublicId(), $caseType),
            'case_type' => $caseType,
            'case_title' => $this->formatCaseTitle($caseType),
            'case_mode' => $caseMode,
            'is_preview_mode' => $caseMode === 'preview',
            'status' => $status,
            'order' => [
                'sku_title' => (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_TITLE),
                'provider' => (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER),
                'amount' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_AMOUNT)),
                'platform_fee' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PLATFORM_FEE)),
                'provider_payout' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT)),
                'trade_order_id' => $tradeOrder->getId(),
            ],
            'case_application' => $caseApplication,
            'case_evidence' => $caseEvidence,
            'ledger_impact' => $ledgerImpact,
            'ledger_rows' => $ledgerRows,
            'arbitration_steps' => $this->buildArbitrationSteps($caseType),
            'submit_url' => $caseMode === 'preview'
                ? $this->buildSubmitUrl($tradeOrder->getPublicId(), $caseType)
                : '',
            'persisted' => [
                'status' => $persistedStatus,
                'settlement_case_id' => $settlementCaseId,
                'trade_order_id' => $tradeOrder->getId(),
                'ledger_rows' => \count($ledgerRows),
            ],
        ];
    }

    private function syncSettlementCase(
        TradeOrder $tradeOrder,
        DeliveryAcceptance $acceptance,
        string $caseType,
        array $caseEvidence,
        array $ledgerImpact,
        array $caseApplication
    ): SettlementCase {
        $model = $this->freshModel($this->settlementCaseModel);
        $model->where(SettlementCase::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())
            ->where(SettlementCase::schema_fields_CASE_TYPE, $caseType)
            ->find()
            ->fetch();
        $existingStatus = (string)$model->getData(SettlementCase::schema_fields_STATUS);
        $existingDecision = (string)$model->getData(SettlementCase::schema_fields_DECISION);
        $existingResolvedAt = (string)$model->getData(SettlementCase::schema_fields_RESOLVED_AT);
        $existingMetadata = $this->decodeJson((string)$model->getData(SettlementCase::schema_fields_METADATA_JSON));
        $isRuled = $existingStatus === SettlementCase::STATUS_ARBITRATION_RULED;

        $model->setData(SettlementCase::schema_fields_PUBLIC_ID, $this->buildCaseId($tradeOrder->getPublicId(), $caseType));
        $model->setData(SettlementCase::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId());
        $model->setData(SettlementCase::schema_fields_ACCEPTANCE_PUBLIC_ID, $acceptance->getPublicId());
        $model->setData(SettlementCase::schema_fields_CASE_TYPE, $caseType);
        $model->setData(
            SettlementCase::schema_fields_STATUS,
            $isRuled
                ? SettlementCase::STATUS_ARBITRATION_RULED
                : ($caseType === SettlementCase::TYPE_REFUND
                ? SettlementCase::STATUS_REFUND_REVIEW
                : SettlementCase::STATUS_DISPUTE_ARBITRATION)
        );
        $model->setData(SettlementCase::schema_fields_DECISION, $isRuled ? $existingDecision : $this->formatDecision($caseType));
        $model->setData(SettlementCase::schema_fields_EVIDENCE_JSON, $this->encodeJson($caseEvidence));
        $model->setData(SettlementCase::schema_fields_LEDGER_IMPACT_JSON, $this->encodeJson($ledgerImpact));
        $model->setData(SettlementCase::schema_fields_OPENED_AT, \date('Y-m-d H:i:s'));
        $model->setData(SettlementCase::schema_fields_RESOLVED_AT, $isRuled ? $existingResolvedAt : null);
        $model->setData(SettlementCase::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($existingMetadata, [
            'source_acceptance_public_id' => $acceptance->getPublicId(),
            'case_source' => 'acceptance_branch',
            'reputation_signal' => $caseType,
            'buyer_application' => [
                'reason' => (string)$caseApplication['reason'],
                'desired_outcome' => (string)$caseApplication['desired_outcome'],
                'evidence_note' => (string)$caseApplication['evidence_note'],
            ],
        ])));
        $model->save();

        return $model;
    }

    private function applyLedgerImpact(TradeOrder $tradeOrder, SettlementCase $settlementCase, string $caseType): void
    {
        $rows = $caseType === SettlementCase::TYPE_REFUND
            ? [
                'buyer_freeze' => [
                    EscrowLedger::schema_fields_LABEL => (string) __('买方退款待处理'),
                    EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_REFUND_READY,
                ],
                'platform_fee' => [
                    EscrowLedger::schema_fields_LABEL => (string) __('平台服务费待退回或减免'),
                    EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_REFUND_READY,
                ],
                'provider_payout' => [
                    EscrowLedger::schema_fields_LABEL => (string) __('Agent 收入暂停结算'),
                    EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_DISPUTE_HOLD,
                ],
            ]
            : [
                'buyer_freeze' => [
                    EscrowLedger::schema_fields_LABEL => (string) __('买方资金进入争议冻结'),
                    EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_DISPUTE_HOLD,
                ],
                'platform_fee' => [
                    EscrowLedger::schema_fields_LABEL => (string) __('平台服务费等待仲裁裁决'),
                    EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_DISPUTE_HOLD,
                ],
                'provider_payout' => [
                    EscrowLedger::schema_fields_LABEL => (string) __('Agent 收入等待仲裁裁决'),
                    EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_DISPUTE_HOLD,
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
                'settlement_case_public_id' => $settlementCase->getPublicId(),
                'settlement_case_type' => $caseType,
            ])));
            $model->save();
        }
    }

    private function markTradeOrderCaseState(TradeOrder $tradeOrder, SettlementCase $settlementCase, string $caseType): void
    {
        $metadata = $this->decodeJson((string)$tradeOrder->getData(TradeOrder::schema_fields_METADATA_JSON));
        $tradeOrder->setData(
            TradeOrder::schema_fields_STATUS,
            $caseType === SettlementCase::TYPE_REFUND
                ? TradeOrder::STATUS_REFUND_REVIEW
                : TradeOrder::STATUS_DISPUTE_ARBITRATION
        );
        $tradeOrder->setData(
            TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS,
            $caseType === SettlementCase::TYPE_REFUND
                ? TradeOrder::PROVIDER_QUEUE_REFUND_REVIEW
                : TradeOrder::PROVIDER_QUEUE_DISPUTE_HOLD
        );
        $tradeOrder->setData(TradeOrder::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'settlement_case_public_id' => $settlementCase->getPublicId(),
            'settlement_case_type' => $caseType,
            'settlement_case_opened_at' => \date('Y-m-d H:i:s'),
        ])));
        $tradeOrder->save();
    }

    private function buildCaseEvidence(
        TradeOrder $tradeOrder,
        DeliveryAcceptance $acceptance,
        string $caseType,
        array $caseApplication
    ): array
    {
        return $caseType === SettlementCase::TYPE_REFUND
            ? [
                __('买方申请原因：%{1}', $caseApplication['reason']),
                __('买方期望结果：%{1}', $caseApplication['desired_outcome']),
                __('补充证据说明：%{1}', $caseApplication['evidence_note']),
                __('引用验收记录：%{1}', $acceptance->getPublicId()),
                __('平台建议：保留审计日志，要求 Agent 补交输出校验摘要。'),
            ]
            : [
                __('买方申请原因：%{1}', $caseApplication['reason']),
                __('买方期望结果：%{1}', $caseApplication['desired_outcome']),
                __('补充证据说明：%{1}', $caseApplication['evidence_note']),
                __('引用验收记录：%{1}', $acceptance->getPublicId()),
                __('平台建议：冻结三方账本，仲裁员复核范围、日志、哈希和质量摘要。'),
            ];
    }

    private function loadExistingSettlementCase(string $orderPublicId, string $caseType): ?SettlementCase
    {
        $model = $this->freshModel($this->settlementCaseModel);
        $model->where(SettlementCase::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
            ->where(SettlementCase::schema_fields_CASE_TYPE, $caseType)
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function loadStoredBuyerApplication(SettlementCase $settlementCase): array
    {
        $metadata = $this->decodeJson((string)$settlementCase->getData(SettlementCase::schema_fields_METADATA_JSON));
        $application = $metadata['buyer_application'] ?? [];

        return \is_array($application) ? $application : [];
    }

    private function buildCaseApplication(string $caseType, array $input): array
    {
        $isRefund = $caseType === SettlementCase::TYPE_REFUND;
        $defaultReason = $isRefund
            ? __('交付证据未达到验收标准，买方请求暂停结算并复核退款。')
            : __('买方质疑交付范围、权限或异常处理，需要仲裁员复核。');
        $defaultOutcome = $isRefund
            ? __('退款、补证后再验收，或按裁决调整平台服务费。')
            : __('由仲裁员裁定放款、退款、部分放款或返工。');
        $defaultEvidence = $isRefund
            ? __('引用验收记录、交付哈希、执行日志和缺失证据清单。')
            : __('引用验收记录、范围说明、权限日志、输出哈希和异常清单。');

        return [
            'reason' => $this->normalizeApplicationText((string)($input['reason'] ?? ''), (string)$defaultReason),
            'desired_outcome' => $this->normalizeApplicationText((string)($input['desired_outcome'] ?? ''), (string)$defaultOutcome),
            'evidence_note' => $this->normalizeApplicationText((string)($input['evidence_note'] ?? ''), (string)$defaultEvidence),
            'reason_label' => $isRefund ? __('退款复核原因') : __('争议仲裁原因'),
            'desired_outcome_label' => __('期望处理结果'),
            'evidence_note_label' => __('证据说明'),
            'submit_label' => $isRefund ? __('提交退款复核申请') : __('提交争议仲裁申请'),
            'preview_notice' => __('提交前只生成申请预览，不创建案件、不冻结资金、不改写账本。'),
            'safety_notice' => $isRefund
                ? __('提交后平台进入退款复核，Agent 收入暂停结算。')
                : __('提交后资金进入争议冻结，仲裁员才能签发最终裁决。'),
        ];
    }

    private function normalizeApplicationText(string $value, string $default, int $limit = 700): string
    {
        $normalized = \preg_replace('/\s+/u', ' ', \trim($value));
        $normalized = \trim(\is_string($normalized) ? $normalized : $value);
        if ($normalized === '') {
            return $default;
        }

        if (\function_exists('mb_substr')) {
            return \mb_substr($normalized, 0, $limit, 'UTF-8');
        }

        return \substr($normalized, 0, $limit);
    }

    private function buildLedgerImpact(string $caseType): array
    {
        return $caseType === SettlementCase::TYPE_REFUND
            ? [
                __('买方资金进入待退款复核。'),
                __('平台服务费暂缓确认或按裁决减免。'),
                __('Agent 收入暂停出款，等待补证或双方确认。'),
            ]
            : [
                __('买方资金、平台服务费和 Agent 收入全部进入争议冻结。'),
                __('仲裁员必须基于范围、执行日志、哈希和质量摘要做出裁决。'),
                __('争议结果将写入 Agent 信誉重算输入。'),
            ];
    }

    private function buildArbitrationSteps(string $caseType): array
    {
        return $caseType === SettlementCase::TYPE_REFUND
            ? [
                __('检查缺失证据并限定补交时限。'),
                __('确认退款比例、平台服务费减免和 Agent 收入保留规则。'),
                __('补证通过则回到验收放款；补证失败则生成退款裁决。'),
            ]
            : [
                __('冻结资金并锁定双方提交的证据快照。'),
                __('仲裁员复核范围、权限、日志、哈希、质量摘要和异常清单。'),
                __('输出全额放款、部分放款、退款或返工裁决。'),
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

    private function buildCaseId(string $orderPublicId, string $caseType): string
    {
        $prefix = $caseType === SettlementCase::TYPE_REFUND ? 'A2A-REFUND-' : 'A2A-DISPUTE-';

        return $prefix . \strtoupper(\substr(\hash('crc32b', $orderPublicId . '|' . $caseType), 0, 6));
    }

    private function formatCaseTitle(string $caseType): string
    {
        return $caseType === SettlementCase::TYPE_REFUND
            ? (string) __('退款复核分支')
            : (string) __('争议仲裁分支');
    }

    private function formatPreviewStatus(string $caseType): string
    {
        return $caseType === SettlementCase::TYPE_REFUND
            ? (string) __('退款复核申请待提交')
            : (string) __('争议仲裁申请待提交');
    }

    private function formatCaseStatus(string $caseType, string $status): string
    {
        if ($status === SettlementCase::STATUS_ARBITRATION_RULED) {
            return (string) __('仲裁已裁决，钱包指令已生成');
        }

        return $caseType === SettlementCase::TYPE_REFUND
            ? (string) __('退款复核已开启，等待补证或裁决')
            : (string) __('争议仲裁已开启，资金已冻结');
    }

    private function formatDecision(string $caseType): string
    {
        return $caseType === SettlementCase::TYPE_REFUND
            ? 'refund_review_required'
            : 'arbitration_required';
    }

    private function buildSubmitUrl(string $orderPublicId, string $caseType): string
    {
        return '/a2a/frontend/settlement-case?case=' . \rawurlencode($caseType)
            . '&order=' . \rawurlencode($orderPublicId)
            . '&submit=1';
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
