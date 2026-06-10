<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

use Aiweline\A2A\Model\AgentReputation;
use Aiweline\A2A\Model\ArbitrationRuling;
use Aiweline\A2A\Model\DeliveryAcceptance;
use Aiweline\A2A\Model\SettlementCase;
use Aiweline\A2A\Model\TradeOrder;
use Aiweline\A2A\Model\WalletInstruction;
use Weline\Framework\Database\Model;

class AgentReputationService
{
    private const DEFAULT_PROVIDER_KEY = 'dataclean-pro-agent';

    public function __construct(
        private readonly AgentReputation $agentReputationModel,
        private readonly TradeOrder $tradeOrderModel,
        private readonly DeliveryAcceptance $deliveryAcceptanceModel,
        private readonly SettlementCase $settlementCaseModel,
        private readonly ArbitrationRuling $arbitrationRulingModel,
        private readonly WalletInstruction $walletInstructionModel
    ) {
    }

    public function calculate(string $providerKey): array
    {
        $providerKey = $this->normalizeProviderKey($providerKey ?: self::DEFAULT_PROVIDER_KEY);
        $provider = $this->resolveProviderName($providerKey);
        if ($provider === '') {
            throw new \InvalidArgumentException((string) __('Agent 信誉档案不存在或尚未形成交易结果。'));
        }

        $orders = $this->loadOrders($provider);
        if ($orders === []) {
            throw new \InvalidArgumentException((string) __('Agent 信誉档案不存在或尚未形成交易结果。'));
        }

        $acceptances = $this->loadAcceptances($provider);
        $cases = $this->loadSettlementCases($orders);
        $rulings = $this->loadArbitrationRulings($orders);
        $walletInstructions = $this->loadWalletInstructions($orders);
        $refundCases = $this->countCases($cases, SettlementCase::TYPE_REFUND);
        $disputeCases = $this->countCases($cases, SettlementCase::TYPE_DISPUTE);
        $rulingCounts = $this->countRulingsByType($rulings);
        $walletCounts = $this->countWalletInstructionsByStatus($walletInstructions);
        $totalOrders = \count($orders);
        $acceptedOrders = \count($acceptances);
        $finalRulings = \count($rulings);
        $walletConfirmed = $walletCounts[WalletInstruction::STATUS_ADAPTER_CONFIRMED] ?? 0;
        $walletFailed = $walletCounts[WalletInstruction::STATUS_ADAPTER_FAILED] ?? 0;
        $walletExecutable = \max(0, \count($walletInstructions) - ($walletCounts[WalletInstruction::STATUS_BLOCKED_HOLD] ?? 0));
        $acceptanceRate = $totalOrders > 0 ? $acceptedOrders / $totalOrders : 0.0;
        $disputeRate = $totalOrders > 0 ? $disputeCases / $totalOrders : 0.0;
        $walletConfirmationRate = $walletExecutable > 0 ? $walletConfirmed / $walletExecutable : 0.0;
        $score = $this->calculateScore($totalOrders, $acceptedOrders, $refundCases, $disputeCases, $rulingCounts, $walletCounts);
        $tier = $this->formatTier($score);
        $trustSignals = $this->buildTrustSignals($acceptedOrders, $acceptanceRate, $rulingCounts, $walletConfirmed);
        $riskSignals = $this->buildRiskSignals($refundCases, $disputeCases, $disputeRate, $rulingCounts, $walletFailed);
        $evidenceRows = $this->buildEvidenceRows($orders, $acceptances, $cases, $rulings, $walletInstructions);
        $sourceSnapshot = [
            'order_public_ids' => \array_values(\array_map(
                static fn(array $row): string => (string)($row[TradeOrder::schema_fields_PUBLIC_ID] ?? ''),
                $orders
            )),
            'ruling_public_ids' => \array_values(\array_map(
                static fn(array $row): string => (string)($row[ArbitrationRuling::schema_fields_PUBLIC_ID] ?? ''),
                $rulings
            )),
            'wallet_instruction_public_ids' => \array_values(\array_map(
                static fn(array $row): string => (string)($row[WalletInstruction::schema_fields_PUBLIC_ID] ?? ''),
                $walletInstructions
            )),
            'acceptance_count' => $acceptedOrders,
            'refund_cases' => $refundCases,
            'dispute_cases' => $disputeCases,
            'final_ruling_counts' => $rulingCounts,
            'wallet_instruction_counts' => $walletCounts,
            'score_formula' => '96 + accepted evidence bonus - friction case penalty + final ruling outcome adjustment + wallet execution adjustment',
        ];

        $snapshot = $this->syncReputation(
            $providerKey,
            $provider,
            $tier,
            $score,
            $totalOrders,
            $acceptedOrders,
            $refundCases,
            $disputeCases,
            $acceptanceRate,
            $disputeRate,
            $trustSignals,
            $riskSignals,
            $sourceSnapshot
        );

        return [
            'page_title' => __('A2A Agent 信誉重算'),
            'provider_key' => $providerKey,
            'provider' => $provider,
            'score' => \number_format($score, 1),
            'tier' => $tier['label'],
            'tier_state' => $tier['state'],
            'status' => $this->formatStatus($tier['state'], $refundCases, $disputeCases, $finalRulings, $walletFailed),
            'metrics' => [
                ['label' => __('订单样本'), 'value' => (string)$totalOrders, 'caption' => __('正式托管订单')],
                ['label' => __('证据验收率'), 'value' => $this->formatPercent($acceptanceRate), 'caption' => __('已形成验收快照')],
                ['label' => __('退款复核'), 'value' => (string)$refundCases, 'caption' => __('进入退款分支')],
                ['label' => __('争议率'), 'value' => $this->formatPercent($disputeRate), 'caption' => __('进入仲裁冻结')],
                ['label' => __('最终裁决'), 'value' => (string)$finalRulings, 'caption' => __('按裁决结果重算')],
                ['label' => __('钱包确认率'), 'value' => $this->formatPercent($walletConfirmationRate), 'caption' => __('dry-run 对账确认')],
            ],
            'trust_signals' => $trustSignals,
            'risk_signals' => $riskSignals,
            'latest_dispute_order_id' => $this->findLatestDisputeOrderId($cases),
            'latest_wallet_order_id' => $this->findLatestWalletOrderId($walletInstructions),
            'evidence_rows' => $evidenceRows,
            'score_rules' => $this->buildScoreRules($acceptedOrders, $refundCases, $disputeCases, $rulingCounts, $walletCounts),
            'next_actions' => $this->buildNextActions($tier['state'], $refundCases, $disputeCases, $finalRulings, $walletFailed),
            'persisted' => [
                'status' => __('已写入 Agent 信誉快照'),
                'agent_reputation_id' => $snapshot->getId(),
                'calculated_at' => (string)$snapshot->getData(AgentReputation::schema_fields_CALCULATED_AT),
                'source_rows' => (string)(\count($orders) + \count($acceptances) + \count($cases) + \count($rulings) + \count($walletInstructions)),
            ],
        ];
    }

    private function syncReputation(
        string $providerKey,
        string $provider,
        array $tier,
        float $score,
        int $totalOrders,
        int $acceptedOrders,
        int $refundCases,
        int $disputeCases,
        float $acceptanceRate,
        float $disputeRate,
        array $trustSignals,
        array $riskSignals,
        array $sourceSnapshot
    ): AgentReputation {
        $model = $this->freshModel($this->agentReputationModel);
        $model->where(AgentReputation::schema_fields_PROVIDER_KEY, $providerKey)->find()->fetch();
        $now = \date('Y-m-d H:i:s');

        $model->setData(AgentReputation::schema_fields_PROVIDER_KEY, $providerKey);
        $model->setData(AgentReputation::schema_fields_PROVIDER, $provider);
        $model->setData(AgentReputation::schema_fields_TIER, (string)$tier['label']);
        $model->setData(AgentReputation::schema_fields_TIER_STATE, (string)$tier['state']);
        $model->setData(AgentReputation::schema_fields_SCORE, \number_format($score, 2, '.', ''));
        $model->setData(AgentReputation::schema_fields_TOTAL_ORDERS, $totalOrders);
        $model->setData(AgentReputation::schema_fields_ACCEPTED_ORDERS, $acceptedOrders);
        $model->setData(AgentReputation::schema_fields_REFUND_CASES, $refundCases);
        $model->setData(AgentReputation::schema_fields_DISPUTE_CASES, $disputeCases);
        $model->setData(AgentReputation::schema_fields_ACCEPTANCE_RATE, \number_format($acceptanceRate, 4, '.', ''));
        $model->setData(AgentReputation::schema_fields_DISPUTE_RATE, \number_format($disputeRate, 4, '.', ''));
        $model->setData(AgentReputation::schema_fields_TRUST_SIGNALS_JSON, $this->encodeJson($trustSignals));
        $model->setData(AgentReputation::schema_fields_RISK_SIGNALS_JSON, $this->encodeJson($riskSignals));
        $model->setData(AgentReputation::schema_fields_SOURCE_SNAPSHOT_JSON, $this->encodeJson($sourceSnapshot));
        $model->setData(AgentReputation::schema_fields_CALCULATED_AT, $now);
        $model->save();

        return $model;
    }

    private function loadOrders(string $provider): array
    {
        $rows = $this->freshModel($this->tradeOrderModel)
            ->where(TradeOrder::schema_fields_PROVIDER, $provider)
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values($rows) : [];
    }

    private function loadAcceptances(string $provider): array
    {
        $rows = $this->freshModel($this->deliveryAcceptanceModel)
            ->where(DeliveryAcceptance::schema_fields_PROVIDER, $provider)
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values($rows) : [];
    }

    private function loadSettlementCases(array $orders): array
    {
        $cases = [];
        foreach ($orders as $order) {
            $orderPublicId = (string)($order[TradeOrder::schema_fields_PUBLIC_ID] ?? '');
            if ($orderPublicId === '') {
                continue;
            }

            $rows = $this->freshModel($this->settlementCaseModel)
                ->where(SettlementCase::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
                ->select()
                ->fetchArray();
            if (\is_array($rows)) {
                foreach ($rows as $row) {
                    $cases[] = $row;
                }
            }
        }

        return $cases;
    }

    private function loadArbitrationRulings(array $orders): array
    {
        $rulings = [];
        foreach ($orders as $order) {
            $orderPublicId = (string)($order[TradeOrder::schema_fields_PUBLIC_ID] ?? '');
            if ($orderPublicId === '') {
                continue;
            }

            $rows = $this->freshModel($this->arbitrationRulingModel)
                ->where(ArbitrationRuling::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
                ->select()
                ->fetchArray();
            if (\is_array($rows)) {
                foreach ($rows as $row) {
                    $rulings[] = $row;
                }
            }
        }

        return $rulings;
    }

    private function loadWalletInstructions(array $orders): array
    {
        $instructions = [];
        foreach ($orders as $order) {
            $orderPublicId = (string)($order[TradeOrder::schema_fields_PUBLIC_ID] ?? '');
            if ($orderPublicId === '') {
                continue;
            }

            $rows = $this->freshModel($this->walletInstructionModel)
                ->where(WalletInstruction::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
                ->select()
                ->fetchArray();
            if (\is_array($rows)) {
                foreach ($rows as $row) {
                    $instructions[] = $row;
                }
            }
        }

        return $instructions;
    }

    private function countCases(array $cases, string $caseType): int
    {
        return \count(\array_filter(
            $cases,
            static fn(array $row): bool => (string)($row[SettlementCase::schema_fields_CASE_TYPE] ?? '') === $caseType
        ));
    }

    private function countRulingsByType(array $rulings): array
    {
        $counts = [
            ArbitrationRuling::TYPE_FULL_RELEASE => 0,
            ArbitrationRuling::TYPE_PARTIAL_RELEASE => 0,
            ArbitrationRuling::TYPE_REFUND => 0,
            ArbitrationRuling::TYPE_REWORK => 0,
        ];
        foreach ($rulings as $ruling) {
            $type = (string)($ruling[ArbitrationRuling::schema_fields_RULING_TYPE] ?? '');
            if (\array_key_exists($type, $counts)) {
                $counts[$type]++;
            }
        }

        return $counts;
    }

    private function countWalletInstructionsByStatus(array $instructions): array
    {
        $counts = [
            WalletInstruction::STATUS_DRY_RUN_QUEUED => 0,
            WalletInstruction::STATUS_BLOCKED_HOLD => 0,
            WalletInstruction::STATUS_ADAPTER_PENDING => 0,
            WalletInstruction::STATUS_ADAPTER_CONFIRMED => 0,
            WalletInstruction::STATUS_ADAPTER_FAILED => 0,
        ];
        foreach ($instructions as $instruction) {
            $status = (string)($instruction[WalletInstruction::schema_fields_ADAPTER_STATUS] ?? '');
            if (\array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    private function calculateScore(
        int $totalOrders,
        int $acceptedOrders,
        int $refundCases,
        int $disputeCases,
        array $rulingCounts,
        array $walletCounts
    ): float
    {
        if ($totalOrders <= 0) {
            return 0.0;
        }

        $score = 96.0;
        $score += \min(8.0, $acceptedOrders * 4.0);
        $score -= $refundCases * 4.0;
        $score -= $disputeCases * 6.0;
        $score += ($rulingCounts[ArbitrationRuling::TYPE_FULL_RELEASE] ?? 0) * 4.0;
        $score -= ($rulingCounts[ArbitrationRuling::TYPE_PARTIAL_RELEASE] ?? 0) * 10.0;
        $score -= ($rulingCounts[ArbitrationRuling::TYPE_REFUND] ?? 0) * 22.0;
        $score -= ($rulingCounts[ArbitrationRuling::TYPE_REWORK] ?? 0) * 14.0;
        $score += \min(3.0, (float)($walletCounts[WalletInstruction::STATUS_ADAPTER_CONFIRMED] ?? 0));
        $score -= ($walletCounts[WalletInstruction::STATUS_ADAPTER_FAILED] ?? 0) * 12.0;

        return \max(40.0, \min(100.0, $score));
    }

    private function formatTier(float $score): array
    {
        if ($score >= 95.0) {
            return ['label' => __('黑金认证'), 'state' => 'black'];
        }
        if ($score >= 88.0) {
            return ['label' => __('铂金认证'), 'state' => 'platinum'];
        }
        if ($score >= 75.0) {
            return ['label' => __('金级观察'), 'state' => 'gold'];
        }
        if ($score >= 60.0) {
            return ['label' => __('银级观察'), 'state' => 'silver'];
        }

        return ['label' => __('风控观察'), 'state' => 'risk'];
    }

    private function formatStatus(string $tierState, int $refundCases, int $disputeCases, int $finalRulings, int $walletFailed): string
    {
        if ($walletFailed > 0) {
            return (string) __('存在钱包执行失败，信誉等级等待人工复核');
        }
        if ($finalRulings > 0) {
            return (string) __('最终裁决已纳入信誉重算');
        }
        if ($disputeCases > 0) {
            return (string) __('存在争议冻结，信誉等级进入观察期');
        }
        if ($refundCases > 0) {
            return (string) __('存在退款复核，信誉等级暂缓上调');
        }
        if ($tierState === 'black' || $tierState === 'platinum') {
            return (string) __('订单证据稳定，维持高信誉曝光');
        }

        return (string) __('需要更多已验收订单提升等级');
    }

    private function buildTrustSignals(int $acceptedOrders, float $acceptanceRate, array $rulingCounts, int $walletConfirmed): array
    {
        $signals = [
            __('执行日志与验收快照已绑定'),
            __('交付证据可追溯到订单范围'),
        ];

        if ($acceptedOrders > 0 && $acceptanceRate >= 1.0) {
            $signals[] = __('所有订单均形成验收证据');
        }
        if (($rulingCounts[ArbitrationRuling::TYPE_FULL_RELEASE] ?? 0) > 0) {
            $signals[] = __('最终裁决确认 Agent 可全额放款');
        }
        if ($walletConfirmed > 0) {
            $signals[] = __('钱包 dry-run 对账已确认');
        }

        return $signals;
    }

    private function buildRiskSignals(int $refundCases, int $disputeCases, float $disputeRate, array $rulingCounts, int $walletFailed): array
    {
        $signals = [];
        if ($refundCases > 0) {
            $signals[] = __('存在退款复核记录，需补强交付证明');
        }
        if ($disputeCases > 0) {
            $signals[] = __('存在争议仲裁记录，平台降低自动放款信任');
        }
        if ($disputeRate >= 0.5) {
            $signals[] = __('争议率高于观察阈值，进入风控观察');
        }
        if (($rulingCounts[ArbitrationRuling::TYPE_PARTIAL_RELEASE] ?? 0) > 0) {
            $signals[] = __('最终裁决为部分放款，自动成交曝光下调');
        }
        if (($rulingCounts[ArbitrationRuling::TYPE_REFUND] ?? 0) > 0) {
            $signals[] = __('最终裁决为买方退款，能力 SKU 需进入复核');
        }
        if (($rulingCounts[ArbitrationRuling::TYPE_REWORK] ?? 0) > 0) {
            $signals[] = __('最终裁决要求返工，交付版本需重新验收');
        }
        if ($walletFailed > 0) {
            $signals[] = __('钱包适配器执行失败，需人工复核幂等键和对账结果');
        }

        return $signals ?: [__('当前无退款或争议扣分')];
    }

    private function buildEvidenceRows(array $orders, array $acceptances, array $cases, array $rulings, array $walletInstructions): array
    {
        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                'source' => __('正式订单'),
                'id' => (string)($order[TradeOrder::schema_fields_PUBLIC_ID] ?? ''),
                'status' => $this->formatOrderStatus((string)($order[TradeOrder::schema_fields_STATUS] ?? '')),
                'impact' => __('计入订单样本'),
            ];
        }
        foreach ($acceptances as $acceptance) {
            $rows[] = [
                'source' => __('验收证据'),
                'id' => (string)($acceptance[DeliveryAcceptance::schema_fields_PUBLIC_ID] ?? ''),
                'status' => __('已验收'),
                'impact' => __('增加证据可信度'),
            ];
        }
        foreach ($cases as $case) {
            $caseType = (string)($case[SettlementCase::schema_fields_CASE_TYPE] ?? '');
            $rows[] = [
                'source' => $caseType === SettlementCase::TYPE_REFUND ? __('退款复核') : __('争议仲裁'),
                'id' => (string)($case[SettlementCase::schema_fields_PUBLIC_ID] ?? ''),
                'status' => $caseType === SettlementCase::TYPE_REFUND ? __('退款复核中') : __('争议仲裁冻结中'),
                'impact' => $caseType === SettlementCase::TYPE_REFUND ? __('小幅扣分') : __('重大扣分'),
            ];
        }
        foreach ($rulings as $ruling) {
            $rulingType = (string)($ruling[ArbitrationRuling::schema_fields_RULING_TYPE] ?? '');
            $rows[] = [
                'source' => __('最终裁决'),
                'id' => (string)($ruling[ArbitrationRuling::schema_fields_PUBLIC_ID] ?? ''),
                'status' => $this->formatRulingType($rulingType),
                'impact' => $this->formatRulingImpact($rulingType),
            ];
        }
        foreach ($walletInstructions as $instruction) {
            $adapterStatus = (string)($instruction[WalletInstruction::schema_fields_ADAPTER_STATUS] ?? '');
            $rows[] = [
                'source' => __('钱包执行'),
                'id' => (string)($instruction[WalletInstruction::schema_fields_PUBLIC_ID] ?? ''),
                'status' => $this->formatWalletStatus($adapterStatus),
                'impact' => $this->formatWalletImpact($adapterStatus),
            ];
        }

        return $rows;
    }

    private function buildScoreRules(int $acceptedOrders, int $refundCases, int $disputeCases, array $rulingCounts, array $walletCounts): array
    {
        return [
            __('基础分 96，代表已认证 Agent 的初始可信度。'),
            __('已验收证据每笔加 4 分，本次计入 %{1} 笔。', $acceptedOrders),
            __('退款复核按交易摩擦每笔扣 4 分，本次计入 %{1} 笔。', $refundCases),
            __('争议仲裁按交易摩擦每笔扣 6 分，本次计入 %{1} 笔。', $disputeCases),
            __('最终裁决按结果调整：全额放款 +4、部分放款 -10、全额退款 -22、返工 -14。'),
            __('钱包执行按对账调整：每条确认 +1（最多 +3），失败每条 -12。'),
            __('本次计入裁决 %{1} 条、钱包指令 %{2} 条。', \array_sum($rulingCounts), \array_sum($walletCounts)),
        ];
    }

    private function findLatestDisputeOrderId(array $cases): string
    {
        foreach (\array_reverse($cases) as $case) {
            if ((string)($case[SettlementCase::schema_fields_CASE_TYPE] ?? '') !== SettlementCase::TYPE_DISPUTE) {
                continue;
            }
            $orderId = (string)($case[SettlementCase::schema_fields_ORDER_PUBLIC_ID] ?? '');
            if ($orderId !== '') {
                return $orderId;
            }
        }

        return '';
    }

    private function findLatestWalletOrderId(array $walletInstructions): string
    {
        foreach (\array_reverse($walletInstructions) as $instruction) {
            $orderId = (string)($instruction[WalletInstruction::schema_fields_ORDER_PUBLIC_ID] ?? '');
            if ($orderId !== '') {
                return $orderId;
            }
        }

        return '';
    }

    private function buildNextActions(string $tierState, int $refundCases, int $disputeCases, int $finalRulings, int $walletFailed): array
    {
        $actions = [
            __('继续累积已验收订单，避免只靠静态认证维持曝光。'),
            __('把执行日志、哈希和质量摘要作为信誉重算的硬证据。'),
        ];
        if ($refundCases > 0) {
            $actions[] = __('补齐退款复核证据，确认退款比例和平台服务费处理。');
        }
        if ($disputeCases > 0) {
            $actions[] = __('完成争议仲裁裁决后重算等级，决定是否恢复黑金曝光。');
        }
        if ($finalRulings > 0) {
            $actions[] = __('复核最终裁决结果是否已同步到能力 SKU 曝光和排序权重。');
        }
        if ($walletFailed > 0) {
            $actions[] = __('先处理钱包适配器失败，再允许恢复自动成交或高价值能力曝光。');
        }
        if ($tierState === 'risk') {
            $actions[] = __('进入人工风控复核，暂停高风险 API 能力自动成交。');
        }

        return $actions;
    }

    private function resolveProviderName(string $providerKey): string
    {
        return match ($providerKey) {
            'dataclean-pro-agent' => 'DataClean Pro Agent',
            'research-scout-agent' => 'Research Scout Agent',
            'ops-workflow-agent' => 'Ops Workflow Agent',
            'expert-review-desk' => 'A2A Expert Review Desk',
            default => '',
        };
    }

    private function normalizeProviderKey(string $providerKey): string
    {
        $normalized = \strtolower(\trim($providerKey));
        $normalized = \preg_replace('/[^a-z0-9_-]+/', '-', $normalized) ?: '';
        $normalized = \trim($normalized, '-_');

        return $normalized !== '' ? $normalized : self::DEFAULT_PROVIDER_KEY;
    }

    private function formatRulingType(string $type): string
    {
        return match ($type) {
            ArbitrationRuling::TYPE_FULL_RELEASE => (string) __('全额放款'),
            ArbitrationRuling::TYPE_PARTIAL_RELEASE => (string) __('部分放款'),
            ArbitrationRuling::TYPE_REFUND => (string) __('全额退款'),
            ArbitrationRuling::TYPE_REWORK => (string) __('返工补交'),
            default => $type,
        };
    }

    private function formatRulingImpact(string $type): string
    {
        return match ($type) {
            ArbitrationRuling::TYPE_FULL_RELEASE => (string) __('恢复放款可信度'),
            ArbitrationRuling::TYPE_PARTIAL_RELEASE => (string) __('部分扣分'),
            ArbitrationRuling::TYPE_REFUND => (string) __('重大扣分'),
            ArbitrationRuling::TYPE_REWORK => (string) __('返工观察'),
            default => __('等待裁决影响'),
        };
    }

    private function formatWalletStatus(string $status): string
    {
        return match ($status) {
            WalletInstruction::STATUS_DRY_RUN_QUEUED => (string) __('dry-run 已排队'),
            WalletInstruction::STATUS_BLOCKED_HOLD => (string) __('冻结保持'),
            WalletInstruction::STATUS_ADAPTER_PENDING => (string) __('待提交适配器'),
            WalletInstruction::STATUS_ADAPTER_CONFIRMED => (string) __('dry-run 已确认'),
            WalletInstruction::STATUS_ADAPTER_FAILED => (string) __('执行失败'),
            default => $status,
        };
    }

    private function formatWalletImpact(string $status): string
    {
        return match ($status) {
            WalletInstruction::STATUS_ADAPTER_CONFIRMED => (string) __('对账确认'),
            WalletInstruction::STATUS_ADAPTER_FAILED => (string) __('失败扣分'),
            WalletInstruction::STATUS_BLOCKED_HOLD => (string) __('继续冻结'),
            WalletInstruction::STATUS_ADAPTER_PENDING,
            WalletInstruction::STATUS_DRY_RUN_QUEUED => (string) __('等待对账'),
            default => __('等待钱包结果'),
        };
    }

    private function formatOrderStatus(string $status): string
    {
        return match ($status) {
            TradeOrder::STATUS_ACCEPTED_RELEASED => (string) __('已验收放款'),
            TradeOrder::STATUS_REFUND_REVIEW => (string) __('退款复核中'),
            TradeOrder::STATUS_DISPUTE_ARBITRATION => (string) __('争议仲裁冻结中'),
            TradeOrder::STATUS_ARBITRATION_RULED => (string) __('仲裁已裁决，钱包指令待执行'),
            TradeOrder::STATUS_REWORK_REQUIRED => (string) __('仲裁裁决返工'),
            TradeOrder::STATUS_EXECUTION_READY => (string) __('范围已提交，等待受控执行'),
            TradeOrder::STATUS_ESCROW_LOCKED => (string) __('托管已锁定'),
            default => $status,
        };
    }

    private function formatPercent(float $value): string
    {
        return \number_format($value * 100, 1) . '%';
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
}
