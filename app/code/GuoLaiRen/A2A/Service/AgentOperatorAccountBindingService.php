<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;

class AgentOperatorAccountBindingService
{
    private ?BackendUser $backendUserModel = null;

    /**
     * @return array<string, bool|int|string>
     */
    public function resolveForProvider(string $providerKey, string $providerDisplay): array
    {
        $providerKey = \strtolower(\trim($providerKey));
        $providerDisplay = \trim($providerDisplay);
        if ($providerDisplay === '') {
            $providerDisplay = (string) __('Agent 供给方');
        }

        try {
            $backendUser = $this->freshBackendUser()
                ->where(BackendUser::schema_fields_is_deleted, 0)
                ->where(BackendUser::schema_fields_is_enabled, 1)
                ->order(BackendUser::schema_fields_ID)
                ->find()
                ->fetch();
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'fallback_reason' => (string) __('后台运营用户查询失败：%{1}', [$exception->getMessage()]),
            ];
        }

        $backendUserId = (int)$backendUser->getId();
        if ($backendUserId <= 0) {
            return [
                'available' => false,
                'fallback_reason' => (string) __('尚未找到可用的后台运营用户。'),
            ];
        }

        return [
            'available' => true,
            'provider_key' => $providerKey,
            'backend_user_id' => $backendUserId,
            'subject_type' => 'agent_operator_backend_user',
            'subject_reference' => 'backend_user:' . $backendUserId . ':agent:' . $providerKey,
            'subject_display' => $providerDisplay . ' ' . (string) __('后台运营用户 #%{1}', [$backendUserId]),
            'identity_source' => 'agent_operator_backend_user',
            'verification_level' => 'provider_operator_backend_user',
            'identity_readiness' => 'real_account',
            'production_ready' => true,
            'risk_label' => (string) __('已解析到启用的后台运营用户；交易交付、放款和仲裁仍必须通过订单级运行时守卫。'),
            'evidence_label' => (string) __('后台用户 #%{1} 已启用，绑定到 Agent 供给方 %{2}。', [$backendUserId, $providerKey]),
        ];
    }

    private function freshBackendUser(): BackendUser
    {
        $this->backendUserModel ??= ObjectManager::make(BackendUser::class);

        return (clone $this->backendUserModel)->clearData()->clearQuery();
    }
}
