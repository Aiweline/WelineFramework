<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\BackendUserToken;
use Weline\Backend\Service\BackendTokenService;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentDevRelaySession;

/**
 * 线上静默配对：用后台用户 API Token 创建/关闭 Relay 会话（免浏览器 Cookie）。
 */
final class DevRelayPairService
{
    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelaySessionService $sessions,
        private readonly BackendTokenService $tokens,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function pairFromRequest(Request $request, string $localInboundUrl = '', string $outboundMode = ''): array
    {
        if (!$this->gate->isOnlineRelayHost()) {
            throw new \RuntimeException((string) __('当前环境不能作为线上 Webhook Relay 主机。'));
        }

        $user = $this->requireUserFromBearer($request);
        $mode = $outboundMode !== ''
            ? $outboundMode
            : (string) $this->gate->config()['outbound_mode'];

        $payload = $this->sessions->createOnlineSession(
            (int) $user->getId(),
            (string) ($user->getUsername() ?: 'api'),
            $localInboundUrl,
            $mode,
        );

        $sessionCode = (string) $payload['session_code'];
        $relayToken = (string) $payload['token'];

        return [
            'session_code' => $sessionCode,
            'token' => $relayToken,
            'stream_url' => $this->sessions->buildPublicStreamUrl($sessionCode, $relayToken),
            'event_base_url' => $this->sessions->buildPublicEventBaseUrl(),
            'ack_url' => $this->sessions->buildPublicAckUrl(),
            'local_inbound_url' => (string) ($payload['local_inbound_url'] ?? ''),
            'outbound_mode' => (string) ($payload['outbound_mode'] ?? $mode),
            'expires_at' => (string) ($payload['expires_at'] ?? ''),
            'holder_user_id' => (int) $user->getId(),
            'holder_label' => (string) ($user->getUsername() ?: 'api'),
        ];
    }

    public function closeFromRequest(Request $request, string $sessionCode, string $relayToken): void
    {
        if (!$this->gate->isOnlineRelayHost()) {
            throw new \RuntimeException((string) __('当前环境不能作为线上 Webhook Relay 主机。'));
        }
        $this->requireUserFromBearer($request);
        $this->sessions->closeSession($sessionCode, $relayToken);
    }

    public function requireUserFromBearer(Request $request): BackendUser
    {
        $token = $this->extractBearerToken($request);
        if ($token === '') {
            throw new \RuntimeException((string) __('缺少 Authorization Bearer 用户 Token。'));
        }
        $user = $this->tokens->getUserByToken($token);
        if (!$user instanceof BackendUser || !(int) $user->getId()) {
            throw new \RuntimeException((string) __('用户 Token 无效或已过期。'));
        }

        return $user;
    }

    public function extractBearerToken(Request $request): string
    {
        $header = trim((string) $request->getHeaderLine('Authorization'));
        if ($header === '') {
            $header = trim((string) ($request->getServer('HTTP_AUTHORIZATION') ?? ''));
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $match) === 1) {
            return trim((string) $match[1]);
        }
        $alt = trim((string) $request->getHeaderLine('X-Weline-Backend-Token'));
        if ($alt !== '') {
            return $alt;
        }

        return trim((string) $request->getParam('user_token', ''));
    }

    /**
     * 为当前后台用户确保可复制的 API Token（每用户一条）。
     */
    public function ensureUserApiToken(BackendUser $user, int $ttlSeconds = 86400 * 30): string
    {
        $userId = (int) $user->getId();
        if ($userId <= 0) {
            throw new \InvalidArgumentException((string) __('无效的后台用户。'));
        }

        /** @var BackendUserToken $model */
        $model = ObjectManager::getInstance(BackendUserToken::class);
        $model->clear()->where(BackendUserToken::schema_fields_ID, $userId)->find()->fetch();
        if ($model->getId()) {
            $existing = trim((string) $model->getData(BackendUserToken::schema_fields_token));
            $expireRaw = $model->getData('token_expire_time')
                ?? $model->getData('expire_time')
                ?? 0;
            $expire = is_numeric($expireRaw) ? (int) $expireRaw : (int) strtotime((string) $expireRaw);
            if ($existing !== '' && $expire > time() + 3600) {
                return $existing;
            }
        }

        $token = $this->tokens->createApiToken($user, time() + max(3600, $ttlSeconds));
        if ($token === null || $token === '') {
            throw new \RuntimeException((string) __('无法创建用户 API Token。'));
        }

        return $token;
    }
}
