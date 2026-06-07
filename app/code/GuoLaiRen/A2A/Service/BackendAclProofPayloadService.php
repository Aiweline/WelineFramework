<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

class BackendAclProofPayloadService
{
    private const ROLE_CAPABILITIES = [
        'provider' => [
            'submit_delivery_evidence',
            'update_delivery_status',
            'respond_to_rework',
        ],
        'platform' => [
            'risk_review',
            'escrow_hold_review',
            'refund_review',
            'wallet_monitor',
        ],
        'arbitrator' => [
            'review_dispute_evidence',
            'issue_final_ruling',
            'ruling_audit',
        ],
    ];

    public function buildIndex(array $sources, AuthenticatedSessionInterface $session): array
    {
        $actor = $this->backendActor($session);
        $contracts = [];
        foreach ($sources as $role => $source) {
            if (!\is_array($source)) {
                continue;
            }
            $contracts[] = $this->sourceContract((string)$role, $source);
        }

        return [
            'surface' => 'a2a_acl_proof_index',
            'proof_contract' => [
                'status' => $actor['authenticated'] ? 'backend_session_detected' : 'backend_login_required',
                'message' => $actor['authenticated']
                    ? (string) __('后台会话已进入 A2A ACL 实证区。')
                    : (string) __('需要先登录后台后才能查看 A2A ACL 实证。'),
                'runtime_authorization_rule' => (string) __('后台 ACL 实证只证明后台会话可进入该 Source；订单级付款、退款、交付、仲裁仍必须绑定交易角色和订单上下文。'),
            ],
            'backend_actor' => $actor,
            'sources' => $contracts,
            'checked_at' => \date(DATE_ATOM),
        ];
    }

    public function buildRoleProof(string $role, array $source, AuthenticatedSessionInterface $session): array
    {
        $actor = $this->backendActor($session);
        $sourceId = (string)($source['source_id'] ?? '');
        $passed = $actor['authenticated'] === true && $sourceId !== '';

        return [
            'surface' => 'a2a_acl_proof',
            'role' => $role,
            'source' => $source,
            'proof' => [
                'status' => $passed ? 'backend_acl_route_verified' : 'backend_login_required',
                'passed' => $passed,
                'proof_level' => $passed ? 'backend_session_acl_route' : 'login_required',
                'evidence' => $passed
                    ? (string) __('当前后台会话已通过 ACL 路由守卫进入 Source：%{1}', [$sourceId])
                    : (string) __('当前请求尚未形成可用的后台会话 ACL 实证。'),
                'checked_at' => \date(DATE_ATOM),
            ],
            'backend_actor' => $actor,
            'capability_scope' => self::ROLE_CAPABILITIES[$role] ?? [],
            'marketplace_trust_mapping' => [
                'verified_practice' => $passed,
                'expert_review' => $passed && \in_array($role, ['platform', 'arbitrator'], true),
                'continuous_update' => true,
                'data_driven' => \in_array($role, ['platform', 'provider'], true),
                'community_bundle' => false,
            ],
            'transaction_authorization' => [
                'passed' => false,
                'reason' => (string) __('尚未绑定具体订单角色和交易动作；不能仅凭 ACL 实证执行付款、退款、交付或仲裁。'),
                'next_required_evidence' => [
                    'order_actor_binding',
                    'runtime_session_matches_actor',
                    'action_specific_guard',
                    'escrow_or_case_state_allows_action',
                ],
            ],
        ];
    }

    private function sourceContract(string $role, array $source): array
    {
        return [
            'role' => $role,
            'source_id' => (string)($source['source_id'] ?? ''),
            'role_label' => (string)($source['role_label'] ?? ''),
            'proof_label' => (string)($source['proof_label'] ?? ''),
            'capability_scope' => self::ROLE_CAPABILITIES[$role] ?? [],
        ];
    }

    private function backendActor(AuthenticatedSessionInterface $session): array
    {
        $userId = (int)($session->getUserId() ?: 0);
        $roleId = 0;
        $isEnabled = null;

        try {
            $rawSession = $session->getSession();
            $roleId = (int)($rawSession->get('backend_acl_role_id') ?: 0);
            $rawEnabled = $rawSession->get('backend_acl_is_enabled');
            $isEnabled = $rawEnabled === null ? null : (bool)((int)$rawEnabled);
        } catch (\Throwable) {
            $roleId = 0;
        }

        if ($userId > 0 && $roleId === 0) {
            try {
                $context = BackendUser::getAclContext($userId);
                if (\is_array($context)) {
                    $roleId = (int)($context['role_id'] ?? 0);
                    $isEnabled = (bool)((int)($context['is_enabled'] ?? 0));
                }
            } catch (\Throwable) {
                $roleId = 0;
            }
        }

        return [
            'authenticated' => $session->isLoggedIn() && $userId > 0,
            'backend_user_id' => $userId > 0 ? $userId : null,
            'backend_role_id' => $roleId > 0 ? $roleId : null,
            'backend_acl_enabled' => $isEnabled,
        ];
    }
}
