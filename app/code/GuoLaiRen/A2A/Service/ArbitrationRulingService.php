<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use GuoLaiRen\A2A\Model\ArbitrationRuling;
use GuoLaiRen\A2A\Model\EscrowLedger;
use GuoLaiRen\A2A\Model\SettlementCase;
use GuoLaiRen\A2A\Model\TradeOrder;
use GuoLaiRen\A2A\Model\WalletInstruction;
use Weline\Framework\Database\Model;

class ArbitrationRulingService
{
    private const DEFAULT_RULING_TYPE = ArbitrationRuling::TYPE_PARTIAL_RELEASE;

    public function __construct(
        private readonly TradeOrder $tradeOrderModel,
        private readonly SettlementCase $settlementCaseModel,
        private readonly EscrowLedger $escrowLedgerModel,
        private readonly ArbitrationRuling $arbitrationRulingModel,
        private readonly WalletInstruction $walletInstructionModel
    ) {
    }

    public function issue(string $orderPublicId, string $rulingType): array
    {
        $orderPublicId = \strtoupper(\trim($orderPublicId));
        $rulingType = $this->normalizeRulingType($rulingType ?: self::DEFAULT_RULING_TYPE);
        if ($orderPublicId === '') {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        $tradeOrder = $this->loadTradeOrder($orderPublicId);
        $settlementCase = $this->loadDisputeCase($orderPublicId);
        $ledgerRows = $this->loadLedgerRows($orderPublicId);
        if ($ledgerRows === []) {
            throw new \InvalidArgumentException((string) __('托管账本不存在，不能生成仲裁裁决。'));
        }

        $amounts = $this->buildRulingAmounts($tradeOrder, $rulingType);
        $evidence = $this->buildRulingEvidence($tradeOrder, $settlementCase, $rulingType);
        $walletPlan = $this->buildWalletPlan($tradeOrder, $rulingType, $amounts);
        $ruling = $this->syncRuling($tradeOrder, $settlementCase, $rulingType, $amounts, $evidence, $walletPlan);
        $walletInstructions = $this->syncWalletInstructions($tradeOrder, $ruling, $walletPlan);
        $this->applyLedgerDecision($tradeOrder, $ruling, $walletPlan);
        $this->markCaseRuled($settlementCase, $ruling, $rulingType);
        $this->markTradeOrderRuled($tradeOrder, $ruling, $rulingType);

        return [
            'page_title' => __('A2A 仲裁裁决与钱包指令'),
            'order_id' => $tradeOrder->getPublicId(),
            'case_id' => $settlementCase->getPublicId(),
            'ruling_id' => $ruling->getPublicId(),
            'ruling_type' => $rulingType,
            'ruling_title' => $this->formatRulingTitle($rulingType),
            'ruling_status' => $this->formatRulingStatus($rulingType),
            'order' => [
                'sku_title' => (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_TITLE),
                'provider' => (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER),
                'amount' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_AMOUNT)),
                'platform_fee' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PLATFORM_FEE)),
                'provider_payout' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT)),
            ],
            'ruling_options' => $this->buildRulingOptions($tradeOrder->getPublicId(), $rulingType),
            'amounts' => [
                ['label' => __('买方退款'), 'value' => $this->formatUsd($amounts['buyer_refund'])],
                ['label' => __('平台服务费确认'), 'value' => $this->formatUsd($amounts['platform_fee'])],
                ['label' => __('Agent 放款'), 'value' => $this->formatUsd($amounts['provider_payout'])],
            ],
            'evidence' => $evidence,
            'wallet_plan' => $this->formatWalletPlan($walletPlan),
            'wallet_instructions' => $walletInstructions,
            'ledger_rows' => $this->loadLedgerRows($tradeOrder->getPublicId()),
            'persisted' => [
                'status' => __('已写入仲裁裁决与钱包指令边界'),
                'arbitration_ruling_id' => $ruling->getId(),
                'wallet_instructions' => \count($walletInstructions),
                'adapter_mode' => __('原型 dry-run，未执行真实资金动作'),
            ],
        ];
    }

    private function syncRuling(
        TradeOrder $tradeOrder,
        SettlementCase $settlementCase,
        string $rulingType,
        array $amounts,
        array $evidence,
        array $walletPlan
    ): ArbitrationRuling {
        $model = $this->freshModel($this->arbitrationRulingModel);
        $model->where(ArbitrationRuling::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())
            ->where(ArbitrationRuling::schema_fields_RULING_TYPE, $rulingType)
            ->find()
            ->fetch();
        $now = \date('Y-m-d H:i:s');

        $model->setData(ArbitrationRuling::schema_fields_PUBLIC_ID, $this->buildRulingId($tradeOrder->getPublicId(), $rulingType));
        $model->setData(ArbitrationRuling::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId());
        $model->setData(ArbitrationRuling::schema_fields_SETTLEMENT_CASE_PUBLIC_ID, $settlementCase->getPublicId());
        $model->setData(ArbitrationRuling::schema_fields_RULING_TYPE, $rulingType);
        $model->setData(ArbitrationRuling::schema_fields_STATUS, ArbitrationRuling::STATUS_ISSUED);
        $model->setData(ArbitrationRuling::schema_fields_DECISION, $this->formatDecisionCode($rulingType));
        $model->setData(ArbitrationRuling::schema_fields_BUYER_REFUND_AMOUNT, $this->formatDecimal($amounts['buyer_refund']));
        $model->setData(ArbitrationRuling::schema_fields_PLATFORM_FEE_AMOUNT, $this->formatDecimal($amounts['platform_fee']));
        $model->setData(ArbitrationRuling::schema_fields_PROVIDER_PAYOUT_AMOUNT, $this->formatDecimal($amounts['provider_payout']));
        $model->setData(ArbitrationRuling::schema_fields_CURRENCY_CODE, (string)$tradeOrder->getData(TradeOrder::schema_fields_CURRENCY_CODE));
        $model->setData(ArbitrationRuling::schema_fields_EVIDENCE_JSON, $this->encodeJson($evidence));
        $model->setData(ArbitrationRuling::schema_fields_WALLET_PLAN_JSON, $this->encodeJson($walletPlan));
        $model->setData(ArbitrationRuling::schema_fields_METADATA_JSON, $this->encodeJson([
            'adapter_boundary' => 'dry_run_wallet_instruction',
            'settlement_case_public_id' => $settlementCase->getPublicId(),
            'no_real_funds_moved' => true,
        ]));
        $model->setData(ArbitrationRuling::schema_fields_RULED_AT, $now);
        $model->save();

        return $model;
    }

    private function syncWalletInstructions(TradeOrder $tradeOrder, ArbitrationRuling $ruling, array $walletPlan): array
    {
        $rows = [];
        foreach ($walletPlan as $plan) {
            $model = $this->freshModel($this->walletInstructionModel);
            $publicId = $this->buildWalletInstructionId(
                $tradeOrder->getPublicId(),
                $ruling->getPublicId(),
                (string)$plan['entry_type'],
                (string)$plan['instruction_type']
            );
            $model->where(WalletInstruction::schema_fields_PUBLIC_ID, $publicId)->find()->fetch();
            $model->setData(WalletInstruction::schema_fields_PUBLIC_ID, $publicId);
            $model->setData(WalletInstruction::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId());
            $model->setData(WalletInstruction::schema_fields_RULING_PUBLIC_ID, $ruling->getPublicId());
            $model->setData(WalletInstruction::schema_fields_LEDGER_ENTRY_TYPE, (string)$plan['entry_type']);
            $model->setData(WalletInstruction::schema_fields_INSTRUCTION_TYPE, (string)$plan['instruction_type']);
            $model->setData(WalletInstruction::schema_fields_AMOUNT, $this->formatDecimal((float)$plan['amount']));
            $model->setData(WalletInstruction::schema_fields_CURRENCY_CODE, (string)$tradeOrder->getData(TradeOrder::schema_fields_CURRENCY_CODE));
            $model->setData(WalletInstruction::schema_fields_ADAPTER_CODE, 'prototype_wallet');
            $currentStatus = (string)$model->getData(WalletInstruction::schema_fields_ADAPTER_STATUS);
            $adapterStatus = $this->shouldPreserveAdapterExecutionStatus($currentStatus)
                ? $currentStatus
                : (string)$plan['adapter_status'];
            $metadata = $this->decodeJson((string)$model->getData(WalletInstruction::schema_fields_METADATA_JSON));
            $model->setData(WalletInstruction::schema_fields_ADAPTER_STATUS, $adapterStatus);
            $model->setData(WalletInstruction::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
                'label' => (string)$plan['label'],
                'note' => (string)$plan['note'],
                'no_real_funds_moved' => true,
            ])));
            $model->setData(WalletInstruction::schema_fields_QUEUED_AT, \date('Y-m-d H:i:s'));
            $model->save();
            $rows[] = [
                'id' => $publicId,
                'entry_type' => (string)$plan['entry_type'],
                'instruction_type' => $this->formatInstructionType((string)$plan['instruction_type']),
                'amount' => $this->formatUsd((float)$plan['amount']),
                'adapter_status' => $this->formatAdapterStatus($adapterStatus),
                'idempotency_key' => (string)$model->getData(WalletInstruction::schema_fields_IDEMPOTENCY_KEY),
                'external_reference' => (string)$model->getData(WalletInstruction::schema_fields_EXTERNAL_REFERENCE),
                'note' => (string)$plan['note'],
            ];
        }

        return $rows;
    }

    private function applyLedgerDecision(TradeOrder $tradeOrder, ArbitrationRuling $ruling, array $walletPlan): void
    {
        foreach ($walletPlan as $plan) {
            $model = $this->freshModel($this->escrowLedgerModel);
            $model->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())
                ->where(EscrowLedger::schema_fields_ENTRY_TYPE, (string)$plan['entry_type'])
                ->find()
                ->fetch();
            if (!$model->getId()) {
                continue;
            }

            $metadata = $this->decodeJson((string)$model->getData(EscrowLedger::schema_fields_METADATA_JSON));
            $model->setData(EscrowLedger::schema_fields_LABEL, (string)$plan['ledger_label']);
            $model->setData(EscrowLedger::schema_fields_STATUS, (string)$plan['ledger_status']);
            $model->setData(EscrowLedger::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
                'arbitration_ruling_public_id' => $ruling->getPublicId(),
                'wallet_instruction_type' => (string)$plan['instruction_type'],
                'wallet_adapter_status' => (string)$plan['adapter_status'],
                'no_real_funds_moved' => true,
            ])));
            $model->save();
        }
    }

    private function markCaseRuled(SettlementCase $settlementCase, ArbitrationRuling $ruling, string $rulingType): void
    {
        $metadata = $this->decodeJson((string)$settlementCase->getData(SettlementCase::schema_fields_METADATA_JSON));
        $settlementCase->setData(SettlementCase::schema_fields_STATUS, SettlementCase::STATUS_ARBITRATION_RULED);
        $settlementCase->setData(SettlementCase::schema_fields_DECISION, $this->formatDecisionCode($rulingType));
        $settlementCase->setData(SettlementCase::schema_fields_RESOLVED_AT, \date('Y-m-d H:i:s'));
        $settlementCase->setData(SettlementCase::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'arbitration_ruling_public_id' => $ruling->getPublicId(),
            'final_ruling_type' => $rulingType,
        ])));
        $settlementCase->save();
    }

    private function markTradeOrderRuled(TradeOrder $tradeOrder, ArbitrationRuling $ruling, string $rulingType): void
    {
        $metadata = $this->decodeJson((string)$tradeOrder->getData(TradeOrder::schema_fields_METADATA_JSON));
        $tradeOrder->setData(
            TradeOrder::schema_fields_STATUS,
            $rulingType === ArbitrationRuling::TYPE_REWORK
                ? TradeOrder::STATUS_REWORK_REQUIRED
                : TradeOrder::STATUS_ARBITRATION_RULED
        );
        $tradeOrder->setData(
            TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS,
            $rulingType === ArbitrationRuling::TYPE_REWORK
                ? TradeOrder::PROVIDER_QUEUE_REWORK_REQUIRED
                : TradeOrder::PROVIDER_QUEUE_ARBITRATION_RULED
        );
        $tradeOrder->setData(TradeOrder::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'arbitration_ruling_public_id' => $ruling->getPublicId(),
            'final_ruling_type' => $rulingType,
            'wallet_adapter_mode' => 'dry_run',
        ])));
        $tradeOrder->save();
    }

    private function buildWalletPlan(TradeOrder $tradeOrder, string $rulingType, array $amounts): array
    {
        $amount = (float)$tradeOrder->getData(TradeOrder::schema_fields_AMOUNT);
        $platformFee = (float)$tradeOrder->getData(TradeOrder::schema_fields_PLATFORM_FEE);
        $providerPayout = (float)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT);

        return match ($rulingType) {
            ArbitrationRuling::TYPE_FULL_RELEASE => [
                $this->walletPlanRow('buyer_freeze', 'release_to_provider', $amount, EscrowLedger::STATUS_WALLET_PENDING, __('买方资金按全额放款裁决待钱包执行'), __('释放买方托管本金给 Agent。')),
                $this->walletPlanRow('platform_fee', 'capture_platform_fee', $platformFee, EscrowLedger::STATUS_WALLET_PENDING, __('平台服务费按全额放款裁决待入账'), __('确认平台服务费收入。')),
                $this->walletPlanRow('provider_payout', 'pay_provider', $providerPayout, EscrowLedger::STATUS_WALLET_PENDING, __('Agent 全额收入待钱包出款'), __('向 Agent 钱包发起全额出款指令。')),
            ],
            ArbitrationRuling::TYPE_REFUND => [
                $this->walletPlanRow('buyer_freeze', 'refund_buyer', $amount, EscrowLedger::STATUS_REFUND_READY, __('买方全额退款待钱包执行'), __('退回买方托管本金。')),
                $this->walletPlanRow('platform_fee', 'reverse_platform_fee', $platformFee, EscrowLedger::STATUS_REFUND_READY, __('平台服务费全额冲回待钱包执行'), __('取消平台服务费确认。')),
                $this->walletPlanRow('provider_payout', 'block_provider_payout', 0.0, EscrowLedger::STATUS_DISPUTE_HOLD, __('Agent 收入按退款裁决取消'), __('不生成 Agent 出款。')),
            ],
            ArbitrationRuling::TYPE_REWORK => [
                $this->walletPlanRow('buyer_freeze', 'continue_hold', $amount, EscrowLedger::STATUS_REWORK_HOLD, __('买方资金继续冻结等待返工'), __('返工前不移动买方资金。'), WalletInstruction::STATUS_BLOCKED_HOLD),
                $this->walletPlanRow('platform_fee', 'continue_hold', $platformFee, EscrowLedger::STATUS_REWORK_HOLD, __('平台服务费继续冻结等待返工'), __('返工前不确认平台服务费。'), WalletInstruction::STATUS_BLOCKED_HOLD),
                $this->walletPlanRow('provider_payout', 'continue_hold', $providerPayout, EscrowLedger::STATUS_REWORK_HOLD, __('Agent 收入继续冻结等待返工'), __('返工验收前不出款。'), WalletInstruction::STATUS_BLOCKED_HOLD),
            ],
            default => [
                $this->walletPlanRow('buyer_freeze', 'refund_buyer', $amounts['buyer_refund'], EscrowLedger::STATUS_REFUND_READY, __('买方部分退款待钱包执行'), __('按裁决比例退回买方部分托管资金。')),
                $this->walletPlanRow('platform_fee', 'capture_platform_fee', $amounts['platform_fee'], EscrowLedger::STATUS_WALLET_PENDING, __('平台服务费部分确认待钱包执行'), __('平台只确认裁决后的服务费。')),
                $this->walletPlanRow('provider_payout', 'pay_provider', $amounts['provider_payout'], EscrowLedger::STATUS_WALLET_PENDING, __('Agent 部分放款待钱包执行'), __('向 Agent 钱包发起部分出款指令。')),
            ],
        };
    }

    private function walletPlanRow(
        string $entryType,
        string $instructionType,
        float $amount,
        string $ledgerStatus,
        string|\Stringable $ledgerLabel,
        string|\Stringable $note,
        string $adapterStatus = WalletInstruction::STATUS_DRY_RUN_QUEUED
    ): array {
        return [
            'entry_type' => $entryType,
            'instruction_type' => $instructionType,
            'amount' => $this->roundAmount($amount),
            'ledger_status' => $ledgerStatus,
            'ledger_label' => (string)$ledgerLabel,
            'adapter_status' => $adapterStatus,
            'label' => (string)$ledgerLabel,
            'note' => (string)$note,
        ];
    }

    private function buildRulingAmounts(TradeOrder $tradeOrder, string $rulingType): array
    {
        $amount = (float)$tradeOrder->getData(TradeOrder::schema_fields_AMOUNT);
        $platformFee = (float)$tradeOrder->getData(TradeOrder::schema_fields_PLATFORM_FEE);
        $providerPayout = (float)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT);

        return match ($rulingType) {
            ArbitrationRuling::TYPE_FULL_RELEASE => [
                'buyer_refund' => 0.0,
                'platform_fee' => $this->roundAmount($platformFee),
                'provider_payout' => $this->roundAmount($providerPayout),
            ],
            ArbitrationRuling::TYPE_REFUND => [
                'buyer_refund' => $this->roundAmount($amount),
                'platform_fee' => 0.0,
                'provider_payout' => 0.0,
            ],
            ArbitrationRuling::TYPE_REWORK => [
                'buyer_refund' => 0.0,
                'platform_fee' => 0.0,
                'provider_payout' => 0.0,
            ],
            default => [
                'buyer_refund' => $this->roundAmount($amount * 0.30),
                'platform_fee' => $this->roundAmount($platformFee * 0.50),
                'provider_payout' => $this->roundAmount($amount - ($amount * 0.30) - ($platformFee * 0.50)),
            ],
        };
    }

    private function buildRulingEvidence(TradeOrder $tradeOrder, SettlementCase $settlementCase, string $rulingType): array
    {
        return [
            __('裁决引用争议单：%{1}', $settlementCase->getPublicId()),
            __('裁决对象：%{1}', $tradeOrder->getPublicId()),
            __('证据范围：执行范围、交付日志、输出哈希、验收快照和异常清单。'),
            __('裁决类型：%{1}', $this->formatRulingTitle($rulingType)),
            __('钱包边界：仅生成 dry-run 指令，不执行真实资金动作。'),
        ];
    }

    private function loadTradeOrder(string $orderPublicId): TradeOrder
    {
        $tradeOrder = $this->freshModel($this->tradeOrderModel);
        $tradeOrder->where(TradeOrder::schema_fields_PUBLIC_ID, $orderPublicId)->find()->fetch();
        if (!$tradeOrder->getId()) {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        return $tradeOrder;
    }

    private function loadDisputeCase(string $orderPublicId): SettlementCase
    {
        $settlementCase = $this->freshModel($this->settlementCaseModel);
        $settlementCase->where(SettlementCase::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
            ->where(SettlementCase::schema_fields_CASE_TYPE, SettlementCase::TYPE_DISPUTE)
            ->find()
            ->fetch();
        if (!$settlementCase->getId()) {
            throw new \InvalidArgumentException((string) __('订单尚未进入争议仲裁，不能生成最终裁决。'));
        }

        return $settlementCase;
    }

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

    private function buildRulingOptions(string $orderPublicId, string $currentType): array
    {
        $options = [];
        foreach ($this->rulingLabels() as $type => $label) {
            $options[] = [
                'type' => $type,
                'label' => $label,
                'active' => $type === $currentType,
                'href' => '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($orderPublicId) . '&ruling=' . \rawurlencode($type),
            ];
        }

        return $options;
    }

    private function formatWalletPlan(array $walletPlan): array
    {
        return \array_values(\array_map(fn(array $plan): array => [
            'entry_type' => (string)$plan['entry_type'],
            'instruction_type' => $this->formatInstructionType((string)$plan['instruction_type']),
            'amount' => $this->formatUsd((float)$plan['amount']),
            'adapter_status' => $this->formatAdapterStatus((string)$plan['adapter_status']),
            'ledger_status' => $this->formatLedgerStatus((string)$plan['ledger_status']),
            'note' => (string)$plan['note'],
        ], $walletPlan));
    }

    private function normalizeRulingType(string $rulingType): string
    {
        $rulingType = \strtolower(\trim($rulingType));
        if (!\array_key_exists($rulingType, $this->rulingLabels())) {
            throw new \InvalidArgumentException((string) __('仲裁裁决类型无效。'));
        }

        return $rulingType;
    }

    private function rulingLabels(): array
    {
        return [
            ArbitrationRuling::TYPE_FULL_RELEASE => __('全额放款'),
            ArbitrationRuling::TYPE_PARTIAL_RELEASE => __('部分放款'),
            ArbitrationRuling::TYPE_REFUND => __('全额退款'),
            ArbitrationRuling::TYPE_REWORK => __('返工补交'),
        ];
    }

    private function formatRulingTitle(string $rulingType): string
    {
        return (string)($this->rulingLabels()[$rulingType] ?? $rulingType);
    }

    private function formatRulingStatus(string $rulingType): string
    {
        return match ($rulingType) {
            ArbitrationRuling::TYPE_FULL_RELEASE => (string) __('裁决全额放款，等待钱包适配器执行'),
            ArbitrationRuling::TYPE_REFUND => (string) __('裁决全额退款，等待钱包适配器执行'),
            ArbitrationRuling::TYPE_REWORK => (string) __('裁决返工补交，资金继续冻结'),
            default => (string) __('裁决部分放款，等待钱包适配器执行'),
        };
    }

    private function formatDecisionCode(string $rulingType): string
    {
        return match ($rulingType) {
            ArbitrationRuling::TYPE_FULL_RELEASE => 'full_release_awarded',
            ArbitrationRuling::TYPE_REFUND => 'full_refund_awarded',
            ArbitrationRuling::TYPE_REWORK => 'rework_required',
            default => 'partial_release_awarded',
        };
    }

    private function formatInstructionType(string $type): string
    {
        return match ($type) {
            'release_to_provider' => (string) __('释放托管本金'),
            'capture_platform_fee' => (string) __('确认平台服务费'),
            'pay_provider' => (string) __('Agent 出款'),
            'refund_buyer' => (string) __('买方退款'),
            'reverse_platform_fee' => (string) __('平台服务费冲回'),
            'block_provider_payout' => (string) __('取消 Agent 出款'),
            'continue_hold' => (string) __('继续冻结'),
            default => $type,
        };
    }

    private function formatAdapterStatus(string $status): string
    {
        return match ($status) {
            WalletInstruction::STATUS_BLOCKED_HOLD => (string) __('冻结保持，不提交钱包'),
            WalletInstruction::STATUS_DRY_RUN_QUEUED => (string) __('dry-run 已排队'),
            WalletInstruction::STATUS_ADAPTER_PENDING => (string) __('待提交适配器'),
            WalletInstruction::STATUS_ADAPTER_CONFIRMED => (string) __('dry-run 已确认'),
            WalletInstruction::STATUS_ADAPTER_FAILED => (string) __('执行失败'),
            default => $status,
        };
    }

    private function shouldPreserveAdapterExecutionStatus(string $status): bool
    {
        return \in_array($status, [
            WalletInstruction::STATUS_ADAPTER_CONFIRMED,
            WalletInstruction::STATUS_ADAPTER_FAILED,
        ], true);
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

    private function buildRulingId(string $orderPublicId, string $rulingType): string
    {
        return 'A2A-RULING-' . \strtoupper(\substr(\hash('crc32b', $orderPublicId . '|ruling|' . $rulingType), 0, 6));
    }

    private function buildWalletInstructionId(string $orderPublicId, string $rulingPublicId, string $entryType, string $instructionType): string
    {
        return 'A2A-WALLET-' . \strtoupper(\substr(\hash('crc32b', $orderPublicId . '|' . $rulingPublicId . '|' . $entryType . '|' . $instructionType), 0, 8));
    }

    private function roundAmount(float $amount): float
    {
        return \round($amount, 2);
    }

    private function formatDecimal(float $amount): string
    {
        return \number_format($amount, 2, '.', '');
    }

    private function formatUsd(float $amount): string
    {
        return '$' . \number_format($amount, 2);
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
