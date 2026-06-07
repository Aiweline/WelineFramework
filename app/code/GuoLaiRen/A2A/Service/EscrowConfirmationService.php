<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use GuoLaiRen\A2A\Model\EscrowLedger;
use GuoLaiRen\A2A\Model\OrderDraft;
use GuoLaiRen\A2A\Model\TradeOrder;
use Weline\Framework\Database\Model;

class EscrowConfirmationService
{
    public function __construct(
        private readonly OrderDraft $orderDraftModel,
        private readonly TradeOrder $tradeOrderModel,
        private readonly EscrowLedger $escrowLedgerModel
    ) {
    }

    public function confirm(string $draftPublicId): array
    {
        $draftPublicId = \strtoupper(\trim($draftPublicId));
        if ($draftPublicId === '') {
            throw new \InvalidArgumentException((string) __('订单草稿不存在或已失效。'));
        }

        $draft = $this->freshModel($this->orderDraftModel);
        $draft->where(OrderDraft::schema_fields_PUBLIC_ID, $draftPublicId)->find()->fetch();
        if (!$draft->getId()) {
            throw new \InvalidArgumentException((string) __('订单草稿不存在或已失效。'));
        }

        $tradeOrder = $this->syncTradeOrder($draft);
        $draft->setData(OrderDraft::schema_fields_STATUS, OrderDraft::STATUS_ESCROW_CONFIRMED);
        $draft->save();

        $this->syncLedgerForConfirmedOrder($draft->getPublicId(), $tradeOrder->getPublicId());
        $ledgerRows = $this->loadLedgerRows($tradeOrder->getPublicId());

        return [
            'page_title' => __('A2A 正式托管订单'),
            'order_id' => $tradeOrder->getPublicId(),
            'draft_id' => $draft->getPublicId(),
            'status' => __('托管已确认，等待 Agent 补充执行范围'),
            'order' => [
                'sku_title' => (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_TITLE),
                'provider' => (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER),
                'amount' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_AMOUNT)),
                'platform_fee' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PLATFORM_FEE)),
                'provider_payout' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT)),
                'currency_code' => (string)$tradeOrder->getData(TradeOrder::schema_fields_CURRENCY_CODE),
                'status' => (string)$tradeOrder->getData(TradeOrder::schema_fields_STATUS),
                'provider_queue_status' => $this->formatProviderQueueStatus(
                    (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS)
                ),
                'provider_queue_status_code' => (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS),
                'provider_scope_url' => '/a2a/frontend/provider-scope?order=' . \rawurlencode($tradeOrder->getPublicId()),
                'trade_order_id' => $tradeOrder->getId(),
            ],
            'ledger_rows' => $ledgerRows,
            'provider_next_actions' => [
                __('Agent 补充执行范围、工具权限和交付证据清单。'),
                __('买方确认范围后订单进入受控执行队列。'),
                __('高权限 API 或数据外发动作必须先通过平台风控。'),
            ],
            'risk_rules' => [
                __('正式订单生成后，资金状态从草稿预估变为托管锁定。'),
                __('Provider 只能在授权范围内执行，超范围动作会触发冻结复核。'),
                __('未提交执行日志和验收证据时，账本不得进入放款状态。'),
            ],
            'persisted' => [
                'status' => __('已写入正式订单并锁定托管账本'),
                'trade_order_id' => $tradeOrder->getId(),
                'ledger_rows' => \count($ledgerRows),
            ],
        ];
    }

    private function syncTradeOrder(OrderDraft $draft): TradeOrder
    {
        $orderPublicId = $this->buildTradeOrderId($draft->getPublicId());
        $model = $this->freshModel($this->tradeOrderModel);
        $model->where(TradeOrder::schema_fields_DRAFT_PUBLIC_ID, $draft->getPublicId())->find()->fetch();
        $currentStatus = (string)$model->getData(TradeOrder::schema_fields_STATUS);
        $currentQueueStatus = (string)$model->getData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS);
        $currentMetadata = $this->decodeJson((string)$model->getData(TradeOrder::schema_fields_METADATA_JSON));
        $isRefundReview = $currentStatus === TradeOrder::STATUS_REFUND_REVIEW
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_REFUND_REVIEW;
        $isDisputeHold = $currentStatus === TradeOrder::STATUS_DISPUTE_ARBITRATION
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_DISPUTE_HOLD;
        $isArbitrationRuled = $currentStatus === TradeOrder::STATUS_ARBITRATION_RULED
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_ARBITRATION_RULED;
        $isReworkRequired = $currentStatus === TradeOrder::STATUS_REWORK_REQUIRED
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_REWORK_REQUIRED;
        $isAccepted = $currentStatus === TradeOrder::STATUS_ACCEPTED_RELEASED
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_ACCEPTED;
        $hasScopeSubmitted = $currentStatus === TradeOrder::STATUS_EXECUTION_READY
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_SCOPE_SUBMITTED;
        $nextStatus = match (true) {
            $isReworkRequired => TradeOrder::STATUS_REWORK_REQUIRED,
            $isArbitrationRuled => TradeOrder::STATUS_ARBITRATION_RULED,
            $isRefundReview => TradeOrder::STATUS_REFUND_REVIEW,
            $isDisputeHold => TradeOrder::STATUS_DISPUTE_ARBITRATION,
            $isAccepted => TradeOrder::STATUS_ACCEPTED_RELEASED,
            $hasScopeSubmitted => TradeOrder::STATUS_EXECUTION_READY,
            default => TradeOrder::STATUS_ESCROW_LOCKED,
        };
        $nextQueueStatus = match (true) {
            $isReworkRequired => TradeOrder::PROVIDER_QUEUE_REWORK_REQUIRED,
            $isArbitrationRuled => TradeOrder::PROVIDER_QUEUE_ARBITRATION_RULED,
            $isRefundReview => TradeOrder::PROVIDER_QUEUE_REFUND_REVIEW,
            $isDisputeHold => TradeOrder::PROVIDER_QUEUE_DISPUTE_HOLD,
            $isAccepted => TradeOrder::PROVIDER_QUEUE_ACCEPTED,
            $hasScopeSubmitted => TradeOrder::PROVIDER_QUEUE_SCOPE_SUBMITTED,
            default => TradeOrder::PROVIDER_QUEUE_PENDING_SCOPE,
        };

        $model->setData(TradeOrder::schema_fields_PUBLIC_ID, $orderPublicId);
        $model->setData(TradeOrder::schema_fields_DRAFT_PUBLIC_ID, $draft->getPublicId());
        $model->setData(TradeOrder::schema_fields_SKU_CODE, (string)$draft->getData(OrderDraft::schema_fields_SKU_CODE));
        $model->setData(TradeOrder::schema_fields_SKU_TITLE, (string)$draft->getData(OrderDraft::schema_fields_SKU_TITLE));
        $model->setData(TradeOrder::schema_fields_PROVIDER, (string)$draft->getData(OrderDraft::schema_fields_PROVIDER));
        $model->setData(TradeOrder::schema_fields_BUYER_REFERENCE, (string)$draft->getData(OrderDraft::schema_fields_BUYER_REFERENCE));
        $model->setData(TradeOrder::schema_fields_AMOUNT, $this->formatDecimal((float)$draft->getData(OrderDraft::schema_fields_AMOUNT)));
        $model->setData(TradeOrder::schema_fields_CURRENCY_CODE, (string)$draft->getData(OrderDraft::schema_fields_CURRENCY_CODE));
        $model->setData(TradeOrder::schema_fields_PLATFORM_FEE, $this->formatDecimal((float)$draft->getData(OrderDraft::schema_fields_PLATFORM_FEE)));
        $model->setData(TradeOrder::schema_fields_PROVIDER_PAYOUT, $this->formatDecimal((float)$draft->getData(OrderDraft::schema_fields_PROVIDER_PAYOUT)));
        $model->setData(TradeOrder::schema_fields_FEE_RATE, (string)$draft->getData(OrderDraft::schema_fields_FEE_RATE));
        $model->setData(TradeOrder::schema_fields_STATUS, $nextStatus);
        $model->setData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS, $nextQueueStatus);
        $model->setData(TradeOrder::schema_fields_CONFIRMED_AT, \date('Y-m-d H:i:s'));
        $model->setData(TradeOrder::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($currentMetadata, [
            'draft_public_id' => $draft->getPublicId(),
            'acceptance_rules_json' => (string)$draft->getData(OrderDraft::schema_fields_ACCEPTANCE_RULES_JSON),
            'provider_queue_reason' => 'buyer_escrow_confirmed',
        ])));
        $model->save();

        return $model;
    }

    private function syncLedgerForConfirmedOrder(string $draftPublicId, string $orderPublicId): void
    {
        $rows = [
            'buyer_freeze' => [
                EscrowLedger::schema_fields_LABEL => '买方冻结金额',
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_LOCKED,
            ],
            'platform_fee' => [
                EscrowLedger::schema_fields_LABEL => '平台服务费锁定',
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_RESERVED,
            ],
            'provider_payout' => [
                EscrowLedger::schema_fields_LABEL => 'Agent 待放款收入',
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_PENDING_RELEASE,
            ],
        ];

        foreach ($rows as $entryType => $row) {
            $model = $this->freshModel($this->escrowLedgerModel);
            $model->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
                ->where(EscrowLedger::schema_fields_ENTRY_TYPE, $entryType)
                ->find()
                ->fetch();

            if (!$model->getId()) {
                $model = $this->freshModel($this->escrowLedgerModel);
                $model->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $draftPublicId)
                    ->where(EscrowLedger::schema_fields_ENTRY_TYPE, $entryType)
                    ->find()
                    ->fetch();
            }

            if (!$model->getId()) {
                continue;
            }

            $model->setData(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $orderPublicId);
            $model->setData(EscrowLedger::schema_fields_LABEL, (string)$row[EscrowLedger::schema_fields_LABEL]);
            $model->setData(EscrowLedger::schema_fields_STATUS, (string)$row[EscrowLedger::schema_fields_STATUS]);
            $model->setData(EscrowLedger::schema_fields_METADATA_JSON, $this->encodeJson([
                'draft_public_id' => $draftPublicId,
                'trade_order_public_id' => $orderPublicId,
                'escrow_confirmed' => true,
            ]));
            $model->save();
        }
    }

    /**
     * @return list<array{label: string, amount: string, status: string, entry_type: string}>
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

    private function buildTradeOrderId(string $draftPublicId): string
    {
        if (\str_starts_with($draftPublicId, 'A2A-DRAFT-')) {
            return \str_replace('A2A-DRAFT-', 'A2A-ORDER-', $draftPublicId);
        }

        return 'A2A-ORDER-' . \strtoupper(\substr(\hash('crc32b', $draftPublicId), 0, 6));
    }

    private function formatUsd(float $amount): string
    {
        return '$' . \number_format($amount, 2);
    }

    private function formatDecimal(float $amount): string
    {
        return \number_format($amount, 2, '.', '');
    }

    private function formatProviderQueueStatus(string $status): string
    {
        return match ($status) {
            TradeOrder::PROVIDER_QUEUE_PENDING_SCOPE => (string) __('等待 Agent 补充范围'),
            TradeOrder::PROVIDER_QUEUE_SCOPE_SUBMITTED => (string) __('范围已提交，等待受控执行'),
            TradeOrder::PROVIDER_QUEUE_ACCEPTED => (string) __('已验收，等待结算出款'),
            TradeOrder::PROVIDER_QUEUE_REFUND_REVIEW => (string) __('退款复核中'),
            TradeOrder::PROVIDER_QUEUE_DISPUTE_HOLD => (string) __('争议仲裁冻结中'),
            default => $status,
        };
    }

    private function formatLedgerStatus(string $status): string
    {
        return match ($status) {
            EscrowLedger::STATUS_LOCKED => (string) __('已锁定'),
            EscrowLedger::STATUS_RESERVED => (string) __('已预留'),
            EscrowLedger::STATUS_PENDING_RELEASE => (string) __('待验收放款'),
            EscrowLedger::STATUS_RELEASED => (string) __('已释放'),
            EscrowLedger::STATUS_CAPTURED => (string) __('已入账'),
            EscrowLedger::STATUS_PAID => (string) __('待结算'),
            EscrowLedger::STATUS_REFUND_READY => (string) __('待退款'),
            EscrowLedger::STATUS_DISPUTE_HOLD => (string) __('争议冻结'),
            EscrowLedger::STATUS_WALLET_PENDING => (string) __('钱包指令待执行'),
            EscrowLedger::STATUS_REWORK_HOLD => (string) __('返工冻结'),
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
