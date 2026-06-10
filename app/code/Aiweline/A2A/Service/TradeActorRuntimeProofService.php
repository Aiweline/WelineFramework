<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

use Aiweline\A2A\Model\TradeActorAssignment;
use Weline\Acl\Model\Acl;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

class TradeActorRuntimeProofService
{
    private const ROLE_BUYER = 'buyer';
    private const ROLE_PROVIDER = 'provider';
    private const ROLE_PLATFORM = 'platform';
    private const ROLE_ARBITRATOR = 'arbitrator';

    private const ACL_SOURCES = [
        self::ROLE_PROVIDER => 'Aiweline_A2A::agent_operator',
        self::ROLE_PLATFORM => 'Aiweline_A2A::platform_risk',
        self::ROLE_ARBITRATOR => 'Aiweline_A2A::arbitration_panel',
    ];

    private const ACL_PROOF_ROUTES = [
        'Aiweline_A2A::agent_operator' => 'a2a/backend/acl-proof/agent-operator',
        'Aiweline_A2A::platform_risk' => 'a2a/backend/acl-proof/platform-risk',
        'Aiweline_A2A::arbitration_panel' => 'a2a/backend/acl-proof/arbitration-panel',
    ];

    private ?Acl $aclModel = null;
    private ?Url $urlBuilder = null;

    public function summarizeProof(TradeActorAssignment $assignment, array $metadata, array $actor): array
    {
        $bindingStatus = (string)$assignment->getData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS);
        $role = \strtolower((string)$assignment->getData(TradeActorAssignment::schema_fields_ROLE_CODE));
        $boundSubjectType = (string)$assignment->getData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_TYPE);
        $boundSubjectReference = (string)$assignment->getData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_REFERENCE);
        $verificationLevel = (string)$assignment->getData(TradeActorAssignment::schema_fields_VERIFICATION_LEVEL);
        $bindingEvent = \is_array($metadata['binding_event'] ?? null) ? $metadata['binding_event'] : [];
        $identityReadiness = (string)($bindingEvent['identity_readiness'] ?? '');
        $identitySource = (string)($bindingEvent['identity_source'] ?? '');

        if ($bindingStatus !== TradeActorAssignment::BINDING_ACCOUNT_BOUND || $boundSubjectReference === '') {
            return $this->buildProof(
                'not_bound',
                (string) __('未绑定'),
                (string) __('无运行时实证'),
                (string) __('需要先绑定订单角色。'),
                true,
                false,
                $this->aclSourceForRole($role),
                ''
            );
        }

        if (
            $role === self::ROLE_BUYER
            && $boundSubjectType === 'customer_account'
            && \str_starts_with($boundSubjectReference, 'customer:')
            && $this->actorMatchesBoundSubject($actor, $boundSubjectReference)
        ) {
            return $this->buildProof(
                'proof_passed',
                (string) __('运行时已实证'),
                (string) __('当前前台客户会话与订单买方绑定主体一致。'),
                (string) __('无'),
                true,
                true,
                '',
                'frontend_customer_session'
            );
        }

        if ($role === self::ROLE_BUYER && ($identityReadiness === 'real_account' || $verificationLevel === 'real_customer_session')) {
            return $this->buildProof(
                'session_mismatch',
                (string) __('会话未匹配'),
                (string) __('绑定主体是真实买方账号，但当前请求没有证明同一会话。'),
                (string) __('需要用同一买方账号登录后再执行生产动作。'),
                true,
                false,
                '',
                $identitySource !== '' ? $identitySource : 'frontend_customer_session'
            );
        }

        if ($role === self::ROLE_PROVIDER && $boundSubjectType === 'agent_operator_backend_user') {
            return $this->buildProof(
                'operator_account_bound',
                (string) __('Agent 运营账号已绑定'),
                (string) __('Agent 供给方已解析到启用后台运营用户；当前前台请求仍不能证明该后台用户正在执行订单动作。'),
                (string) __('需要把后台运营用户会话、Agent ACL Source 和订单动作上下文一起接入运行时守卫。'),
                true,
                false,
                $this->aclSourceForRole(self::ROLE_PROVIDER),
                'agent_operator_backend_user'
            );
        }

        if ($role === self::ROLE_PROVIDER || $boundSubjectType === 'agent_operator_account') {
            return $this->buildProof(
                'operator_acl_missing',
                (string) __('Agent 运营账号待接入'),
                (string) __('当前只有 Agent 运营契约，没有真实运营账号或 ACL 运行时校验。'),
                (string) __('需要接入 Agent 运营账号或后台 ACL Source：%{1}', [$this->aclSourceForRole(self::ROLE_PROVIDER)]),
                true,
                false,
                $this->aclSourceForRole(self::ROLE_PROVIDER),
                'agent_operator_contract'
            );
        }

        if ($role === self::ROLE_PLATFORM || $role === self::ROLE_ARBITRATOR || $boundSubjectType === 'backend_acl_group') {
            return $this->buildProof(
                'acl_runtime_missing',
                (string) __('后台 ACL 待接入'),
                (string) __('当前页面是前台请求，不能证明后台 ACL 会话或角色权限。'),
                (string) __('需要后台 ACL Source：%{1} 并通过后台会话校验。', [$this->aclSourceForRole($role)]),
                true,
                false,
                $this->aclSourceForRole($role),
                'backend_acl_contract'
            );
        }

        if ($identityReadiness === 'prototype_only' || \str_starts_with($boundSubjectReference, 'prototype:')) {
            return $this->buildProof(
                'proof_missing',
                (string) __('缺少运行时实证'),
                (string) __('当前为原型会话声明，不能作为生产授权证据。'),
                (string) __('需要真实登录账号、Agent 运营账号或后台 ACL 校验。'),
                true,
                false,
                $this->aclSourceForRole($role),
                'prototype_session_claim'
            );
        }

        return $this->buildProof(
            'proof_missing',
            (string) __('缺少运行时实证'),
            (string) __('绑定主体存在，但当前请求尚未提供可验证运行时证明。'),
            (string) __('需要补齐与角色匹配的会话、账号或 ACL 证据。'),
            true,
            false,
            $this->aclSourceForRole($role),
            $identitySource
        );
    }

    public function fallbackProof(string $role): array
    {
        return $this->buildProof(
            'not_bound',
            (string) __('未绑定'),
            (string) __('无运行时实证'),
            (string) __('需要先绑定订单角色。'),
            true,
            false,
            $this->aclSourceForRole(\strtolower($role)),
            ''
        );
    }

    private function actorMatchesBoundSubject(array $actor, string $boundSubjectReference): bool
    {
        if (($actor['is_logged_in'] ?? false) !== true) {
            return false;
        }
        $identityId = \strtolower(\trim((string)($actor['identity_id'] ?? '')));
        if ($identityId === '') {
            return false;
        }

        return \strtolower($boundSubjectReference) === 'customer:' . $identityId;
    }

    private function aclSourceForRole(string $role): string
    {
        return self::ACL_SOURCES[$role] ?? '';
    }

    private function buildProof(
        string $status,
        string $label,
        string $evidence,
        string $gap,
        bool $required,
        bool $passed,
        string $aclSource,
        string $source
    ): array {
        $aclRegistration = $this->summarizeAclRegistration($aclSource);

        return [
            'runtime_proof_status' => $status,
            'runtime_proof_label' => $label,
            'runtime_proof_evidence' => $evidence,
            'runtime_proof_gap' => $gap,
            'runtime_proof_required' => $required,
            'runtime_proof_passed' => $passed,
            'runtime_acl_source' => $aclSource,
            'runtime_acl_registered' => $aclRegistration['registered'],
            'runtime_acl_registration_label' => $aclRegistration['label'],
            'runtime_acl_registration_evidence' => $aclRegistration['evidence'],
            'runtime_acl_proof_route' => $aclRegistration['proof_route'],
            'runtime_acl_proof_url' => $aclRegistration['proof_url'],
            'runtime_proof_source' => $source,
        ];
    }

    private function summarizeAclRegistration(string $aclSource): array
    {
        if ($aclSource === '') {
            return [
                'registered' => false,
                'label' => (string) __('无 ACL 来源'),
                'evidence' => (string) __('当前角色不需要后台 ACL Source。'),
                'proof_route' => '',
                'proof_url' => '',
            ];
        }

        $proofRoute = $this->aclProofRoute($aclSource);
        $proofUrl = $this->backendProofUrl($proofRoute);

        try {
            $acl = $this->freshAclModel()->load(Acl::schema_fields_SOURCE_ID, $aclSource);
            if ($acl->getSourceId() === $aclSource) {
                return [
                    'registered' => true,
                    'label' => (string) __('ACL Source 已注册'),
                    'evidence' => (string) __('Weline ACL 表已存在 Source：%{1}', [$aclSource]),
                    'proof_route' => $proofRoute,
                    'proof_url' => $proofUrl,
                ];
            }
        } catch (\Throwable $exception) {
            return [
                'registered' => false,
                'label' => (string) __('ACL Source 查询失败'),
                'evidence' => (string) __('查询 ACL Source 时发生错误：%{1}', [$exception->getMessage()]),
                'proof_route' => $proofRoute,
                'proof_url' => $proofUrl,
            ];
        }

        return [
            'registered' => false,
            'label' => (string) __('ACL Source 未注册'),
            'evidence' => (string) __('Weline ACL 表未找到 Source：%{1}', [$aclSource]),
            'proof_route' => $proofRoute,
            'proof_url' => $proofUrl,
        ];
    }

    private function aclProofRoute(string $aclSource): string
    {
        return self::ACL_PROOF_ROUTES[$aclSource] ?? '';
    }

    private function backendProofUrl(string $proofRoute): string
    {
        if ($proofRoute === '') {
            return '';
        }

        try {
            $this->urlBuilder ??= ObjectManager::getInstance(Url::class);

            return $this->urlBuilder->getBackendUrl($proofRoute);
        } catch (\Throwable) {
            return '';
        }
    }

    private function freshAclModel(): Acl
    {
        $this->aclModel ??= ObjectManager::getInstance(Acl::class);

        return (clone $this->aclModel)->clearData()->clearQuery();
    }
}
