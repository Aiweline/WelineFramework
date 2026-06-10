<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

use Aiweline\A2A\Model\ArbitrationRuling;
use Aiweline\A2A\Model\TradeOrder;
use Aiweline\A2A\Model\WalletInstruction;
use Weline\Framework\Database\Model;

class WalletInstructionAdapterService
{
    private const MODE_INSPECT = 'inspect';
    private const MODE_DRY_RUN_EXECUTE = 'dry_run_execute';
    private const MODE_SIMULATE_FAILURE = 'simulate_failure';
    private const MODE_RETRY_FAILED = 'retry_failed';

    public function __construct(
        private readonly TradeOrder $tradeOrderModel,
        private readonly ArbitrationRuling $arbitrationRulingModel,
        private readonly WalletInstruction $walletInstructionModel
    ) {
    }

    public function inspect(string $orderPublicId, string $mode): array
    {
        $orderPublicId = \strtoupper(\trim($orderPublicId));
        $mode = $this->normalizeMode($mode ?: self::MODE_DRY_RUN_EXECUTE);
        if ($orderPublicId === '') {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        $tradeOrder = $this->loadTradeOrder($orderPublicId);
        $ruling = $this->loadCurrentRuling($tradeOrder);
        $instructions = $this->loadInstructionModels($tradeOrder->getPublicId(), $ruling->getPublicId());
        $processed = $this->processInstructions($instructions, $mode);

        return [
            'page_title' => __('A2A 钱包适配器执行监控'),
            'order_id' => $tradeOrder->getPublicId(),
            'ruling_id' => $ruling->getPublicId(),
            'mode' => $mode,
            'mode_label' => $this->modeLabels()[$mode],
            'mode_options' => $this->buildModeOptions($tradeOrder->getPublicId(), $mode),
            'order' => [
                'sku_title' => (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_TITLE),
                'provider' => (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER),
                'amount' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_AMOUNT)),
                'platform_fee' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PLATFORM_FEE)),
                'provider_payout' => $this->formatUsd((float)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT)),
            ],
            'ruling' => [
                'type' => (string)$ruling->getData(ArbitrationRuling::schema_fields_RULING_TYPE),
                'decision' => (string)$ruling->getData(ArbitrationRuling::schema_fields_DECISION),
                'status' => (string)$ruling->getData(ArbitrationRuling::schema_fields_STATUS),
                'ruled_at' => (string)$ruling->getData(ArbitrationRuling::schema_fields_RULED_AT),
            ],
            'metrics' => $processed['metrics'],
            'instructions' => $processed['rows'],
            'adapter_contract' => $this->buildAdapterContract(),
            'navigation' => [
                'arbitration_href' => '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($tradeOrder->getPublicId()) . '&ruling=' . \rawurlencode((string)$ruling->getData(ArbitrationRuling::schema_fields_RULING_TYPE)),
                'role_href' => '/a2a/frontend/role-console?switch_role=1&role=platform&order=' . \rawurlencode($tradeOrder->getPublicId()),
                'reputation_href' => '/a2a/frontend/reputation?agent=' . \rawurlencode($this->resolveProviderKey((string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER))),
            ],
            'persisted' => [
                'status' => __('已写入钱包适配器 dry-run 执行状态'),
                'mode' => $this->modeLabels()[$mode],
                'confirmed' => $processed['metrics']['confirmed']['value'],
                'retryable' => $processed['metrics']['retryable']['value'],
                'adapter_boundary' => __('不执行真实资金动作'),
            ],
        ];
    }

    /**
     * @param WalletInstruction[] $instructions
     */
    private function processInstructions(array $instructions, string $mode): array
    {
        $rows = [];
        $failureConsumed = false;
        foreach ($instructions as $instruction) {
            $status = (string)$instruction->getData(WalletInstruction::schema_fields_ADAPTER_STATUS);
            if ($mode === self::MODE_SIMULATE_FAILURE
                && !$failureConsumed
                && $status !== WalletInstruction::STATUS_BLOCKED_HOLD
            ) {
                $this->markFailed($instruction);
                $failureConsumed = true;
            } elseif ($mode === self::MODE_DRY_RUN_EXECUTE
                && \in_array($status, [WalletInstruction::STATUS_DRY_RUN_QUEUED, WalletInstruction::STATUS_ADAPTER_PENDING], true)
            ) {
                $this->markConfirmed($instruction, $mode, false);
            } elseif ($mode === self::MODE_RETRY_FAILED
                && $status === WalletInstruction::STATUS_ADAPTER_FAILED
            ) {
                $this->markConfirmed($instruction, $mode, true);
            }

            $rows[] = $this->formatInstructionRow($instruction);
        }

        return [
            'rows' => $rows,
            'metrics' => $this->buildMetrics($rows),
        ];
    }

    private function markConfirmed(WalletInstruction $instruction, string $mode, bool $isRetry): void
    {
        $now = \date('Y-m-d H:i:s');
        $idempotencyKey = $this->resolveIdempotencyKey($instruction);
        $metadata = $this->decodeJson((string)$instruction->getData(WalletInstruction::schema_fields_METADATA_JSON));
        $retryCount = (int)($instruction->getData(WalletInstruction::schema_fields_RETRY_COUNT) ?: 0);

        $instruction->setData(WalletInstruction::schema_fields_ADAPTER_STATUS, WalletInstruction::STATUS_ADAPTER_CONFIRMED);
        $instruction->setData(WalletInstruction::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey);
        $instruction->setData(WalletInstruction::schema_fields_EXTERNAL_REFERENCE, $this->buildExternalReference($idempotencyKey));
        $instruction->setData(WalletInstruction::schema_fields_FAILURE_REASON, '');
        $instruction->setData(WalletInstruction::schema_fields_RETRY_COUNT, $isRetry ? $retryCount + 1 : $retryCount);
        $instruction->setData(WalletInstruction::schema_fields_EXECUTED_AT, $now);
        $instruction->setData(WalletInstruction::schema_fields_RECONCILED_AT, $now);
        $instruction->setData(WalletInstruction::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'adapter_mode' => $mode,
            'reconciliation_result' => 'matched',
            'idempotency_policy' => 'same_instruction_same_key',
            'no_real_funds_moved' => true,
        ])));
        $instruction->save();
    }

    private function markFailed(WalletInstruction $instruction): void
    {
        $now = \date('Y-m-d H:i:s');
        $idempotencyKey = $this->resolveIdempotencyKey($instruction);
        $metadata = $this->decodeJson((string)$instruction->getData(WalletInstruction::schema_fields_METADATA_JSON));

        $instruction->setData(WalletInstruction::schema_fields_ADAPTER_STATUS, WalletInstruction::STATUS_ADAPTER_FAILED);
        $instruction->setData(WalletInstruction::schema_fields_IDEMPOTENCY_KEY, $idempotencyKey);
        $instruction->setData(WalletInstruction::schema_fields_EXTERNAL_REFERENCE, '');
        $instruction->setData(WalletInstruction::schema_fields_FAILURE_REASON, (string) __('模拟钱包适配器超时，等待人工复核或重试。'));
        $instruction->setData(WalletInstruction::schema_fields_EXECUTED_AT, $now);
        $instruction->setData(WalletInstruction::schema_fields_RECONCILED_AT, null);
        $instruction->setData(WalletInstruction::schema_fields_METADATA_JSON, $this->encodeJson(\array_merge($metadata, [
            'adapter_mode' => self::MODE_SIMULATE_FAILURE,
            'reconciliation_result' => 'not_matched',
            'idempotency_policy' => 'same_instruction_same_key',
            'no_real_funds_moved' => true,
        ])));
        $instruction->save();
    }

    private function formatInstructionRow(WalletInstruction $instruction): array
    {
        $status = (string)$instruction->getData(WalletInstruction::schema_fields_ADAPTER_STATUS);
        $metadata = $this->decodeJson((string)$instruction->getData(WalletInstruction::schema_fields_METADATA_JSON));

        return [
            'id' => (string)$instruction->getData(WalletInstruction::schema_fields_PUBLIC_ID),
            'entry_type' => (string)$instruction->getData(WalletInstruction::schema_fields_LEDGER_ENTRY_TYPE),
            'instruction_type' => $this->formatInstructionType((string)$instruction->getData(WalletInstruction::schema_fields_INSTRUCTION_TYPE)),
            'amount' => $this->formatUsd((float)$instruction->getData(WalletInstruction::schema_fields_AMOUNT)),
            'adapter_code' => (string)$instruction->getData(WalletInstruction::schema_fields_ADAPTER_CODE),
            'adapter_status' => $status,
            'adapter_status_label' => $this->formatAdapterStatus($status),
            'idempotency_key' => (string)$instruction->getData(WalletInstruction::schema_fields_IDEMPOTENCY_KEY),
            'external_reference' => (string)$instruction->getData(WalletInstruction::schema_fields_EXTERNAL_REFERENCE),
            'failure_reason' => (string)$instruction->getData(WalletInstruction::schema_fields_FAILURE_REASON),
            'retry_count' => (int)($instruction->getData(WalletInstruction::schema_fields_RETRY_COUNT) ?: 0),
            'executed_at' => (string)$instruction->getData(WalletInstruction::schema_fields_EXECUTED_AT),
            'reconciled_at' => (string)$instruction->getData(WalletInstruction::schema_fields_RECONCILED_AT),
            'reconciliation_result' => (string)($metadata['reconciliation_result'] ?? 'pending'),
            'reconciliation_label' => $this->formatReconciliationResult((string)($metadata['reconciliation_result'] ?? 'pending')),
            'note' => (string)($metadata['note'] ?? ''),
            'is_retryable' => $status === WalletInstruction::STATUS_ADAPTER_FAILED,
        ];
    }

    private function buildMetrics(array $rows): array
    {
        $total = \count($rows);
        $confirmed = 0;
        $blocked = 0;
        $failed = 0;
        $pending = 0;
        $retryable = 0;

        foreach ($rows as $row) {
            $status = (string)$row['adapter_status'];
            if ($status === WalletInstruction::STATUS_ADAPTER_CONFIRMED) {
                $confirmed++;
            } elseif ($status === WalletInstruction::STATUS_BLOCKED_HOLD) {
                $blocked++;
            } elseif ($status === WalletInstruction::STATUS_ADAPTER_FAILED) {
                $failed++;
                $retryable++;
            } else {
                $pending++;
            }
        }

        return [
            'total' => ['label' => __('钱包指令'), 'value' => $total],
            'confirmed' => ['label' => __('dry-run 已确认'), 'value' => $confirmed],
            'pending' => ['label' => __('待提交'), 'value' => $pending],
            'failed' => ['label' => __('执行失败'), 'value' => $failed],
            'blocked' => ['label' => __('冻结保持'), 'value' => $blocked],
            'retryable' => ['label' => __('可重试'), 'value' => $retryable],
        ];
    }

    private function buildAdapterContract(): array
    {
        return [
            [
                'label' => __('适配器'),
                'value' => 'prototype_wallet',
                'note' => __('只模拟真实钱包接口返回，不触发外部支付或链上动作。'),
            ],
            [
                'label' => __('幂等键'),
                'value' => 'A2A-IDEMP-*',
                'note' => __('同一钱包指令重复执行必须复用同一个幂等键，避免重复放款或退款。'),
            ],
            [
                'label' => __('对账规则'),
                'value' => __('对账匹配'),
                'note' => __('金额、币种、指令类型和外部引用一致时，dry-run 记为已确认。'),
            ],
            [
                'label' => __('失败治理'),
                'value' => __('失败可重试'),
                'note' => __('失败指令保留原因和重试次数，重试仍然沿用原幂等键。'),
            ],
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

    private function loadCurrentRuling(TradeOrder $tradeOrder): ArbitrationRuling
    {
        $metadata = $this->decodeJson((string)$tradeOrder->getData(TradeOrder::schema_fields_METADATA_JSON));
        $rulingPublicId = (string)($metadata['arbitration_ruling_public_id'] ?? '');
        if ($rulingPublicId === '') {
            $rows = $this->freshModel($this->arbitrationRulingModel)
                ->where(ArbitrationRuling::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())
                ->select()
                ->fetchArray();
            if (\is_array($rows) && $rows !== []) {
                $latest = \end($rows);
                $rulingPublicId = (string)($latest[ArbitrationRuling::schema_fields_PUBLIC_ID] ?? '');
            }
        }

        $ruling = $this->freshModel($this->arbitrationRulingModel);
        $ruling->where(ArbitrationRuling::schema_fields_PUBLIC_ID, $rulingPublicId)->find()->fetch();
        if (!$ruling->getId()) {
            throw new \InvalidArgumentException((string) __('订单尚未生成仲裁裁决，不能执行钱包监控。'));
        }

        return $ruling;
    }

    /**
     * @return WalletInstruction[]
     */
    private function loadInstructionModels(string $orderPublicId, string $rulingPublicId): array
    {
        $rows = $this->freshModel($this->walletInstructionModel)
            ->where(WalletInstruction::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
            ->where(WalletInstruction::schema_fields_RULING_PUBLIC_ID, $rulingPublicId)
            ->select()
            ->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            throw new \InvalidArgumentException((string) __('钱包指令不存在，请先生成仲裁裁决。'));
        }

        \usort($rows, function (array $left, array $right): int {
            return $this->instructionSortIndex((string)($left[WalletInstruction::schema_fields_LEDGER_ENTRY_TYPE] ?? ''))
                <=> $this->instructionSortIndex((string)($right[WalletInstruction::schema_fields_LEDGER_ENTRY_TYPE] ?? ''));
        });

        $instructions = [];
        foreach ($rows as $row) {
            $model = $this->freshModel($this->walletInstructionModel);
            $model->where(WalletInstruction::schema_fields_PUBLIC_ID, (string)$row[WalletInstruction::schema_fields_PUBLIC_ID])->find()->fetch();
            if ($model->getId()) {
                $instructions[] = $model;
            }
        }

        return $instructions;
    }

    private function instructionSortIndex(string $entryType): int
    {
        return match ($entryType) {
            'buyer_freeze' => 10,
            'platform_fee' => 20,
            'provider_payout' => 30,
            default => 99,
        };
    }

    private function buildModeOptions(string $orderPublicId, string $currentMode): array
    {
        $options = [];
        foreach ($this->modeLabels() as $mode => $label) {
            $options[] = [
                'mode' => $mode,
                'label' => $label,
                'active' => $mode === $currentMode,
                'href' => '/a2a/frontend/wallet-monitor?order=' . \rawurlencode($orderPublicId) . '&mode=' . \rawurlencode($mode),
            ];
        }

        return $options;
    }

    private function modeLabels(): array
    {
        return [
            self::MODE_INSPECT => __('只查看'),
            self::MODE_DRY_RUN_EXECUTE => __('执行 dry-run'),
            self::MODE_SIMULATE_FAILURE => __('模拟失败'),
            self::MODE_RETRY_FAILED => __('重试失败'),
        ];
    }

    private function normalizeMode(string $mode): string
    {
        $mode = \strtolower(\trim($mode));
        if (!\array_key_exists($mode, $this->modeLabels())) {
            throw new \InvalidArgumentException((string) __('钱包监控模式无效。'));
        }

        return $mode;
    }

    private function resolveIdempotencyKey(WalletInstruction $instruction): string
    {
        $current = (string)$instruction->getData(WalletInstruction::schema_fields_IDEMPOTENCY_KEY);
        if ($current !== '') {
            return $current;
        }

        $raw = \implode('|', [
            (string)$instruction->getData(WalletInstruction::schema_fields_PUBLIC_ID),
            (string)$instruction->getData(WalletInstruction::schema_fields_ORDER_PUBLIC_ID),
            (string)$instruction->getData(WalletInstruction::schema_fields_RULING_PUBLIC_ID),
            (string)$instruction->getData(WalletInstruction::schema_fields_INSTRUCTION_TYPE),
            (string)$instruction->getData(WalletInstruction::schema_fields_AMOUNT),
        ]);

        return 'A2A-IDEMP-' . \strtoupper(\substr(\hash('sha256', $raw), 0, 16));
    }

    private function buildExternalReference(string $idempotencyKey): string
    {
        return 'SIM-' . \strtoupper(\substr(\hash('crc32b', $idempotencyKey . '|prototype_wallet'), 0, 8));
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

    private function formatReconciliationResult(string $result): string
    {
        return match ($result) {
            'matched' => (string) __('对账匹配'),
            'not_matched' => (string) __('对账未匹配'),
            default => (string) __('等待对账'),
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

    private function resolveProviderKey(string $provider): string
    {
        return match ($provider) {
            'DataClean Pro Agent' => 'dataclean-pro-agent',
            'Research Scout Agent' => 'research-scout-agent',
            'Ops Workflow Agent' => 'ops-workflow-agent',
            default => \strtolower((string)\preg_replace('/[^a-z0-9]+/i', '-', \trim($provider))),
        };
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
