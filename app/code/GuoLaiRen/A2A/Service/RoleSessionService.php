<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Service;

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

class RoleSessionService
{
    private const SESSION_ROLE_KEY = 'guolairen_a2a_actor_role';
    private const SESSION_UPDATED_AT_KEY = 'guolairen_a2a_actor_role_updated_at';

    private const ROLE_BUYER = 'buyer';
    private const ROLE_PROVIDER = 'provider';
    private const ROLE_PLATFORM = 'platform';
    private const ROLE_ARBITRATOR = 'arbitrator';

    public function resolveActor(
        AuthenticatedSessionInterface $session,
        string $requestedRole = '',
        bool $switchRequested = false
    ): array {
        $labels = $this->roleLabels();
        $requestedRole = \strtolower(\trim($requestedRole));
        $storedRole = \strtolower(\trim((string)($session->get(self::SESSION_ROLE_KEY) ?? '')));
        $source = 'session';
        $notes = [];

        if ($switchRequested) {
            if (!isset($labels[$requestedRole])) {
                throw new \InvalidArgumentException((string) __('角色不存在或尚未接入 A2A 权限策略。'));
            }

            $storedRole = $requestedRole;
            $session->set(self::SESSION_ROLE_KEY, $storedRole);
            $session->set(self::SESSION_UPDATED_AT_KEY, \date('Y-m-d H:i:s'));
            $source = 'switch';
            $notes[] = \sprintf((string) __('已切换为 %s 会话角色。'), (string)$labels[$storedRole]);
        }

        if ($storedRole === '' || !isset($labels[$storedRole])) {
            $storedRole = self::ROLE_BUYER;
            $session->set(self::SESSION_ROLE_KEY, $storedRole);
            $session->set(self::SESSION_UPDATED_AT_KEY, \date('Y-m-d H:i:s'));
            $source = 'default';
            $notes[] = (string) __('当前会话未选择角色，已使用买方作为默认安全角色。');
        }

        $roleParamIgnored = !$switchRequested && $requestedRole !== '' && $requestedRole !== $storedRole;
        if ($roleParamIgnored) {
            $notes[] = isset($labels[$requestedRole])
                ? (string) __('请求的 URL role 参数与会话角色不一致，已按会话角色执行。')
                : (string) __('URL role 参数未接入角色策略，已按会话角色执行。');
            $source = 'session_ignored_param';
        }

        return [
            'role' => $storedRole,
            'role_label' => (string)$labels[$storedRole],
            'source' => $source,
            'source_label' => $this->formatSourceLabel($source),
            'requested_role' => $requestedRole,
            'role_param_ignored' => $roleParamIgnored,
            'switch_requested' => $switchRequested,
            'updated_at' => (string)($session->get(self::SESSION_UPDATED_AT_KEY) ?? ''),
            'is_logged_in' => $session->isLoggedIn(),
            'identity_id' => (string)($session->getUserId() ?? ''),
            'identity_label' => $session->isLoggedIn() ? (string) __('已登录账户') : (string) __('前台访客会话'),
            'session_key' => self::SESSION_ROLE_KEY,
            'notes' => $notes,
        ];
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

    private function formatSourceLabel(string $source): string
    {
        return match ($source) {
            'switch' => (string) __('会话角色已保存'),
            'default' => (string) __('默认买方会话'),
            'session_ignored_param' => (string) __('URL role 参数已忽略'),
            default => (string) __('A2A 会话角色'),
        };
    }
}
