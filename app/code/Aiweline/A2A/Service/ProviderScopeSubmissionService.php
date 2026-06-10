<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

use Aiweline\A2A\Model\ProviderScopeSubmission;
use Aiweline\A2A\Model\TradeOrder;
use Weline\Framework\Database\Model;

class ProviderScopeSubmissionService
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

        $scopeItems = $this->buildExecutionScope($tradeOrder);
        $permissionItems = $this->buildToolPermissions($tradeOrder);
        $evidenceItems = $this->buildEvidenceChecklist($tradeOrder);
        $scope = $this->syncProviderScope($tradeOrder, $scopeItems, $permissionItems, $evidenceItems);
        $this->markTradeOrderExecutionReady($tradeOrder, $scope);

        return [
            'page_title' => __('A2A Provider 执行范围'),
            'order_id' => $tradeOrder->getPublicId(),
            'draft_id' => (string)$tradeOrder->getData(TradeOrder::schema_fields_DRAFT_PUBLIC_ID),
            'scope_id' => $scope->getPublicId(),
            'status' => __('范围已提交，等待受控执行'),
            'delivery_submission_url' => '/a2a/frontend/delivery-submission?order=' . \rawurlencode($tradeOrder->getPublicId()),
            'acceptance_url' => '/a2a/frontend/delivery-submission?order=' . \rawurlencode($tradeOrder->getPublicId()),
            'buyer_acceptance_url' => '/a2a/frontend/acceptance?order=' . \rawurlencode($tradeOrder->getPublicId()),
            'order' => [
                'sku_title' => (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_TITLE),
                'provider' => (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER),
                'amount' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_AMOUNT)),
                'platform_fee' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PLATFORM_FEE)),
                'provider_payout' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT)),
                'trade_order_id' => $tradeOrder->getId(),
            ],
            'scope_items' => $scopeItems,
            'permission_items' => $permissionItems,
            'evidence_items' => $evidenceItems,
            'risk_gate' => [
                'status' => __('有限范围已通过'),
                'rules' => [
                    __('仅允许读取买方授权输入和生成约定交付物。'),
                    __('外部 API、数据外发或高权限工具调用必须重新进入平台风控。'),
                    __('未绑定执行日志、输入哈希和输出校验摘要时不得进入验收放款。'),
                ],
            ],
            'persisted' => [
                'status' => __('已写入 Provider 范围并推进订单到受控执行准备'),
                'provider_scope_id' => $scope->getId(),
                'trade_order_id' => $tradeOrder->getId(),
            ],
        ];
    }

    private function syncProviderScope(
        TradeOrder $tradeOrder,
        array $scopeItems,
        array $permissionItems,
        array $evidenceItems
    ): ProviderScopeSubmission {
        $scopePublicId = $this->buildScopeId($tradeOrder->getPublicId());
        $model = $this->freshModel($this->providerScopeModel);
        $model->where(ProviderScopeSubmission::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())->find()->fetch();

        $model->setData(ProviderScopeSubmission::schema_fields_PUBLIC_ID, $scopePublicId);
        $model->setData(ProviderScopeSubmission::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId());
        $model->setData(ProviderScopeSubmission::schema_fields_PROVIDER, (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER));
        $model->setData(ProviderScopeSubmission::schema_fields_EXECUTION_SCOPE_JSON, $this->encodeJson($scopeItems));
        $model->setData(ProviderScopeSubmission::schema_fields_TOOL_PERMISSIONS_JSON, $this->encodeJson($permissionItems));
        $model->setData(ProviderScopeSubmission::schema_fields_EVIDENCE_CHECKLIST_JSON, $this->encodeJson($evidenceItems));
        $model->setData(ProviderScopeSubmission::schema_fields_STATUS, ProviderScopeSubmission::STATUS_SCOPE_SUBMITTED);
        $model->setData(ProviderScopeSubmission::schema_fields_RISK_GATE_STATUS, ProviderScopeSubmission::RISK_GATE_LIMITED_SCOPE);
        $model->setData(ProviderScopeSubmission::schema_fields_SUBMITTED_AT, \date('Y-m-d H:i:s'));
        $model->setData(ProviderScopeSubmission::schema_fields_METADATA_JSON, $this->encodeJson([
            'trade_order_status_after_submit' => TradeOrder::STATUS_EXECUTION_READY,
            'provider_queue_status_after_submit' => TradeOrder::PROVIDER_QUEUE_SCOPE_SUBMITTED,
            'prototype_submission' => true,
        ]));
        $model->save();

        return $model;
    }

    private function markTradeOrderExecutionReady(TradeOrder $tradeOrder, ProviderScopeSubmission $scope): void
    {
        $currentStatus = (string)$tradeOrder->getData(TradeOrder::schema_fields_STATUS);
        $currentQueueStatus = (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS);
        if ($currentStatus === TradeOrder::STATUS_ACCEPTED_RELEASED
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_ACCEPTED
            || $currentStatus === TradeOrder::STATUS_REFUND_REVIEW
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_REFUND_REVIEW
            || $currentStatus === TradeOrder::STATUS_DISPUTE_ARBITRATION
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_DISPUTE_HOLD
            || $currentStatus === TradeOrder::STATUS_ARBITRATION_RULED
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_ARBITRATION_RULED
            || $currentStatus === TradeOrder::STATUS_REWORK_REQUIRED
            || $currentQueueStatus === TradeOrder::PROVIDER_QUEUE_REWORK_REQUIRED
        ) {
            return;
        }

        $metadata = $this->decodeJson((string)$tradeOrder->getData(TradeOrder::schema_fields_METADATA_JSON));
        $tradeOrder->setData(TradeOrder::schema_fields_STATUS, TradeOrder::STATUS_EXECUTION_READY);
        $tradeOrder->setData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS, TradeOrder::PROVIDER_QUEUE_SCOPE_SUBMITTED);
        $tradeOrder->setData(TradeOrder::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'provider_scope_public_id' => $scope->getPublicId(),
            'provider_scope_submitted_at' => \date('Y-m-d H:i:s'),
            'execution_ready' => true,
        ])));
        $tradeOrder->save();
    }

    private function buildExecutionScope(TradeOrder $tradeOrder): array
    {
        $title = (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_TITLE);

        return [
            __('处理订单约定输入：%{1}', $title),
            __('输出验收所需的结果文件、质量摘要和异常清单。'),
            __('保留执行参数、运行日志和关键中间结果哈希。'),
        ];
    }

    private function buildToolPermissions(TradeOrder $tradeOrder): array
    {
        return [
            __('只读买方授权输入文件。'),
            __('允许使用本地沙箱数据处理工具。'),
            __('允许写入交付物和审计日志到订单证据包。'),
            __('禁止未审批外部 API 调用、数据外发和生产系统写入。'),
        ];
    }

    private function buildEvidenceChecklist(TradeOrder $tradeOrder): array
    {
        return [
            __('输入文件哈希与版本号。'),
            __('执行参数和工具版本。'),
            __('完整执行日志。'),
            __('输出文件校验摘要。'),
            __('异常行、失败项或未处理范围说明。'),
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

    private function buildScopeId(string $orderPublicId): string
    {
        return 'A2A-SCOPE-' . \strtoupper(\substr(\hash('crc32b', $orderPublicId), 0, 6));
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
