<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use GuoLaiRen\A2A\Exception\TradeActorAuthorizationException;
use GuoLaiRen\A2A\Model\TradeActorAssignment;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

class TradeActorAuthorizationGuardService
{
    public function __construct(
        private readonly RoleSessionService $roleSessionService,
        private readonly TradeActorAssignmentService $tradeActorAssignmentService
    ) {
    }

    private ?RuntimeProofTokenService $runtimeProofTokenService = null;

    public function assertBoundActor(
        AuthenticatedSessionInterface $session,
        string $orderPublicId,
        string|array $allowedRoles,
        string|\Stringable $operationLabel,
        array $options = []
    ): array {
        $allowedRoles = $this->normalizeAllowedRoles($allowedRoles);
        $actor = $this->roleSessionService->resolveActor($session, '', false);
        $role = \strtolower(\trim((string)($actor['role'] ?? '')));

        if (!\in_array($role, $allowedRoles, true)) {
            throw new TradeActorAuthorizationException((string) __(
                '当前会话角色不能执行“%{1}”，需要 %{2}。',
                [(string)$operationLabel, $this->formatAllowedRoles($allowedRoles)]
            ));
        }

        $actorAcl = $this->tradeActorAssignmentService->inspect($orderPublicId, $actor);
        $assignment = $actorAcl['current_assignment'] ?? [];
        $bindingStatus = (string)($assignment['binding_status'] ?? '');
        $boundSubjectReference = (string)($assignment['bound_subject_reference'] ?? '');
        $identityReadiness = (string)($assignment['identity_readiness'] ?? '');
        $identitySource = (string)($assignment['identity_source'] ?? '');
        $runtimeProofPassed = !empty($assignment['runtime_proof_passed']);
        $requiresRuntimeProof = $this->requiresRuntimeProof($role, $options);
        $runtimeTokenProof = [];
        if ($bindingStatus !== TradeActorAssignment::BINDING_ACCOUNT_BOUND || $boundSubjectReference === '') {
            throw new TradeActorAuthorizationException((string) __(
                '当前订单角色尚未绑定账号/权限组，不能执行“%{1}”。请先在角色权限控制台绑定当前角色。',
                [(string)$operationLabel]
            ));
        }
        if ($requiresRuntimeProof && !$runtimeProofPassed) {
            $runtimeTokenProof = $this->runtimeProofTokenService()->verify(
                (string)($options['runtime_proof_token'] ?? ''),
                [
                    'role' => $role,
                    'source_id' => (string)($assignment['runtime_acl_source'] ?? ''),
                    'order' => $orderPublicId,
                    'action' => (string)($options['runtime_proof_action'] ?? ''),
                ]
            );
            $runtimeProofPassed = !empty($runtimeTokenProof['passed']);
        }
        if ($requiresRuntimeProof && !$runtimeProofPassed) {
            $runtimeProofGap = (string)($runtimeTokenProof['gap'] ?? ($assignment['runtime_proof_gap'] ?? __('需要补齐与角色匹配的会话、账号或 ACL 证据。')));
            throw new TradeActorAuthorizationException((string) __(
                '当前订单角色缺少运行时实证，不能执行“%{1}”。请先完成 %{2} 的后台会话/ACL 实证，并把该实证绑定到订单动作上下文。当前缺口：%{3}',
                [
                    (string)$operationLabel,
                    (string)($assignment['runtime_acl_source'] ?? __('对应角色')),
                    $runtimeProofGap,
                ]
            ));
        }

        $runtimeProofStatus = (string)($assignment['runtime_proof_status'] ?? '');
        $runtimeProofLabel = (string)($assignment['runtime_proof_label'] ?? '');
        $runtimeProofEvidence = (string)($assignment['runtime_proof_evidence'] ?? '');
        $runtimeProofGap = (string)($assignment['runtime_proof_gap'] ?? '');
        if (!empty($runtimeTokenProof['passed'])) {
            $runtimeProofStatus = (string)$runtimeTokenProof['status'];
            $runtimeProofLabel = (string)$runtimeTokenProof['label'];
            $runtimeProofEvidence = (string)$runtimeTokenProof['evidence'];
            $runtimeProofGap = (string)$runtimeTokenProof['gap'];
        }

        return [
            'actor' => $actor,
            'actor_acl' => $actorAcl,
            'authorization_guard' => [
                'operation' => (string)$operationLabel,
                'allowed_roles' => $allowedRoles,
                'role' => $role,
                'bound_subject_reference' => $boundSubjectReference,
                'bound_subject_display' => (string)($assignment['bound_subject_display'] ?? ''),
                'identity_readiness' => $identityReadiness,
                'identity_readiness_label' => (string)($assignment['identity_readiness_label'] ?? ''),
                'identity_source' => $identitySource,
                'identity_source_label' => (string)($assignment['identity_source_label'] ?? ''),
                'runtime_proof_status' => $runtimeProofStatus,
                'runtime_proof_label' => $runtimeProofLabel,
                'runtime_proof_evidence' => $runtimeProofEvidence,
                'runtime_proof_gap' => $runtimeProofGap,
                'runtime_acl_source' => (string)($assignment['runtime_acl_source'] ?? ''),
                'runtime_proof_required' => $requiresRuntimeProof,
                'runtime_proof_token_status' => (string)($runtimeTokenProof['status'] ?? ''),
                'identity_status' => $runtimeProofPassed ? 'production_ready' : 'allowed_with_runtime_proof_gap',
                'status' => 'allowed',
            ],
        ];
    }

    private function normalizeAllowedRoles(string|array $allowedRoles): array
    {
        $roles = \is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
        $normalized = [];
        foreach ($roles as $role) {
            $role = \strtolower(\trim((string)$role));
            if ($role !== '') {
                $normalized[] = $role;
            }
        }

        return \array_values(\array_unique($normalized));
    }

    private function requiresRuntimeProof(string $role, array $options): bool
    {
        if (($options['require_runtime_proof'] ?? false) === true) {
            return true;
        }

        $requiredRoles = $this->normalizeAllowedRoles($options['require_runtime_proof_roles'] ?? []);

        return \in_array($role, $requiredRoles, true);
    }

    private function formatAllowedRoles(array $roles): string
    {
        $labels = [
            'buyer' => (string) __('买方'),
            'provider' => (string) __('Agent'),
            'platform' => (string) __('平台风控'),
            'arbitrator' => (string) __('仲裁员'),
        ];
        $formatted = [];
        foreach ($roles as $role) {
            $formatted[] = $labels[$role] ?? $role;
        }

        return \implode(' / ', $formatted);
    }

    private function runtimeProofTokenService(): RuntimeProofTokenService
    {
        $this->runtimeProofTokenService ??= ObjectManager::getInstance(RuntimeProofTokenService::class);

        return $this->runtimeProofTokenService;
    }
}
