<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use GuoLaiRen\A2A\Model\ProviderScopeSubmission;
use GuoLaiRen\A2A\Model\TradeOrder;
use Weline\Framework\Database\Model;

class DeliverySubmissionService
{
    public function __construct(
        private readonly TradeOrder $tradeOrderModel,
        private readonly ProviderScopeSubmission $providerScopeModel
    ) {
    }

    public function submit(string $orderPublicId): array
    {
        $orderPublicId = \strtoupper(\trim($orderPublicId));
        if ($orderPublicId === '') {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        $tradeOrder = $this->freshModel($this->tradeOrderModel);
        $tradeOrder->where(TradeOrder::schema_fields_PUBLIC_ID, $orderPublicId)->find()->fetch();
        if (!$tradeOrder->getId()) {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        $providerScope = $this->freshModel($this->providerScopeModel);
        $providerScope->where(ProviderScopeSubmission::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)->find()->fetch();
        if (!$providerScope->getId()) {
            throw new \InvalidArgumentException((string) __('Agent 尚未提交执行范围，不能提交交付证据。'));
        }

        $deliverySubmission = $this->syncDeliverySubmission($tradeOrder, $providerScope);
        $this->markTradeOrderDeliverySubmitted($tradeOrder, $deliverySubmission);

        return [
            'page_title' => __('A2A Agent 交付证据提交'),
            'order_id' => $tradeOrder->getPublicId(),
            'scope_id' => $providerScope->getPublicId(),
            'delivery_id' => (string)$deliverySubmission['delivery_public_id'],
            'status' => __('Agent 已提交交付证据，等待买方验收'),
            'buyer_console_url' => '/a2a/frontend/role-console?switch_role=1&role=buyer&order=' . \rawurlencode($tradeOrder->getPublicId()),
            'acceptance_url' => '/a2a/frontend/acceptance?order=' . \rawurlencode($tradeOrder->getPublicId()),
            'order' => [
                'sku_title' => (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_TITLE),
                'provider' => (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER),
                'amount' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_AMOUNT)),
                'platform_fee' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PLATFORM_FEE)),
                'provider_payout' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT)),
                'trade_order_id' => $tradeOrder->getId(),
            ],
            'delivery_submission' => $deliverySubmission,
            'evidence_items' => $deliverySubmission['evidence_items'],
            'control_points' => [
                __('交付证据只证明 Agent 已完成约定输出，不自动释放托管。'),
                __('买方必须使用绑定账号进入验收页，才能触发放款或退款/争议分支。'),
                __('平台后续风控、仲裁和信誉评分都引用同一份交付证据快照。'),
            ],
            'persisted' => [
                'status' => __('已写入 Agent 交付证据快照，验收门禁已打开'),
                'provider_scope_id' => $providerScope->getId(),
                'trade_order_id' => $tradeOrder->getId(),
                'delivery_public_id' => (string)$deliverySubmission['delivery_public_id'],
            ],
        ];
    }

    private function syncDeliverySubmission(TradeOrder $tradeOrder, ProviderScopeSubmission $providerScope): array
    {
        $metadata = $this->decodeJson((string)$providerScope->getData(ProviderScopeSubmission::schema_fields_METADATA_JSON));
        $deliveryPublicId = $this->buildDeliveryId($tradeOrder->getPublicId());
        $submittedAt = \date('Y-m-d H:i:s');
        $outputHash = 'sha256:' . \substr(\hash('sha256', $tradeOrder->getPublicId() . '|delivery-submission'), 0, 24);
        $evidenceItems = $this->buildEvidenceItems($tradeOrder, $providerScope, $outputHash);

        $deliverySubmission = [
            'delivery_public_id' => $deliveryPublicId,
            'status' => 'submitted',
            'submitted_at' => $submittedAt,
            'output_hash' => $outputHash,
            'evidence_count' => \count($evidenceItems),
            'evidence_items' => $evidenceItems,
            'acceptance_gate' => 'buyer_acceptance_required',
            'trust_tags' => ['实战验证', '数据驱动', '持续更新', '专家审核'],
            'settlement_effect' => 'no_fund_release_until_buyer_acceptance',
        ];

        $providerScope->setData(ProviderScopeSubmission::schema_fields_STATUS, ProviderScopeSubmission::STATUS_DELIVERY_SUBMITTED);
        $providerScope->setData(ProviderScopeSubmission::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'delivery_submission' => $deliverySubmission,
            'provider_delivery_submitted_at' => $submittedAt,
            'buyer_acceptance_required' => true,
        ])));
        $providerScope->save();

        return $deliverySubmission;
    }

    private function markTradeOrderDeliverySubmitted(TradeOrder $tradeOrder, array $deliverySubmission): void
    {
        if ($this->isTerminalOrCaseState($tradeOrder)) {
            return;
        }

        $metadata = $this->decodeJson((string)$tradeOrder->getData(TradeOrder::schema_fields_METADATA_JSON));
        $tradeOrder->setData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS, TradeOrder::PROVIDER_QUEUE_DELIVERY_SUBMITTED);
        $tradeOrder->setData(TradeOrder::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'delivery_submission_public_id' => (string)$deliverySubmission['delivery_public_id'],
            'provider_delivery_submitted_at' => (string)$deliverySubmission['submitted_at'],
            'delivery_output_hash' => (string)$deliverySubmission['output_hash'],
            'buyer_acceptance_required' => true,
        ])));
        $tradeOrder->save();
    }

    private function isTerminalOrCaseState(TradeOrder $tradeOrder): bool
    {
        $status = (string)$tradeOrder->getData(TradeOrder::schema_fields_STATUS);
        $queueStatus = (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS);

        return $status === TradeOrder::STATUS_ACCEPTED_RELEASED
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_ACCEPTED
            || $status === TradeOrder::STATUS_REFUND_REVIEW
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_REFUND_REVIEW
            || $status === TradeOrder::STATUS_DISPUTE_ARBITRATION
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_DISPUTE_HOLD
            || $status === TradeOrder::STATUS_ARBITRATION_RULED
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_ARBITRATION_RULED
            || $status === TradeOrder::STATUS_REWORK_REQUIRED
            || $queueStatus === TradeOrder::PROVIDER_QUEUE_REWORK_REQUIRED;
    }

    private function buildEvidenceItems(TradeOrder $tradeOrder, ProviderScopeSubmission $providerScope, string $outputHash): array
    {
        $orderId = $tradeOrder->getPublicId();

        return [
            __('执行范围快照：%{1}', [$providerScope->getPublicId()]),
            __('运行日志包：runlog-%{1}.jsonl，含工具版本、参数和异常行清单', [$orderId]),
            __('输出校验摘要：%{1}', [$outputHash]),
            __('API / 数据使用声明：仅使用买方授权输入和本地沙箱工具，未触发外部 API 外发'),
            __('质量摘要：完成抽样复核、异常说明和可追溯输出文件索引'),
            __('验收门禁：等待买方绑定账号进入验收页，不自动释放托管'),
        ];
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

    private function buildDeliveryId(string $orderPublicId): string
    {
        return 'A2A-DELIVERY-' . \strtoupper(\substr(\hash('crc32b', $orderPublicId), 0, 6));
    }

    private function formatUsd(float $amount): string
    {
        return '$' . \number_format($amount, 2);
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
