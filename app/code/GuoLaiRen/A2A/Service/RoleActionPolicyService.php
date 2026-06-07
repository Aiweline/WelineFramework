<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use GuoLaiRen\A2A\Model\DeliveryAcceptance;
use GuoLaiRen\A2A\Model\EscrowLedger;
use GuoLaiRen\A2A\Model\ProviderScopeSubmission;
use GuoLaiRen\A2A\Model\SettlementCase;
use GuoLaiRen\A2A\Model\TradeOrder;
use Weline\Framework\Database\Model;

class RoleActionPolicyService
{
    private const ROLE_BUYER = 'buyer';
    private const ROLE_PROVIDER = 'provider';
    private const ROLE_PLATFORM = 'platform';
    private const ROLE_ARBITRATOR = 'arbitrator';

    public function __construct(
        private readonly TradeOrder $tradeOrderModel,
        private readonly DeliveryAcceptance $deliveryAcceptanceModel,
        private readonly SettlementCase $settlementCaseModel,
        private readonly ProviderScopeSubmission $providerScopeSubmissionModel,
        private readonly EscrowLedger $escrowLedgerModel
    ) {
    }

    public function inspect(string $orderPublicId, string $role, string $requestedAction = ''): array
    {
        $orderPublicId = \strtoupper(\trim($orderPublicId));
        $role = \strtolower(\trim($role));
        $requestedAction = \strtolower(\trim($requestedAction));
        if ($orderPublicId === '') {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }
        if (!isset($this->roleLabels()[$role])) {
            throw new \InvalidArgumentException((string) __('角色不存在或尚未接入 A2A 权限策略。'));
        }

        $order = $this->freshModel($this->tradeOrderModel);
        $order->where(TradeOrder::schema_fields_PUBLIC_ID, $orderPublicId)->find()->fetch();
        if (!$order->getId()) {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        $acceptance = $this->loadAcceptance($orderPublicId);
        $cases = $this->loadSettlementCases($orderPublicId);
        $hasRefund = $this->hasCase($cases, SettlementCase::TYPE_REFUND);
        $hasDispute = $this->hasCase($cases, SettlementCase::TYPE_DISPUTE);
        $providerScope = $this->loadProviderScope($orderPublicId);
        $hasDeliverySubmission = $this->hasDeliverySubmission($providerScope);
        $actions = $this->buildActions($role, $order, (bool)$acceptance, $hasRefund, $hasDispute, $hasDeliverySubmission);
        $actionResult = $this->inspectRequestedAction($requestedAction, $actions);

        return [
            'page_title' => __('A2A 角色权限控制台'),
            'order_id' => $order->getPublicId(),
            'role' => $role,
            'role_label' => $this->roleLabels()[$role],
            'role_tabs' => $this->buildRoleTabs($order->getPublicId(), $role),
            'requested_action' => $requestedAction,
            'action_result' => $actionResult,
            'is_forbidden' => $actionResult['http_status'] === 403,
            'order' => [
                'sku_title' => (string)$order->getData(TradeOrder::schema_fields_SKU_TITLE),
                'provider' => (string)$order->getData(TradeOrder::schema_fields_PROVIDER),
                'status' => (string)$order->getData(TradeOrder::schema_fields_STATUS),
                'status_label' => $this->formatOrderStatus((string)$order->getData(TradeOrder::schema_fields_STATUS)),
                'provider_queue' => $this->formatProviderQueue((string)$order->getData(TradeOrder::schema_fields_PROVIDER_QUEUE_STATUS)),
                'amount' => $this->formatUsd((float)$order->getData(TradeOrder::schema_fields_AMOUNT)),
                'platform_fee' => $this->formatUsd((float)$order->getData(TradeOrder::schema_fields_PLATFORM_FEE)),
                'provider_payout' => $this->formatUsd((float)$order->getData(TradeOrder::schema_fields_PROVIDER_PAYOUT)),
            ],
            'guards' => $this->buildGuardRules($hasRefund, $hasDispute),
            'actions' => $actions,
            'case_summary' => $this->buildCaseSummary($cases),
            'ledger_rows' => $this->loadLedgerRows($order->getPublicId()),
            'persisted' => [
                'status' => __('已按会话角色计算动作边界'),
                'trade_order_id' => $order->getId(),
                'role_code' => $role,
                'case_count' => \count($cases),
            ],
        ];
    }

    private function inspectRequestedAction(string $requestedAction, array $actions): array
    {
        if ($requestedAction === '') {
            return [
                'state' => 'idle',
                'http_status' => 200,
                'message' => __('请选择一个动作查看是否允许执行。'),
            ];
        }

        foreach ($actions as $action) {
            if (($action['code'] ?? '') !== $requestedAction) {
                continue;
            }

            if (($action['state'] ?? '') === 'allowed') {
                return [
                    'state' => 'allowed',
                    'http_status' => 200,
                    'message' => __('动作允许：角色、订单状态和证据前置条件均满足。'),
                    'label' => (string)$action['label'],
                ];
            }

            return [
                'state' => 'blocked',
                'http_status' => 403,
                'message' => __('动作被阻断：当前角色或订单状态不满足执行条件。'),
                'label' => (string)$action['label'],
            ];
        }

        return [
            'state' => 'blocked',
            'http_status' => 403,
            'message' => __('动作被阻断：当前角色没有该动作入口。'),
        ];
    }

    private function buildActions(
        string $role,
        TradeOrder $order,
        bool $hasAcceptance,
        bool $hasRefund,
        bool $hasDispute,
        bool $hasDeliverySubmission
    ): array
    {
        $orderId = $order->getPublicId();
        $status = (string)$order->getData(TradeOrder::schema_fields_STATUS);

        return match ($role) {
            self::ROLE_BUYER => [
                $this->action('review_order', __('查看订单证据'), 'allowed', __('买方始终可以查看自己的托管订单与证据。'), '/a2a/frontend/confirm?draft=' . \rawurlencode((string)$order->getData(TradeOrder::schema_fields_DRAFT_PUBLIC_ID))),
                $this->action('accept_delivery', __('验收交付'), $this->allowedWhen($status === TradeOrder::STATUS_EXECUTION_READY && $hasDeliverySubmission && !$hasRefund && !$hasDispute), __('只有 Agent 已提交交付证据且没有退款/争议分支时，买方才能验收。'), '/a2a/frontend/acceptance?order=' . \rawurlencode($orderId)),
                $this->action('open_refund', __('发起退款复核'), $this->allowedWhen($hasAcceptance && !$hasDispute), __('必须先形成验收证据；争议仲裁开启后不能再走普通退款复核。'), '/a2a/frontend/settlement-case?case=refund&order=' . \rawurlencode($orderId)),
                $this->action('open_dispute', __('发起争议仲裁'), $this->allowedWhen($hasAcceptance), __('必须引用验收快照和交付证据才能进入仲裁。'), '/a2a/frontend/settlement-case?case=dispute&order=' . \rawurlencode($orderId)),
                $this->action('release_funds', __('直接释放资金'), $this->allowedWhen(false), __('资金释放必须通过验收或仲裁裁决，买方不能绕过托管账本。')),
            ],
            self::ROLE_PROVIDER => [
                $this->action('submit_scope', __('提交执行范围'), $this->allowedWhen($status === TradeOrder::STATUS_ESCROW_LOCKED), __('只有托管已锁定且尚未提交范围时，Agent 才能补充执行边界。'), '/a2a/frontend/provider-scope?order=' . \rawurlencode($orderId)),
                $this->action('submit_delivery', __('提交交付证据'), $this->allowedWhen($status === TradeOrder::STATUS_EXECUTION_READY), __('必须先完成执行范围、权限和证据清单；该动作不会释放托管。'), '/a2a/frontend/delivery-submission?order=' . \rawurlencode($orderId)),
                $this->action('respond_dispute', __('响应争议'), $this->allowedWhen($hasDispute), __('存在争议仲裁记录时，Agent 可以补充说明和证据。'), '/a2a/frontend/settlement-case?case=dispute&order=' . \rawurlencode($orderId)),
                $this->action('withdraw_payout', __('申请收入出款'), $this->allowedWhen(false), __('出款依赖钱包适配器和最终结算状态，本阶段禁止直接出款。')),
            ],
            self::ROLE_PLATFORM => [
                $this->action('risk_review', __('执行风控复核'), $this->allowedWhen($hasRefund || $hasDispute), __('退款或争议分支存在时，平台风控必须复核证据和账本状态。')),
                $this->action('freeze_funds', __('冻结托管资金'), $this->allowedWhen($hasDispute), __('争议仲裁开启后，平台可确认资金冻结状态。'), '/a2a/frontend/settlement-case?case=dispute&order=' . \rawurlencode($orderId)),
                $this->action('monitor_wallet', __('监控钱包执行'), $this->allowedWhen($hasDispute), __('平台风控必须能追踪最终裁决后的钱包指令状态、失败原因和对账结果。'), '/a2a/frontend/wallet-monitor?order=' . \rawurlencode($orderId) . '&mode=inspect'),
                $this->action('recalculate_reputation', __('重算 Agent 信誉'), $this->allowedWhen(true), __('信誉重算必须引用订单、验收、退款、争议、最终裁决和钱包执行证据。'), '/a2a/frontend/reputation?agent=' . \rawurlencode($this->resolveProviderKey((string)$order->getData(TradeOrder::schema_fields_PROVIDER)))),
                $this->action('fee_adjustment', __('调整平台服务费'), $this->allowedWhen(false), __('服务费减免需要钱包适配器和最终裁决动作，本阶段不可执行。')),
            ],
            self::ROLE_ARBITRATOR => [
                $this->action('review_evidence', __('复核仲裁证据'), $this->allowedWhen($hasDispute), __('只有争议仲裁分支存在时，仲裁员才能查看冻结证据包。'), '/a2a/frontend/settlement-case?case=dispute&order=' . \rawurlencode($orderId)),
                $this->action('issue_full_release', __('裁决全额放款'), $this->allowedWhen($hasDispute), __('争议存在时，仲裁员可生成全额放款裁决与 dry-run 钱包指令。'), '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($orderId) . '&ruling=full_release'),
                $this->action('issue_partial_release', __('裁决部分放款'), $this->allowedWhen($hasDispute), __('部分放款必须同步生成买方退款、平台服务费和 Agent 出款指令。'), '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($orderId) . '&ruling=partial_release'),
                $this->action('issue_refund', __('裁决全额退款'), $this->allowedWhen($hasDispute), __('退款裁决必须阻断 Agent 出款并生成买方退款指令。'), '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($orderId) . '&ruling=refund'),
                $this->action('request_rework', __('裁决返工'), $this->allowedWhen($hasDispute), __('返工裁决继续冻结资金，并要求新的交付版本和时限记录。'), '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($orderId) . '&ruling=rework'),
                $this->action('execute_wallet_dry_run', __('执行钱包 dry-run'), $this->allowedWhen($hasDispute), __('钱包 dry-run 只能在最终裁决形成后执行，并必须留下幂等键和对账状态。'), '/a2a/frontend/wallet-monitor?order=' . \rawurlencode($orderId) . '&mode=dry_run_execute'),
            ],
            default => [],
        };
    }

    private function action(string $code, string|\Stringable $label, string $state, string|\Stringable $rule, string $href = ''): array
    {
        return [
            'code' => $code,
            'label' => (string)$label,
            'state' => $state,
            'state_label' => $this->formatActionState($state),
            'rule' => (string)$rule,
            'href' => $href,
        ];
    }

    private function allowedWhen(bool $condition): string
    {
        return $condition ? 'allowed' : 'blocked';
    }

    private function loadAcceptance(string $orderPublicId): ?DeliveryAcceptance
    {
        $acceptance = $this->freshModel($this->deliveryAcceptanceModel);
        $acceptance->where(DeliveryAcceptance::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)->find()->fetch();

        return $acceptance->getId() ? $acceptance : null;
    }

    private function loadProviderScope(string $orderPublicId): ?ProviderScopeSubmission
    {
        $providerScope = $this->freshModel($this->providerScopeSubmissionModel);
        $providerScope->where(ProviderScopeSubmission::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)->find()->fetch();

        return $providerScope->getId() ? $providerScope : null;
    }

    private function hasDeliverySubmission(?ProviderScopeSubmission $providerScope): bool
    {
        if (!$providerScope) {
            return false;
        }

        $metadata = (string)$providerScope->getData(ProviderScopeSubmission::schema_fields_METADATA_JSON);
        if ($metadata === '') {
            return false;
        }

        try {
            $decoded = \json_decode($metadata, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        $deliverySubmission = \is_array($decoded) ? ($decoded['delivery_submission'] ?? []) : [];

        return \is_array($deliverySubmission)
            && (string)($deliverySubmission['status'] ?? '') === 'submitted'
            && (string)($deliverySubmission['delivery_public_id'] ?? '') !== '';
    }

    private function loadSettlementCases(string $orderPublicId): array
    {
        $rows = $this->freshModel($this->settlementCaseModel)
            ->where(SettlementCase::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values($rows) : [];
    }

    private function hasCase(array $cases, string $caseType): bool
    {
        foreach ($cases as $case) {
            if ((string)($case[SettlementCase::schema_fields_CASE_TYPE] ?? '') === $caseType) {
                return true;
            }
        }

        return false;
    }

    private function buildRoleTabs(string $orderPublicId, string $currentRole): array
    {
        $tabs = [];
        foreach ($this->roleLabels() as $role => $label) {
            $tabs[] = [
                'role' => $role,
                'label' => $label,
                'active' => $role === $currentRole,
                'href' => '/a2a/frontend/role-console?switch_role=1&role=' . \rawurlencode($role) . '&order=' . \rawurlencode($orderPublicId),
            ];
        }

        return $tabs;
    }

    private function buildGuardRules(bool $hasRefund, bool $hasDispute): array
    {
        $rules = [
            __('未托管的订单不能进入 Agent 执行。'),
            __('Agent 只能在声明的执行范围和工具权限内交付。'),
            __('买方不能绕过验收或仲裁直接释放资金。'),
            __('A2A 会话角色必须先切换并保存，不能只靠 URL role 参数执行敏感动作。'),
            __('平台风控、仲裁裁决和钱包动作必须通过订单级运行时实证，不能只靠 ACL Source 注册或角色标签。'),
        ];
        if ($hasRefund) {
            $rules[] = __('退款复核存在时，Agent 收入和平台服务费必须等待复核结果。');
        }
        if ($hasDispute) {
            $rules[] = __('争议仲裁存在时，买方资金、平台服务费和 Agent 收入全部冻结。');
        }

        return $rules;
    }

    private function buildCaseSummary(array $cases): array
    {
        $summary = [];
        foreach ($cases as $case) {
            $caseType = (string)($case[SettlementCase::schema_fields_CASE_TYPE] ?? '');
            $summary[] = [
                'case_id' => (string)($case[SettlementCase::schema_fields_PUBLIC_ID] ?? ''),
                'type' => $caseType === SettlementCase::TYPE_REFUND ? __('退款复核') : __('争议仲裁'),
                'status' => $caseType === SettlementCase::TYPE_REFUND ? __('退款复核中') : __('争议仲裁冻结中'),
            ];
        }

        return $summary;
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
            'status_label' => $this->formatLedgerStatus((string)($row[EscrowLedger::schema_fields_STATUS] ?? '')),
        ], $rows));
    }

    private function roleLabels(): array
    {
        return [
            self::ROLE_BUYER => __('买方'),
            self::ROLE_PROVIDER => __('Agent'),
            self::ROLE_PLATFORM => __('平台风控'),
            self::ROLE_ARBITRATOR => __('仲裁员'),
        ];
    }

    private function formatActionState(string $state): string
    {
        return match ($state) {
            'allowed' => (string) __('可执行'),
            'blocked' => (string) __('已阻断'),
            default => (string) __('待选择'),
        };
    }

    private function formatOrderStatus(string $status): string
    {
        return match ($status) {
            TradeOrder::STATUS_ESCROW_LOCKED => (string) __('托管已锁定'),
            TradeOrder::STATUS_EXECUTION_READY => (string) __('范围已提交，等待交付'),
            TradeOrder::STATUS_ACCEPTED_RELEASED => (string) __('已验收放款'),
            TradeOrder::STATUS_REFUND_REVIEW => (string) __('退款复核中'),
            TradeOrder::STATUS_DISPUTE_ARBITRATION => (string) __('争议仲裁冻结中'),
            TradeOrder::STATUS_ARBITRATION_RULED => (string) __('仲裁已裁决，钱包指令待执行'),
            TradeOrder::STATUS_REWORK_REQUIRED => (string) __('仲裁裁决返工，资金继续冻结'),
            default => $status,
        };
    }

    private function formatProviderQueue(string $status): string
    {
        return match ($status) {
            TradeOrder::PROVIDER_QUEUE_PENDING_SCOPE => (string) __('等待 Agent 补充范围'),
            TradeOrder::PROVIDER_QUEUE_SCOPE_SUBMITTED => (string) __('范围已提交'),
            TradeOrder::PROVIDER_QUEUE_DELIVERY_SUBMITTED => (string) __('交付证据已提交'),
            TradeOrder::PROVIDER_QUEUE_ACCEPTED => (string) __('已验收，等待结算出款'),
            TradeOrder::PROVIDER_QUEUE_REFUND_REVIEW => (string) __('退款复核中'),
            TradeOrder::PROVIDER_QUEUE_DISPUTE_HOLD => (string) __('争议冻结中'),
            TradeOrder::PROVIDER_QUEUE_ARBITRATION_RULED => (string) __('仲裁已裁决'),
            TradeOrder::PROVIDER_QUEUE_REWORK_REQUIRED => (string) __('返工补交中'),
            default => $status,
        };
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
}
