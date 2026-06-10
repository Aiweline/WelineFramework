<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

use Aiweline\A2A\Model\CapabilitySku;
use Aiweline\A2A\Model\EscrowLedger;
use Aiweline\A2A\Model\OrderDraft;
use Weline\Framework\Database\Model;

class PurchaseIntentService
{
    private const PLATFORM_FEE_RATE = 0.08;

    public function __construct(
        private readonly TradingWorkspaceDataProvider $workspaceDataProvider,
        private readonly CapabilitySku $capabilitySkuModel,
        private readonly OrderDraft $orderDraftModel,
        private readonly EscrowLedger $escrowLedgerModel
    ) {
    }

    public function createDraft(string $skuCode): array
    {
        $sku = $this->workspaceDataProvider->getAbilitySkuByCode($skuCode);
        if ($sku === null) {
            throw new \InvalidArgumentException((string) __('能力 SKU 不存在或已下架。'));
        }

        $amount = $this->extractAmount((string) ($sku['price'] ?? '0'));
        $platformFee = \round($amount * self::PLATFORM_FEE_RATE, 2);
        $providerPayout = \max(0, \round($amount - $platformFee, 2));
        $code = (string) $sku['code'];
        $orderId = $this->buildOrderId($code);
        $acceptanceRules = [
            __('订单进入执行前必须完成托管确认。'),
            __('交付物必须包含执行日志、验收证据和输出文件。'),
            __('验收失败时资金保持冻结，并进入返工或争议分支。'),
        ];

        $capabilitySku = $this->syncCapabilitySku($sku, $amount);
        $orderDraft = $this->syncOrderDraft(
            $orderId,
            $sku,
            $amount,
            $platformFee,
            $providerPayout,
            $acceptanceRules
        );
        $ledgerRows = $this->isEscrowConfirmed($orderDraft)
            ? $this->countLedgerRows($this->buildConfirmedOrderId($orderId))
            : $this->syncLedgerRows($orderId, $amount, $platformFee, $providerPayout);
        $statusText = $this->isEscrowConfirmed($orderDraft)
            ? __('托管已确认，正式订单已生成')
            : __('待买方确认托管');

        return [
            'page_title' => __('A2A 托管订单草稿'),
            'order_id' => $orderId,
            'status' => $statusText,
            'sku' => $sku,
            'escrow' => [
                'amount' => $this->formatUsd($amount),
                'platform_fee' => $this->formatUsd($platformFee),
                'provider_payout' => $this->formatUsd($providerPayout),
                'fee_rate' => '8%',
            ],
            'ledger_preview' => [
                ['label' => __('买方冻结金额'), 'value' => $this->formatUsd($amount), 'state' => 'active'],
                ['label' => __('平台服务费预估'), 'value' => $this->formatUsd($platformFee), 'state' => 'normal'],
                ['label' => __('Agent 可结算收入'), 'value' => $this->formatUsd($providerPayout), 'state' => 'success'],
            ],
            'acceptance_rules' => $acceptanceRules,
            'next_actions' => [
                __('确认托管并生成正式订单'),
                __('邀请 Agent 补充执行范围'),
                __('返回能力商店继续对比'),
            ],
            'persisted' => [
                'status' => __('已写入订单草稿与托管账本'),
                'capability_sku_id' => $capabilitySku->getId(),
                'order_draft_id' => $orderDraft->getId(),
                'ledger_rows' => $ledgerRows,
            ],
        ];
    }

    private function syncCapabilitySku(array $sku, float $amount): CapabilitySku
    {
        $code = \strtolower((string)($sku['code'] ?? ''));
        $model = $this->freshModel($this->capabilitySkuModel);
        $model->where(CapabilitySku::schema_fields_CODE, $code)->find()->fetch();

        $model->setData(CapabilitySku::schema_fields_CODE, $code);
        $model->setData(CapabilitySku::schema_fields_TITLE, (string)($sku['title'] ?? ''));
        $model->setData(CapabilitySku::schema_fields_PROVIDER, (string)($sku['provider'] ?? ''));
        $model->setData(CapabilitySku::schema_fields_SUPPLY_TYPE, (string)($sku['supply_type'] ?? ''));
        $model->setData(CapabilitySku::schema_fields_TIER, (string)($sku['tier'] ?? ''));
        $model->setData(CapabilitySku::schema_fields_TIER_STATE, (string)($sku['tier_state'] ?? ''));
        $model->setData(CapabilitySku::schema_fields_RARITY, (string)($sku['rarity'] ?? ''));
        $model->setData(CapabilitySku::schema_fields_PRICE_AMOUNT, $this->formatDecimal($amount));
        $model->setData(CapabilitySku::schema_fields_CURRENCY_CODE, 'USD');
        $model->setData(CapabilitySku::schema_fields_UNIT, (string)($sku['unit'] ?? ''));
        $model->setData(CapabilitySku::schema_fields_PURCHASES, $this->extractInteger((string)($sku['purchases'] ?? '0')));
        $model->setData(CapabilitySku::schema_fields_SUMMARY, (string)($sku['summary'] ?? ''));
        $model->setTagsArray($this->normalizeStringList($sku['tags'] ?? []));
        $model->setTrustArray($this->normalizeStringList($sku['trust'] ?? []));
        $model->setData(CapabilitySku::schema_fields_STATUS, CapabilitySku::STATUS_ACTIVE);
        $model->save();

        return $model;
    }

    private function syncOrderDraft(
        string $orderId,
        array $sku,
        float $amount,
        float $platformFee,
        float $providerPayout,
        array $acceptanceRules
    ): OrderDraft {
        $model = $this->freshModel($this->orderDraftModel);
        $model->where(OrderDraft::schema_fields_PUBLIC_ID, $orderId)->find()->fetch();
        $currentStatus = (string)$model->getData(OrderDraft::schema_fields_STATUS);

        $model->setData(OrderDraft::schema_fields_PUBLIC_ID, $orderId);
        $model->setData(OrderDraft::schema_fields_SKU_CODE, (string)($sku['code'] ?? ''));
        $model->setData(OrderDraft::schema_fields_SKU_TITLE, (string)($sku['title'] ?? ''));
        $model->setData(OrderDraft::schema_fields_PROVIDER, (string)($sku['provider'] ?? ''));
        $model->setData(OrderDraft::schema_fields_BUYER_REFERENCE, 'prototype-buyer');
        $model->setData(OrderDraft::schema_fields_SOURCE_TYPE, OrderDraft::SOURCE_SKU_PURCHASE);
        $model->setData(OrderDraft::schema_fields_REQUEST_PUBLIC_ID, '');
        $model->setData(OrderDraft::schema_fields_QUOTE_PUBLIC_ID, '');
        $model->setData(OrderDraft::schema_fields_AMOUNT, $this->formatDecimal($amount));
        $model->setData(OrderDraft::schema_fields_CURRENCY_CODE, 'USD');
        $model->setData(OrderDraft::schema_fields_PLATFORM_FEE, $this->formatDecimal($platformFee));
        $model->setData(OrderDraft::schema_fields_PROVIDER_PAYOUT, $this->formatDecimal($providerPayout));
        $model->setData(OrderDraft::schema_fields_FEE_RATE, '0.0800');
        $model->setData(
            OrderDraft::schema_fields_STATUS,
            $currentStatus === OrderDraft::STATUS_ESCROW_CONFIRMED
                ? OrderDraft::STATUS_ESCROW_CONFIRMED
                : OrderDraft::STATUS_PENDING_ESCROW
        );
        $model->setData(OrderDraft::schema_fields_ACCEPTANCE_RULES_JSON, $this->encodeJson($this->normalizeStringList($acceptanceRules)));
        $model->setData(OrderDraft::schema_fields_METADATA_JSON, $this->encodeJson([
            'supply_type' => (string)($sku['supply_type'] ?? ''),
            'tier' => (string)($sku['tier'] ?? ''),
            'rarity' => (string)($sku['rarity'] ?? ''),
            'unit' => (string)($sku['unit'] ?? ''),
        ]));
        $model->save();

        return $model;
    }

    private function syncLedgerRows(string $orderId, float $amount, float $platformFee, float $providerPayout): int
    {
        $rows = [
            [
                EscrowLedger::schema_fields_ENTRY_TYPE => 'buyer_freeze',
                EscrowLedger::schema_fields_LABEL => '买方冻结金额',
                EscrowLedger::schema_fields_AMOUNT => $amount,
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_LOCKED,
            ],
            [
                EscrowLedger::schema_fields_ENTRY_TYPE => 'platform_fee',
                EscrowLedger::schema_fields_LABEL => '平台服务费预估',
                EscrowLedger::schema_fields_AMOUNT => $platformFee,
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_RESERVED,
            ],
            [
                EscrowLedger::schema_fields_ENTRY_TYPE => 'provider_payout',
                EscrowLedger::schema_fields_LABEL => 'Agent 可结算收入',
                EscrowLedger::schema_fields_AMOUNT => $providerPayout,
                EscrowLedger::schema_fields_STATUS => EscrowLedger::STATUS_PENDING_RELEASE,
            ],
        ];

        $saved = 0;
        foreach ($rows as $row) {
            $model = $this->freshModel($this->escrowLedgerModel);
            $model->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $orderId)
                ->where(EscrowLedger::schema_fields_ENTRY_TYPE, (string)$row[EscrowLedger::schema_fields_ENTRY_TYPE])
                ->find()
                ->fetch();
            $model->setData(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $orderId);
            $model->setData(EscrowLedger::schema_fields_ENTRY_TYPE, (string)$row[EscrowLedger::schema_fields_ENTRY_TYPE]);
            $model->setData(EscrowLedger::schema_fields_LABEL, (string)$row[EscrowLedger::schema_fields_LABEL]);
            $model->setData(EscrowLedger::schema_fields_AMOUNT, $this->formatDecimal((float)$row[EscrowLedger::schema_fields_AMOUNT]));
            $model->setData(EscrowLedger::schema_fields_CURRENCY_CODE, 'USD');
            $model->setData(EscrowLedger::schema_fields_STATUS, (string)$row[EscrowLedger::schema_fields_STATUS]);
            $model->setData(EscrowLedger::schema_fields_METADATA_JSON, $this->encodeJson([
                'order_public_id' => $orderId,
                'prototype_entry' => true,
            ]));
            $model->save();
            ++$saved;
        }

        return $saved;
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

    private function isEscrowConfirmed(OrderDraft $orderDraft): bool
    {
        return (string)$orderDraft->getData(OrderDraft::schema_fields_STATUS) === OrderDraft::STATUS_ESCROW_CONFIRMED;
    }

    private function countLedgerRows(string $orderPublicId): int
    {
        $rows = $this->freshModel($this->escrowLedgerModel)
            ->where(EscrowLedger::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \count($rows) : 0;
    }

    private function extractAmount(string $price): float
    {
        $amount = \preg_replace('/[^0-9.]/', '', $price);
        if ($amount === null || $amount === '') {
            return 0.0;
        }

        return (float) $amount;
    }

    private function extractInteger(string $value): int
    {
        $number = \preg_replace('/[^0-9]/', '', $value);
        if ($number === null || $number === '') {
            return 0;
        }

        return (int) $number;
    }

    private function formatUsd(float $amount): string
    {
        return '$' . \number_format($amount, 2);
    }

    private function formatDecimal(float $amount): string
    {
        return \number_format($amount, 2, '.', '');
    }

    private function buildOrderId(string $skuCode): string
    {
        return 'A2A-DRAFT-' . \strtoupper(\substr(\hash('crc32b', $skuCode), 0, 6));
    }

    private function buildConfirmedOrderId(string $draftPublicId): string
    {
        if (\str_starts_with($draftPublicId, 'A2A-DRAFT-')) {
            return \str_replace('A2A-DRAFT-', 'A2A-ORDER-', $draftPublicId);
        }

        return 'A2A-ORDER-' . \strtoupper(\substr(\hash('crc32b', $draftPublicId), 0, 6));
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        return \array_values(\array_filter(
            \array_map(static fn(mixed $item): string => \trim((string)$item), $items),
            static fn(string $item): bool => $item !== ''
        ));
    }

    private function encodeJson(array $payload): string
    {
        return \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
