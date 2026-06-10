<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

use Aiweline\A2A\Model\TradeActorAssignment;
use Aiweline\A2A\Model\TradeOrder;
use Weline\Framework\Database\Model;

class TradeActorAssignmentService
{
    private const ROLE_BUYER = 'buyer';
    private const ROLE_PROVIDER = 'provider';
    private const ROLE_PLATFORM = 'platform';
    private const ROLE_ARBITRATOR = 'arbitrator';

    private readonly TradeActorIdentityResolutionService $identityResolutionService;
    private readonly TradeActorRuntimeProofService $runtimeProofService;

    public function __construct(
        private readonly TradeOrder $tradeOrderModel,
        private readonly TradeActorAssignment $assignmentModel,
        ?TradeActorIdentityResolutionService $identityResolutionService = null,
        ?TradeActorRuntimeProofService $runtimeProofService = null
    ) {
        $this->identityResolutionService = $identityResolutionService ?? new TradeActorIdentityResolutionService();
        $this->runtimeProofService = $runtimeProofService ?? new TradeActorRuntimeProofService();
    }

    public function inspect(string $orderPublicId, array $actor): array
    {
        $orderPublicId = \strtoupper(\trim($orderPublicId));
        if ($orderPublicId === '') {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        $tradeOrder = $this->loadTradeOrder($orderPublicId);
        $this->syncAssignments($tradeOrder);
        $assignments = $this->loadAssignments($tradeOrder->getPublicId());
        $role = \strtolower((string)($actor['role'] ?? ''));
        $current = $assignments[$role] ?? null;
        if ($current instanceof TradeActorAssignment) {
            $this->markChecked($current, $actor);
            $assignments[$role] = $current;
        }

        $rows = [];
        $boundCount = 0;
        $productionIdentityReadyCount = 0;
        $contractReadyCount = 0;
        $prototypeBoundCount = 0;
        $runtimeProofPassedCount = 0;
        $runtimeProofMissingCount = 0;
        foreach ($this->roleLabels() as $roleCode => $roleLabel) {
            $assignment = $assignments[$roleCode] ?? null;
            if (!$assignment instanceof TradeActorAssignment) {
                continue;
            }
            if ((string)$assignment->getData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS) === TradeActorAssignment::BINDING_ACCOUNT_BOUND) {
                $boundCount++;
            }
            $row = $this->formatAssignmentRow($assignment, $roleLabel, $roleCode === $role, $actor);
            if (!empty($row['production_ready'])) {
                $productionIdentityReadyCount++;
            }
            if (!empty($row['runtime_proof_passed'])) {
                $runtimeProofPassedCount++;
            } elseif (!empty($row['runtime_proof_required'])) {
                $runtimeProofMissingCount++;
            }
            if (($row['identity_readiness'] ?? '') === 'contract_ready') {
                $contractReadyCount++;
            }
            if (($row['identity_readiness'] ?? '') === 'prototype_only') {
                $prototypeBoundCount++;
            }
            $rows[] = $row;
        }

        return [
            'current_assignment' => $current instanceof TradeActorAssignment
                ? $this->formatAssignmentRow($current, (string)($this->roleLabels()[$role] ?? $role), true, $actor)
                : \array_merge([
                    'role_code' => $role,
                    'role_label' => (string)($this->roleLabels()[$role] ?? $role),
                    'actor_display' => '',
                    'binding_label' => __('订单角色未归属'),
                    'verification_label' => __('未验证'),
                    'identity_readiness' => 'unbound',
                    'identity_readiness_label' => $this->identityResolutionService->formatIdentityReadiness('unbound'),
                    'identity_source_label' => __('未解析'),
                    'identity_risk_label' => __('尚未绑定身份主体。'),
                    'identity_evidence_label' => __('无身份解析证据'),
                    'production_ready' => false,
                    'active' => false,
                ], $this->runtimeProofService->fallbackProof($role)),
            'assignments' => $rows,
            'metrics' => [
                'assignment_count' => \count($rows),
                'assignment_count_label' => \count($rows) . '/4',
                'account_bound_count' => $boundCount,
                'account_bound_label' => $boundCount . '/4',
                'production_identity_ready_count' => $productionIdentityReadyCount,
                'production_identity_ready_label' => $productionIdentityReadyCount . '/4',
                'contract_ready_count' => $contractReadyCount,
                'contract_ready_label' => $contractReadyCount . '/4',
                'prototype_bound_count' => $prototypeBoundCount,
                'prototype_bound_label' => $prototypeBoundCount . '/4',
                'runtime_proof_passed_count' => $runtimeProofPassedCount,
                'runtime_proof_passed_label' => $runtimeProofPassedCount . '/4',
                'runtime_proof_missing_count' => $runtimeProofMissingCount,
                'runtime_proof_missing_label' => $runtimeProofMissingCount . '/4',
                'production_ready' => $runtimeProofPassedCount === 4,
            ],
            'notices' => $this->buildNotices($actor, $boundCount, $productionIdentityReadyCount, $contractReadyCount, $prototypeBoundCount, $runtimeProofPassedCount, $runtimeProofMissingCount),
        ];
    }

    public function bindCurrentActor(string $orderPublicId, array $actor, string $bindingIntent = 'prototype'): array
    {
        $orderPublicId = \strtoupper(\trim($orderPublicId));
        $role = \strtolower(\trim((string)($actor['role'] ?? '')));
        if ($orderPublicId === '') {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }
        if (!isset($this->roleLabels()[$role])) {
            throw new \InvalidArgumentException((string) __('角色不存在或尚未接入 A2A 权限策略。'));
        }

        $tradeOrder = $this->loadTradeOrder($orderPublicId);
        $this->syncAssignments($tradeOrder);
        $assignments = $this->loadAssignments($tradeOrder->getPublicId());
        $assignment = $assignments[$role] ?? null;
        if (!$assignment instanceof TradeActorAssignment) {
            throw new \InvalidArgumentException((string) __('订单角色未归属。'));
        }

        $binding = $this->identityResolutionService->resolveBindingSubject($role, $assignment, $actor);
        $metadata = $this->decodeJson((string)$assignment->getData(TradeActorAssignment::schema_fields_METADATA_JSON));
        $metadata['binding_event'] = [
            'binding_intent' => $bindingIntent !== '' ? $bindingIntent : 'prototype',
            'session_role' => $role,
            'subject_type' => $binding['subject_type'],
            'subject_reference' => $binding['subject_reference'],
            'identity_source' => $binding['identity_source'],
            'identity_readiness' => $binding['identity_readiness'],
            'production_ready' => $binding['production_ready'],
            'risk_label' => $binding['risk_label'],
            'evidence_label' => $binding['evidence_label'],
            'bound_at' => \date('c'),
            'is_real_login' => (bool)($actor['is_logged_in'] ?? false),
            'resolver' => 'a2a_identity_resolution_v1',
        ];
        if (!empty($binding['operator_provider_key'])) {
            $metadata['binding_event']['operator_provider_key'] = (string)$binding['operator_provider_key'];
        }
        if (!empty($binding['operator_backend_user_id'])) {
            $metadata['binding_event']['operator_backend_user_id'] = (int)$binding['operator_backend_user_id'];
        }

        $assignment->setData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS, TradeActorAssignment::BINDING_ACCOUNT_BOUND);
        $assignment->setData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_TYPE, $binding['subject_type']);
        $assignment->setData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_REFERENCE, $binding['subject_reference']);
        $assignment->setData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_DISPLAY, $binding['subject_display']);
        $assignment->setData(TradeActorAssignment::schema_fields_VERIFICATION_LEVEL, $binding['verification_level']);
        $assignment->setData(TradeActorAssignment::schema_fields_BOUND_AT, \date('Y-m-d H:i:s'));
        $assignment->setData(TradeActorAssignment::schema_fields_LAST_CHECKED_AT, \date('Y-m-d H:i:s'));
        $assignment->setData(TradeActorAssignment::schema_fields_METADATA_JSON, $this->encodeJson($metadata));
        $assignment->save();

        $result = $this->inspect($tradeOrder->getPublicId(), $actor);
        $result['binding_result'] = [
            'state' => 'bound',
            'message' => __('当前订单角色已绑定到账号/组织/权限组原型主体。'),
            'subject_type' => $binding['subject_type'],
            'subject_reference' => $binding['subject_reference'],
            'subject_display' => $binding['subject_display'],
            'identity_readiness' => $binding['identity_readiness'],
            'identity_readiness_label' => $binding['identity_readiness_label'],
            'runtime_proof_label' => (string)($result['current_assignment']['runtime_proof_label'] ?? ''),
            'production_ready' => $binding['production_ready'],
        ];

        return $result;
    }

    private function syncAssignments(TradeOrder $tradeOrder): void
    {
        foreach ($this->buildAssignmentSpecs($tradeOrder) as $spec) {
            $model = $this->freshModel($this->assignmentModel);
            $model->where(TradeActorAssignment::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId())
                ->where(TradeActorAssignment::schema_fields_ROLE_CODE, (string)$spec['role_code'])
                ->find()
                ->fetch();

            $model->setData(TradeActorAssignment::schema_fields_PUBLIC_ID, $this->buildAssignmentId($tradeOrder->getPublicId(), (string)$spec['role_code']));
            $model->setData(TradeActorAssignment::schema_fields_ORDER_PUBLIC_ID, $tradeOrder->getPublicId());
            $model->setData(TradeActorAssignment::schema_fields_ROLE_CODE, (string)$spec['role_code']);
            $model->setData(TradeActorAssignment::schema_fields_ACTOR_TYPE, (string)$spec['actor_type']);
            $model->setData(TradeActorAssignment::schema_fields_ACTOR_REFERENCE, (string)$spec['actor_reference']);
            $model->setData(TradeActorAssignment::schema_fields_ACTOR_DISPLAY, (string)$spec['actor_display']);
            $model->setData(TradeActorAssignment::schema_fields_OWNERSHIP_SCOPE, (string)$spec['ownership_scope']);
            $isAlreadyBound = (string)$model->getData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS) === TradeActorAssignment::BINDING_ACCOUNT_BOUND;
            if (!$isAlreadyBound) {
                $model->setData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS, (string)$spec['auth_binding_status']);
                $model->setData(TradeActorAssignment::schema_fields_VERIFICATION_LEVEL, (string)$spec['verification_level']);
            } elseif ((string)$model->getData(TradeActorAssignment::schema_fields_VERIFICATION_LEVEL) === '') {
                $model->setData(TradeActorAssignment::schema_fields_VERIFICATION_LEVEL, (string)$spec['verification_level']);
            }
            $model->setData(TradeActorAssignment::schema_fields_STATUS, TradeActorAssignment::STATUS_ACTIVE);
            $metadata = $this->decodeJson((string)$model->getData(TradeActorAssignment::schema_fields_METADATA_JSON));
            $metadata['order_public_id'] = $tradeOrder->getPublicId();
            $metadata['sku_code'] = (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_CODE);
            $metadata['provider'] = (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER);
            $metadata['source'] = 'order_actor_assignment_sync';
            $model->setData(TradeActorAssignment::schema_fields_METADATA_JSON, $this->encodeJson($metadata ?: [
                'order_public_id' => $tradeOrder->getPublicId(),
                'sku_code' => (string)$tradeOrder->getData(TradeOrder::schema_fields_SKU_CODE),
                'provider' => (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER),
                'source' => 'order_actor_assignment_sync',
            ]));
            $model->save();
        }
    }

    private function markChecked(TradeActorAssignment $assignment, array $actor): void
    {
        $isAccountMatched = $this->isAccountMatched($assignment, $actor);
        $metadata = $this->decodeJson((string)$assignment->getData(TradeActorAssignment::schema_fields_METADATA_JSON));
        $metadata['last_actor_check'] = [
            'session_role' => (string)($actor['role'] ?? ''),
            'is_logged_in' => (bool)($actor['is_logged_in'] ?? false),
            'identity_id' => (string)($actor['identity_id'] ?? ''),
            'matched' => $isAccountMatched,
        ];

        if ($isAccountMatched) {
            $assignment->setData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS, TradeActorAssignment::BINDING_ACCOUNT_BOUND);
        } elseif ((string)$assignment->getData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS) !== TradeActorAssignment::BINDING_ACCOUNT_BOUND) {
            $assignment->setData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS, TradeActorAssignment::BINDING_NEEDS_ACCOUNT);
        }
        $assignment->setData(TradeActorAssignment::schema_fields_LAST_CHECKED_AT, \date('Y-m-d H:i:s'));
        $assignment->setData(TradeActorAssignment::schema_fields_METADATA_JSON, $this->encodeJson($metadata));
        $assignment->save();
    }

    private function isAccountMatched(TradeActorAssignment $assignment, array $actor): bool
    {
        if (($actor['is_logged_in'] ?? false) !== true) {
            return false;
        }

        $identityId = \strtolower(\trim((string)($actor['identity_id'] ?? '')));
        if ($identityId === '') {
            return false;
        }

        $reference = \strtolower((string)$assignment->getData(TradeActorAssignment::schema_fields_ACTOR_REFERENCE));
        return \in_array($reference, [$identityId, 'customer:' . $identityId], true);
    }

    /**
     * @return array<string, TradeActorAssignment>
     */
    private function loadAssignments(string $orderPublicId): array
    {
        $rows = $this->freshModel($this->assignmentModel)
            ->where(TradeActorAssignment::schema_fields_ORDER_PUBLIC_ID, $orderPublicId)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }

        $assignments = [];
        foreach ($rows as $row) {
            $model = $this->freshModel($this->assignmentModel);
            foreach ($row as $field => $value) {
                $model->setData((string)$field, $value);
            }
            $assignments[(string)($row[TradeActorAssignment::schema_fields_ROLE_CODE] ?? '')] = $model;
        }

        return $assignments;
    }

    /**
     * @return list<array<string, string>>
     */
    private function buildAssignmentSpecs(TradeOrder $tradeOrder): array
    {
        $provider = (string)$tradeOrder->getData(TradeOrder::schema_fields_PROVIDER);
        $buyerReference = (string)$tradeOrder->getData(TradeOrder::schema_fields_BUYER_REFERENCE);

        return [
            [
                'role_code' => self::ROLE_BUYER,
                'actor_type' => 'buyer_reference',
                'actor_reference' => $buyerReference !== '' ? $buyerReference : 'prototype-buyer',
                'actor_display' => __('订单买方'),
                'ownership_scope' => __('订单证据、验收、退款和争议发起'),
                'auth_binding_status' => TradeActorAssignment::BINDING_NEEDS_ACCOUNT,
                'verification_level' => 'buyer_reference_snapshot',
            ],
            [
                'role_code' => self::ROLE_PROVIDER,
                'actor_type' => 'agent_provider',
                'actor_reference' => $this->resolveProviderKey($provider),
                'actor_display' => $provider !== '' ? $provider : (string) __('Agent 供给方'),
                'ownership_scope' => __('执行范围、交付证据和争议响应'),
                'auth_binding_status' => TradeActorAssignment::BINDING_NEEDS_ACCOUNT,
                'verification_level' => 'provider_key_snapshot',
            ],
            [
                'role_code' => self::ROLE_PLATFORM,
                'actor_type' => 'platform_risk_team',
                'actor_reference' => 'platform-risk-ops',
                'actor_display' => __('平台风控组'),
                'ownership_scope' => __('风控复核、资金冻结、钱包监控和信誉治理'),
                'auth_binding_status' => TradeActorAssignment::BINDING_NEEDS_ACCOUNT,
                'verification_level' => 'platform_group_snapshot',
            ],
            [
                'role_code' => self::ROLE_ARBITRATOR,
                'actor_type' => 'arbitration_panel',
                'actor_reference' => 'a2a-arbitration-panel',
                'actor_display' => __('A2A 仲裁席位'),
                'ownership_scope' => __('仲裁证据复核、最终裁决和钱包指令确认'),
                'auth_binding_status' => TradeActorAssignment::BINDING_NEEDS_ACCOUNT,
                'verification_level' => 'arbitration_panel_snapshot',
            ],
        ];
    }

    private function formatAssignmentRow(TradeActorAssignment $assignment, string|\Stringable $roleLabel, bool $active, array $actor): array
    {
        $bindingStatus = (string)$assignment->getData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS);
        $metadata = $this->decodeJson((string)$assignment->getData(TradeActorAssignment::schema_fields_METADATA_JSON));
        $identitySummary = $this->identityResolutionService->summarizeAssignment($assignment, $metadata);
        $runtimeProofSummary = $this->runtimeProofService->summarizeProof($assignment, $metadata, $actor);

        return \array_merge([
            'public_id' => (string)$assignment->getData(TradeActorAssignment::schema_fields_PUBLIC_ID),
            'role_code' => (string)$assignment->getData(TradeActorAssignment::schema_fields_ROLE_CODE),
            'role_label' => (string)$roleLabel,
            'actor_type' => (string)$assignment->getData(TradeActorAssignment::schema_fields_ACTOR_TYPE),
            'actor_reference' => (string)$assignment->getData(TradeActorAssignment::schema_fields_ACTOR_REFERENCE),
            'actor_display' => (string)$assignment->getData(TradeActorAssignment::schema_fields_ACTOR_DISPLAY),
            'ownership_scope' => (string)$assignment->getData(TradeActorAssignment::schema_fields_OWNERSHIP_SCOPE),
            'binding_status' => $bindingStatus,
            'binding_label' => $this->formatBindingStatus($bindingStatus),
            'bound_subject_type' => (string)$assignment->getData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_TYPE),
            'bound_subject_reference' => (string)$assignment->getData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_REFERENCE),
            'bound_subject_display' => (string)$assignment->getData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_DISPLAY),
            'bound_at' => (string)$assignment->getData(TradeActorAssignment::schema_fields_BOUND_AT),
            'verification_label' => $this->formatVerificationLevel((string)$assignment->getData(TradeActorAssignment::schema_fields_VERIFICATION_LEVEL)),
            'last_checked_at' => (string)$assignment->getData(TradeActorAssignment::schema_fields_LAST_CHECKED_AT),
            'active' => $active,
        ], $identitySummary, $runtimeProofSummary);
    }

    private function buildNotices(
        array $actor,
        int $boundCount,
        int $productionIdentityReadyCount,
        int $contractReadyCount,
        int $prototypeBoundCount,
        int $runtimeProofPassedCount,
        int $runtimeProofMissingCount
    ): array
    {
        $notices = [
            __('订单角色归属已持久化，后续可绑定真实买方、Agent、平台风控和仲裁账号。'),
        ];

        if (($actor['is_logged_in'] ?? false) !== true) {
            $notices[] = __('当前访问未登录，仅完成订单归属与会话原型校验。');
        }
        if ($prototypeBoundCount > 0) {
            $notices[] = __('存在仅原型身份绑定，不能作为生产放款、退款或仲裁依据。');
        }
        if ($contractReadyCount > 0) {
            $notices[] = __('存在权限契约待实证角色，下一步需要接入真实 Weline ACL 或 Agent 运营账号校验。');
        }
        if ($boundCount < 4) {
            $notices[] = __('生产级 ACL 仍需把所有订单角色绑定到登录账号或后台权限组。');
        }
        if ($productionIdentityReadyCount < 4) {
            $notices[] = __('生产级身份链尚未完成，敏感写动作当前只完成角色归属守卫与身份缺口提示。');
        } else {
            $notices[] = __('所有订单角色均已进入生产级身份校验链。');
        }
        if ($runtimeProofMissingCount > 0) {
            $notices[] = __('生产级运行时实证未完成，当前仍不能声明放款、退款或仲裁生产授权。');
        }
        if ($runtimeProofPassedCount === 4) {
            $notices[] = __('所有订单角色均已通过运行时实证。');
        }

        return $notices;
    }

    private function loadTradeOrder(string $orderPublicId): TradeOrder
    {
        $model = $this->freshModel($this->tradeOrderModel);
        $model->where(TradeOrder::schema_fields_PUBLIC_ID, $orderPublicId)->find()->fetch();
        if (!$model->getId()) {
            throw new \InvalidArgumentException((string) __('正式订单不存在或已失效。'));
        }

        return $model;
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

    private function formatBindingStatus(string $status): string
    {
        return match ($status) {
            TradeActorAssignment::BINDING_ACCOUNT_BOUND => (string) __('已绑定账号/权限组'),
            default => (string) __('待绑定登录账号'),
        };
    }

    private function formatVerificationLevel(string $level): string
    {
        return match ($level) {
            'real_customer_session' => (string) __('前台客户会话'),
            'provider_operator_backend_user' => (string) __('Agent 运营后台用户'),
            'provider_operator_contract' => (string) __('Agent 运营契约'),
            'backend_acl_contract' => (string) __('后台 ACL 契约'),
            'prototype_session_claim' => (string) __('原型会话声明'),
            'buyer_reference_snapshot' => (string) __('买方引用快照'),
            'provider_key_snapshot' => (string) __('Agent 供给快照'),
            'platform_group_snapshot' => (string) __('平台权限组快照'),
            'arbitration_panel_snapshot' => (string) __('仲裁席位快照'),
            default => (string) __('未验证'),
        };
    }

    private function buildAssignmentId(string $orderPublicId, string $role): string
    {
        return 'A2A-ACTOR-' . \strtoupper(\substr(\hash('crc32b', $orderPublicId . '|' . $role), 0, 8));
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

    private function encodeJson(array $payload): string
    {
        return \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function decodeJson(string $json): array
    {
        $decoded = \json_decode($json, true);
        return \is_array($decoded) ? $decoded : [];
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
