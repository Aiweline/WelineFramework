<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

use Aiweline\A2A\Model\TradeActorAssignment;

class TradeActorIdentityResolutionService
{
    private const ROLE_BUYER = 'buyer';
    private const ROLE_PROVIDER = 'provider';
    private const ROLE_PLATFORM = 'platform';
    private const ROLE_ARBITRATOR = 'arbitrator';

    private const READINESS_UNBOUND = 'unbound';
    private const READINESS_PROTOTYPE = 'prototype_only';
    private const READINESS_CONTRACT = 'contract_ready';
    private const READINESS_REAL = 'real_account';

    public function __construct(
        private readonly ?AgentOperatorAccountBindingService $operatorAccountBindingService = null
    ) {
    }

    public function resolveBindingSubject(string $role, TradeActorAssignment $assignment, array $actor): array
    {
        $role = \strtolower(\trim($role));
        $identityId = \strtolower(\trim((string)($actor['identity_id'] ?? '')));
        $isLoggedIn = ($actor['is_logged_in'] ?? false) === true && $identityId !== '';
        $actorDisplay = (string)$assignment->getData(TradeActorAssignment::schema_fields_ACTOR_DISPLAY);
        $actorReference = (string)$assignment->getData(TradeActorAssignment::schema_fields_ACTOR_REFERENCE);

        return match ($role) {
            self::ROLE_BUYER => $isLoggedIn
                ? $this->buildSubject(
                    'customer_account',
                    'customer:' . $identityId,
                    (string) __('已登录买方账号 #%{1}', [$identityId]),
                    'frontend_customer_session',
                    'real_customer_session',
                    self::READINESS_REAL,
                    true
                )
                : $this->buildSubject(
                    'customer_account',
                    'prototype:buyer-account',
                    (string) __('演示买方账号'),
                    'prototype_session_claim',
                    'prototype_session_claim',
                    self::READINESS_PROTOTYPE,
                    false
                ),
            self::ROLE_PROVIDER => $this->resolveProviderSubject($actorReference, $actorDisplay),
            self::ROLE_PLATFORM => $this->buildSubject(
                'backend_acl_group',
                'acl:a2a-platform-risk',
                (string) __('A2A 平台风控权限组'),
                'backend_acl_contract',
                'backend_acl_contract',
                self::READINESS_CONTRACT,
                false
            ),
            self::ROLE_ARBITRATOR => $this->buildSubject(
                'backend_acl_group',
                'acl:a2a-arbitration-panel',
                (string) __('A2A 仲裁权限组'),
                'backend_acl_contract',
                'backend_acl_contract',
                self::READINESS_CONTRACT,
                false
            ),
            default => $this->buildSubject(
                'prototype_actor',
                'prototype:' . $role,
                (string) __('原型角色主体'),
                'prototype_session_claim',
                'prototype_session_claim',
                self::READINESS_PROTOTYPE,
                false
            ),
        };
    }

    private function resolveProviderSubject(string $actorReference, string $actorDisplay): array
    {
        $binding = $this->operatorAccountBindingService()
            ->resolveForProvider($actorReference, $actorDisplay);
        if (!empty($binding['available'])) {
            $subject = $this->buildSubject(
                (string)$binding['subject_type'],
                (string)$binding['subject_reference'],
                (string)$binding['subject_display'],
                (string)$binding['identity_source'],
                (string)$binding['verification_level'],
                (string)$binding['identity_readiness'],
                (bool)$binding['production_ready']
            );
            $subject['risk_label'] = (string)$binding['risk_label'];
            $subject['evidence_label'] = (string)$binding['evidence_label'];
            $subject['operator_provider_key'] = (string)$binding['provider_key'];
            $subject['operator_backend_user_id'] = (int)$binding['backend_user_id'];

            return $subject;
        }

        $subject = $this->buildSubject(
            'agent_operator_account',
            'agent:' . $actorReference . ':operator',
            $actorDisplay !== '' ? $actorDisplay . ' ' . (string) __('运营账号') : (string) __('Agent 运营账号'),
            'agent_operator_contract',
            'provider_operator_contract',
            self::READINESS_CONTRACT,
            false
        );
        if (!empty($binding['fallback_reason'])) {
            $subject['evidence_label'] = (string)$binding['fallback_reason'];
        }

        return $subject;
    }

    public function summarizeAssignment(TradeActorAssignment $assignment, array $metadata): array
    {
        $bindingEvent = \is_array($metadata['binding_event'] ?? null) ? $metadata['binding_event'] : [];
        $bindingStatus = (string)$assignment->getData(TradeActorAssignment::schema_fields_AUTH_BINDING_STATUS);
        $boundSubjectType = (string)$assignment->getData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_TYPE);
        $boundSubjectReference = (string)$assignment->getData(TradeActorAssignment::schema_fields_BOUND_SUBJECT_REFERENCE);
        $verificationLevel = (string)$assignment->getData(TradeActorAssignment::schema_fields_VERIFICATION_LEVEL);

        $readiness = (string)($bindingEvent['identity_readiness'] ?? '');
        if ($readiness === '') {
            $readiness = $this->inferReadiness($bindingStatus, $boundSubjectType, $boundSubjectReference, $verificationLevel);
        }

        $identitySource = (string)($bindingEvent['identity_source'] ?? '');
        if ($identitySource === '') {
            $identitySource = $this->inferSource($readiness, $boundSubjectType);
        }

        $productionReady = \array_key_exists('production_ready', $bindingEvent)
            ? (bool)$bindingEvent['production_ready']
            : $readiness === self::READINESS_REAL;

        return [
            'identity_source' => $identitySource,
            'identity_source_label' => $this->formatIdentitySource($identitySource),
            'identity_readiness' => $readiness,
            'identity_readiness_label' => $this->formatIdentityReadiness($readiness),
            'identity_risk_label' => (string)($bindingEvent['risk_label'] ?? $this->formatIdentityRisk($readiness)),
            'identity_evidence_label' => (string)($bindingEvent['evidence_label'] ?? $this->formatIdentityEvidence($readiness, $identitySource)),
            'production_ready' => $productionReady,
        ];
    }

    public function formatIdentityReadiness(string $readiness): string
    {
        return match ($readiness) {
            self::READINESS_REAL => (string) __('真实账号已解析'),
            self::READINESS_CONTRACT => (string) __('权限契约待实证'),
            self::READINESS_PROTOTYPE => (string) __('仅原型绑定'),
            default => (string) __('待绑定身份'),
        };
    }

    public function formatIdentitySource(string $source): string
    {
        return match ($source) {
            'frontend_customer_session' => (string) __('前台客户会话'),
            'agent_operator_backend_user' => (string) __('Agent 运营后台用户'),
            'agent_operator_contract' => (string) __('Agent 运营契约'),
            'backend_acl_contract' => (string) __('后台 ACL 契约'),
            'prototype_session_claim' => (string) __('原型会话声明'),
            default => (string) __('未解析'),
        };
    }

    private function buildSubject(
        string $subjectType,
        string $subjectReference,
        string $subjectDisplay,
        string $identitySource,
        string $verificationLevel,
        string $readiness,
        bool $productionReady
    ): array {
        return [
            'subject_type' => $subjectType,
            'subject_reference' => $subjectReference,
            'subject_display' => $subjectDisplay,
            'identity_source' => $identitySource,
            'verification_level' => $verificationLevel,
            'identity_readiness' => $readiness,
            'identity_readiness_label' => $this->formatIdentityReadiness($readiness),
            'identity_source_label' => $this->formatIdentitySource($identitySource),
            'risk_label' => $this->formatIdentityRisk($readiness),
            'evidence_label' => $this->formatIdentityEvidence($readiness, $identitySource),
            'production_ready' => $productionReady,
        ];
    }

    private function inferReadiness(
        string $bindingStatus,
        string $boundSubjectType,
        string $boundSubjectReference,
        string $verificationLevel
    ): string {
        if ($bindingStatus !== TradeActorAssignment::BINDING_ACCOUNT_BOUND || $boundSubjectReference === '') {
            return self::READINESS_UNBOUND;
        }
        if (\str_starts_with($boundSubjectReference, 'prototype:') || $verificationLevel === 'prototype_session_claim') {
            return self::READINESS_PROTOTYPE;
        }
        if ($boundSubjectType === 'customer_account' && \str_starts_with($boundSubjectReference, 'customer:')) {
            return self::READINESS_REAL;
        }
        if ($boundSubjectType === 'agent_operator_backend_user' && \str_starts_with($boundSubjectReference, 'backend_user:')) {
            return self::READINESS_REAL;
        }
        if (\in_array($boundSubjectType, ['agent_operator_account', 'backend_acl_group'], true)) {
            return self::READINESS_CONTRACT;
        }

        return self::READINESS_PROTOTYPE;
    }

    private function inferSource(string $readiness, string $boundSubjectType): string
    {
        if ($readiness === self::READINESS_REAL) {
            if ($boundSubjectType === 'agent_operator_backend_user') {
                return 'agent_operator_backend_user';
            }
            return 'frontend_customer_session';
        }
        if ($readiness === self::READINESS_PROTOTYPE) {
            return 'prototype_session_claim';
        }
        if ($boundSubjectType === 'agent_operator_account') {
            return 'agent_operator_contract';
        }
        if ($boundSubjectType === 'backend_acl_group') {
            return 'backend_acl_contract';
        }

        return '';
    }

    private function formatIdentityRisk(string $readiness): string
    {
        return match ($readiness) {
            self::READINESS_REAL => (string) __('真实账号会话已解析，可进入生产校验链。'),
            self::READINESS_CONTRACT => (string) __('已有权限契约，但仍需接入 Weline ACL 实证。'),
            self::READINESS_PROTOTYPE => (string) __('仅可用于演示，不能作为生产放款或仲裁依据。'),
            default => (string) __('尚未绑定身份主体。'),
        };
    }

    private function formatIdentityEvidence(string $readiness, string $source): string
    {
        if ($readiness === self::READINESS_UNBOUND) {
            return (string) __('无身份解析证据');
        }
        if ($source !== '') {
            return $this->formatIdentitySource($source);
        }

        return (string) __('身份解析证据待补齐');
    }

    private function operatorAccountBindingService(): AgentOperatorAccountBindingService
    {
        return $this->operatorAccountBindingService ?? new AgentOperatorAccountBindingService();
    }
}
