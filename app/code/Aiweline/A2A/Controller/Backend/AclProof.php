<?php

declare(strict_types=1);

namespace Aiweline\A2A\Controller\Backend;

use Aiweline\A2A\Service\BackendAclProofPayloadService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Aiweline_A2A::acl_proof', 'A2A 运行时实证', 'mdi mdi-shield-check-outline', 'A2A 交易运行时实证与权限源注册', 'Weline_Backend::order_group')]
class AclProof extends BackendController
{
    private ?BackendAclProofPayloadService $proofPayloadService = null;

    #[Acl('Aiweline_A2A::acl_proof_index', '查看 A2A 实证源', 'mdi mdi-shield-search', '查看 A2A 运行时实证源状态')]
    public function index(): string
    {
        return $this->fetchJson($this->proofPayloadService()->buildIndex($this->sources(), $this->session) + [
            'surface' => 'a2a_acl_proof',
            'message' => (string) __('A2A 后台 ACL Source 已注册，前台交易动作仍需运行时会话实证。'),
        ]);
    }

    #[Acl('Aiweline_A2A::agent_operator', 'A2A Agent 运营账号', 'mdi mdi-robot-outline', 'Agent 运营账号执行交付与证据提交的后台权限')]
    public function agentOperator(): string
    {
        return $this->proofSource('provider');
    }

    #[Acl('Aiweline_A2A::platform_risk', 'A2A 平台风控', 'mdi mdi-shield-alert-outline', '平台风控复核、冻结、退款复核与钱包监控权限')]
    public function platformRisk(): string
    {
        return $this->proofSource('platform');
    }

    #[Acl('Aiweline_A2A::arbitration_panel', 'A2A 仲裁席位', 'mdi mdi-gavel', '仲裁证据复核与最终裁决签发权限')]
    public function arbitrationPanel(): string
    {
        return $this->proofSource('arbitrator');
    }

    private function proofSource(string $role): string
    {
        $source = $this->sources()[$role] ?? [];

        return $this->fetchJson($this->proofPayloadService()->buildRoleProof($role, $source, $this->session, [
            'order' => (string)($this->request->getParam('order') ?? ''),
            'action' => (string)($this->request->getParam('action') ?? ''),
        ]) + [
            'surface' => 'a2a_acl_proof',
            'role' => $role,
            'source' => $source,
            'message' => (string) __('此后台入口证明 ACL Source 与后台会话，并可为具体订单动作生成短期实证票据；最终是否允许交易动作仍由前台动作守卫判断。'),
        ]);
    }

    private function sources(): array
    {
        return [
            'provider' => [
                'source_id' => 'Aiweline_A2A::agent_operator',
                'role_label' => (string) __('Agent'),
                'proof_label' => (string) __('Agent 运营账号实证'),
            ],
            'platform' => [
                'source_id' => 'Aiweline_A2A::platform_risk',
                'role_label' => (string) __('平台风控'),
                'proof_label' => (string) __('平台风控 ACL 实证'),
            ],
            'arbitrator' => [
                'source_id' => 'Aiweline_A2A::arbitration_panel',
                'role_label' => (string) __('仲裁员'),
                'proof_label' => (string) __('仲裁席位 ACL 实证'),
            ],
        ];
    }

    private function proofPayloadService(): BackendAclProofPayloadService
    {
        $this->proofPayloadService ??= ObjectManager::getInstance(BackendAclProofPayloadService::class);

        return $this->proofPayloadService;
    }
}
