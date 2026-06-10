<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

use Weline\Framework\App\Env;

class RuntimeProofTokenService
{
    private const TOKEN_VERSION = 'a2a-runtime-proof-v1';
    private const TTL_SECONDS = 900;

    private const ROLE_SOURCES = [
        'provider' => 'Aiweline_A2A::agent_operator',
        'platform' => 'Aiweline_A2A::platform_risk',
        'arbitrator' => 'Aiweline_A2A::arbitration_panel',
    ];

    public function issue(string $role, string $sourceId, array $backendActor, array $context = []): array
    {
        $role = $this->normalizeRole($role);
        $sourceId = \trim($sourceId);
        $orderPublicId = $this->normalizeOrder((string)($context['order'] ?? ''));
        $action = $this->normalizeAction((string)($context['action'] ?? ''));
        $backendUserId = (int)($backendActor['backend_user_id'] ?? 0);
        $backendRoleId = (int)($backendActor['backend_role_id'] ?? 0);

        if (($backendActor['authenticated'] ?? false) !== true || $backendUserId <= 0 || $sourceId === '') {
            return $this->issueFailure(
                'backend_session_missing',
                __('后台会话未形成订单动作实证票据。'),
                __('需要先通过后台登录和 ACL Source 校验。')
            );
        }

        if ($orderPublicId === '' || $action === '') {
            return $this->issueFailure(
                'order_action_missing',
                __('后台 ACL 实证已通过，但缺少订单或动作上下文。'),
                __('需要从角色控制台的具体动作进入后台实证入口。')
            );
        }

        $expectedSource = self::ROLE_SOURCES[$role] ?? '';
        if ($expectedSource === '' || $expectedSource !== $sourceId) {
            return $this->issueFailure(
                'source_mismatch',
                __('后台 ACL Source 与当前交易角色不匹配。'),
                __('需要使用与当前角色一致的后台实证入口。')
            );
        }

        $issuedAt = \time();
        $expiresAt = $issuedAt + self::TTL_SECONDS;
        $payload = [
            'v' => self::TOKEN_VERSION,
            'role' => $role,
            'source_id' => $sourceId,
            'order' => $orderPublicId,
            'action' => $action,
            'backend_user_id' => $backendUserId,
            'backend_role_id' => $backendRoleId > 0 ? $backendRoleId : null,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'proof_level' => 'backend_acl_order_action_handoff',
        ];

        $payloadSegment = $this->base64UrlEncode(\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        $signature = $this->sign($payloadSegment);
        $token = $payloadSegment . '.' . $signature;

        return [
            'issued' => true,
            'status' => 'runtime_handoff_issued',
            'label' => (string) __('订单动作实证票据已生成'),
            'evidence' => (string) __('后台用户 #%{1} 已为订单 %{2} 的 %{3} 动作生成短期实证票据。', [$backendUserId, $orderPublicId, $action]),
            'gap' => (string) __('无；仍需前台动作守卫校验订单状态和角色边界。'),
            'token' => $token,
            'expires_at' => \date(DATE_ATOM, $expiresAt),
            'ttl_seconds' => self::TTL_SECONDS,
            'frontend_action_url' => $this->frontendActionUrl($role, $orderPublicId, $action, $token),
            'proof_context' => [
                'role' => $role,
                'source_id' => $sourceId,
                'order' => $orderPublicId,
                'action' => $action,
                'backend_user_id' => $backendUserId,
                'backend_role_id' => $backendRoleId > 0 ? $backendRoleId : null,
            ],
        ];
    }

    public function verify(string $token, array $expected): array
    {
        $token = \trim($token);
        if ($token === '') {
            return $this->verificationFailure(
                'runtime_token_missing',
                __('缺少订单动作实证票据'),
                __('需要从后台 ACL 实证入口生成当前订单和动作的短期票据。')
            );
        }

        $segments = \explode('.', $token, 2);
        if (\count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            return $this->verificationFailure(
                'runtime_token_malformed',
                __('订单动作实证票据格式无效'),
                __('请重新从后台 ACL 实证入口生成票据。')
            );
        }

        [$payloadSegment, $signature] = $segments;
        if (!\hash_equals($this->sign($payloadSegment), $signature)) {
            return $this->verificationFailure(
                'runtime_token_signature_invalid',
                __('订单动作实证票据签名无效'),
                __('票据不能被篡改；请重新从后台 ACL 实证入口生成。')
            );
        }

        try {
            $payload = \json_decode($this->base64UrlDecode($payloadSegment), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->verificationFailure(
                'runtime_token_payload_invalid',
                __('订单动作实证票据载荷无效'),
                __('请重新从后台 ACL 实证入口生成票据。')
            );
        }

        if (!\is_array($payload) || (string)($payload['v'] ?? '') !== self::TOKEN_VERSION) {
            return $this->verificationFailure(
                'runtime_token_version_invalid',
                __('订单动作实证票据版本无效'),
                __('请使用当前 A2A 后台实证入口重新生成票据。')
            );
        }

        if ((int)($payload['expires_at'] ?? 0) < \time()) {
            return $this->verificationFailure(
                'runtime_token_expired',
                __('订单动作实证票据已过期'),
                __('请重新生成短期实证票据后再执行敏感动作。')
            );
        }

        $checks = [
            'role' => $this->normalizeRole((string)($expected['role'] ?? '')),
            'source_id' => \trim((string)($expected['source_id'] ?? '')),
            'order' => $this->normalizeOrder((string)($expected['order'] ?? '')),
            'action' => $this->normalizeAction((string)($expected['action'] ?? '')),
        ];
        foreach ($checks as $field => $value) {
            if ($value === '') {
                continue;
            }
            $actual = $field === 'order'
                ? $this->normalizeOrder((string)($payload[$field] ?? ''))
                : ($field === 'action'
                    ? $this->normalizeAction((string)($payload[$field] ?? ''))
                    : \trim((string)($payload[$field] ?? '')));
            if ($actual !== $value) {
                return $this->verificationFailure(
                    'runtime_token_scope_mismatch',
                    __('订单动作实证票据作用域不匹配'),
                    __('票据必须与当前角色、ACL Source、订单和动作完全一致。'),
                    $payload
                );
            }
        }

        return [
            'passed' => true,
            'status' => 'runtime_token_verified',
            'label' => (string) __('订单动作实证票据已通过'),
            'evidence' => (string) __('后台用户 #%{1} 的短期实证票据匹配当前订单 %{2} 和动作 %{3}。', [
                (int)($payload['backend_user_id'] ?? 0),
                (string)($payload['order'] ?? ''),
                (string)($payload['action'] ?? ''),
            ]),
            'gap' => (string) __('无；已进入订单动作守卫校验。'),
            'payload' => $payload,
        ];
    }

    public function proofUrlWithContext(string $proofUrl, string $orderPublicId, string $action): string
    {
        $proofUrl = \trim($proofUrl);
        if ($proofUrl === '') {
            return '';
        }

        return $this->appendQuery($proofUrl, [
            'order' => $this->normalizeOrder($orderPublicId),
            'action' => $this->normalizeAction($action),
        ]);
    }

    private function issueFailure(string $status, string|\Stringable $evidence, string|\Stringable $gap): array
    {
        return [
            'issued' => false,
            'status' => $status,
            'label' => (string) __('订单动作实证票据未生成'),
            'evidence' => (string)$evidence,
            'gap' => (string)$gap,
            'token' => '',
            'expires_at' => '',
            'ttl_seconds' => self::TTL_SECONDS,
            'frontend_action_url' => '',
            'proof_context' => [],
        ];
    }

    private function verificationFailure(
        string $status,
        string|\Stringable $label,
        string|\Stringable $gap,
        array $payload = []
    ): array {
        return [
            'passed' => false,
            'status' => $status,
            'label' => (string)$label,
            'evidence' => (string) __('当前请求未通过订单动作实证票据校验。'),
            'gap' => (string)$gap,
            'payload' => $payload,
        ];
    }

    private function frontendActionUrl(string $role, string $orderPublicId, string $action, string $token): string
    {
        $path = match ($role . ':' . $action) {
            'platform:freeze_funds' => '/a2a/frontend/settlement-case?case=dispute&order=' . \rawurlencode($orderPublicId),
            'platform:monitor_wallet' => '/a2a/frontend/wallet-monitor?order=' . \rawurlencode($orderPublicId) . '&mode=inspect',
            'arbitrator:issue_full_release' => '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($orderPublicId) . '&ruling=full_release',
            'arbitrator:issue_partial_release' => '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($orderPublicId) . '&ruling=partial_release',
            'arbitrator:issue_refund' => '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($orderPublicId) . '&ruling=refund',
            'arbitrator:request_rework' => '/a2a/frontend/arbitration-ruling?order=' . \rawurlencode($orderPublicId) . '&ruling=rework',
            'arbitrator:execute_wallet_dry_run' => '/a2a/frontend/wallet-monitor?order=' . \rawurlencode($orderPublicId) . '&mode=dry_run_execute',
            default => '',
        };

        return $path === '' ? '' : $this->appendQuery($path, ['a2a_runtime_proof' => $token]);
    }

    private function appendQuery(string $url, array $params): string
    {
        $params = \array_filter($params, static fn(mixed $value): bool => \trim((string)$value) !== '');
        if (!$params) {
            return $url;
        }

        $separator = \str_contains($url, '?') ? '&' : '?';

        return $url . $separator . \http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
    }

    private function sign(string $payloadSegment): string
    {
        return $this->base64UrlEncode(\hash_hmac('sha256', $payloadSegment, $this->signingKey(), true));
    }

    private function signingKey(): string
    {
        $configured = \trim((string)(\getenv('GUOLAIREN_A2A_RUNTIME_PROOF_SECRET') ?: ''));
        if ($configured !== '') {
            return $configured;
        }

        try {
            $env = Env::getInstance();
            $configured = \trim((string)$env->getConfig('aiweline_a2a.runtime_proof_secret', ''));
            if ($configured !== '') {
                return $configured;
            }
            $dbPassword = (string)$env->getConfig('db.master.password', '');
            $backendPrefix = (string)$env->getConfig('router.area_routes.backend.prefix', '');

            return \hash('sha256', BP . '|Aiweline_A2A|' . $dbPassword . '|' . $backendPrefix);
        } catch (\Throwable) {
            return \hash('sha256', BP . '|Aiweline_A2A|runtime-proof');
        }
    }

    private function normalizeRole(string $role): string
    {
        return \strtolower(\trim($role));
    }

    private function normalizeOrder(string $orderPublicId): string
    {
        return \strtoupper(\trim($orderPublicId));
    }

    private function normalizeAction(string $action): string
    {
        return \strtolower(\trim($action));
    }

    private function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = \strlen($value) % 4;
        if ($padding > 0) {
            $value .= \str_repeat('=', 4 - $padding);
        }

        return (string)\base64_decode(\strtr($value, '-_', '+/'), true);
    }
}
