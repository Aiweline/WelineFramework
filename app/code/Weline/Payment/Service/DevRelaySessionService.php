<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentDevRelaySession;

final class DevRelaySessionService
{
    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelayTokenService $tokens,
        private readonly Url $url,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function createOnlineSession(
        int $holderUserId,
        string $holderLabel,
        string $localInboundUrl = '',
        string $outboundMode = PaymentDevRelaySession::OUTBOUND_LOCAL_DIRECT,
    ): array {
        if (!$this->gate->isOnlineRelayHost()) {
            throw new \RuntimeException((string) __('当前环境不能作为线上 Webhook Relay 主机。'));
        }

        $this->closeActiveSessions(PaymentDevRelaySession::ROLE_ONLINE);

        $sessionCode = 'drs_' . bin2hex(random_bytes(12));
        $token = $this->tokens->generateToken();
        $ttl = (int) $this->gate->config()['session_ttl_seconds'];
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
        $streamUrl = $this->buildStreamUrl($sessionCode, $token);
        $consoleUrl = $this->buildConsoleUrl($sessionCode, $token);

        $model = $this->newSessionModel();
        $model->setData([
            PaymentDevRelaySession::schema_fields_SESSION_CODE => $sessionCode,
            PaymentDevRelaySession::schema_fields_RELAY_TOKEN_HASH => $this->tokens->hashToken($token),
            PaymentDevRelaySession::schema_fields_ROLE => PaymentDevRelaySession::ROLE_ONLINE,
            PaymentDevRelaySession::schema_fields_LOCAL_INBOUND_URL => trim($localInboundUrl),
            PaymentDevRelaySession::schema_fields_ONLINE_STREAM_URL => $streamUrl,
            PaymentDevRelaySession::schema_fields_OUTBOUND_MODE => $this->normalizeOutboundMode($outboundMode),
            PaymentDevRelaySession::schema_fields_HOLDER_USER_ID => $holderUserId,
            PaymentDevRelaySession::schema_fields_HOLDER_LABEL => $holderLabel,
            PaymentDevRelaySession::schema_fields_STATUS => PaymentDevRelaySession::STATUS_ACTIVE,
            PaymentDevRelaySession::schema_fields_LAST_EVENT_SEQ => 0,
            PaymentDevRelaySession::schema_fields_EXPIRES_AT => $expiresAt,
            PaymentDevRelaySession::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        return $this->sessionPayload($model, $token, $streamUrl, $consoleUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function bindLocalSession(
        string $sessionCode,
        string $token,
        string $onlineStreamUrl = '',
    ): array {
        if (!$this->gate->canOpenUi() || !$this->gate->isLocalEnvironment()) {
            throw new \RuntimeException((string) __('当前环境不能绑定本地 Relay 会话。'));
        }

        $sessionCode = trim($sessionCode);
        if ($sessionCode === '' || trim($token) === '') {
            throw new \InvalidArgumentException((string) __('请提供 session_code 与 token。'));
        }

        $this->closeActiveSessions(PaymentDevRelaySession::ROLE_LOCAL);

        $localInboundUrl = $this->buildLocalInboundUrl($sessionCode, $token);
        $model = $this->newSessionModel();
        $model->setData([
            PaymentDevRelaySession::schema_fields_SESSION_CODE => $sessionCode,
            PaymentDevRelaySession::schema_fields_RELAY_TOKEN_HASH => $this->tokens->hashToken($token),
            PaymentDevRelaySession::schema_fields_ROLE => PaymentDevRelaySession::ROLE_LOCAL,
            PaymentDevRelaySession::schema_fields_LOCAL_INBOUND_URL => $localInboundUrl,
            PaymentDevRelaySession::schema_fields_ONLINE_STREAM_URL => trim($onlineStreamUrl),
            PaymentDevRelaySession::schema_fields_OUTBOUND_MODE => PaymentDevRelaySession::OUTBOUND_LOCAL_DIRECT,
            PaymentDevRelaySession::schema_fields_STATUS => PaymentDevRelaySession::STATUS_ACTIVE,
            PaymentDevRelaySession::schema_fields_LAST_EVENT_SEQ => 0,
            PaymentDevRelaySession::schema_fields_EXPIRES_AT => date('Y-m-d H:i:s', time() + (int) $this->gate->config()['session_ttl_seconds']),
            PaymentDevRelaySession::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ])->save();

        return $this->sessionPayload($model, $token, trim($onlineStreamUrl), '', $localInboundUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateOnlineLocalInboundUrl(string $sessionCode, string $token, string $localInboundUrl): array
    {
        $session = $this->requireActiveSession($sessionCode, $token, PaymentDevRelaySession::ROLE_ONLINE);
        $session->setData(PaymentDevRelaySession::schema_fields_LOCAL_INBOUND_URL, trim($localInboundUrl))
            ->setData(PaymentDevRelaySession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $this->sessionPayload($session, $token, (string) $session->getData(PaymentDevRelaySession::schema_fields_ONLINE_STREAM_URL));
    }

    public function closeSession(string $sessionCode, string $token): void
    {
        $session = $this->requireActiveSession($sessionCode, $token);
        $session->setData(PaymentDevRelaySession::schema_fields_STATUS, PaymentDevRelaySession::STATUS_CLOSED)
            ->setData(PaymentDevRelaySession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();
    }

    public function findActiveOnlineSession(): ?PaymentDevRelaySession
    {
        return $this->findActiveByRole(PaymentDevRelaySession::ROLE_ONLINE);
    }

    public function findActiveLocalSession(): ?PaymentDevRelaySession
    {
        return $this->findActiveByRole(PaymentDevRelaySession::ROLE_LOCAL);
    }

    public function requireActiveSession(
        string $sessionCode,
        string $token,
        ?string $role = null,
    ): PaymentDevRelaySession {
        $session = $this->loadSession($sessionCode);
        if ($session === null) {
            throw new \RuntimeException((string) __('Relay 会话不存在。'));
        }
        if ((string) $session->getData(PaymentDevRelaySession::schema_fields_STATUS) !== PaymentDevRelaySession::STATUS_ACTIVE) {
            throw new \RuntimeException((string) __('Relay 会话未激活。'));
        }
        if (!$this->tokens->verifyToken($token, (string) $session->getData(PaymentDevRelaySession::schema_fields_RELAY_TOKEN_HASH))) {
            throw new \RuntimeException((string) __('Relay token 无效。'));
        }
        $expiresAt = strtotime((string) $session->getData(PaymentDevRelaySession::schema_fields_EXPIRES_AT));
        if ($expiresAt !== false && $expiresAt < time()) {
            throw new \RuntimeException((string) __('Relay 会话已过期。'));
        }
        if ($role !== null && (string) $session->getData(PaymentDevRelaySession::schema_fields_ROLE) !== $role) {
            throw new \RuntimeException((string) __('Relay 会话角色不匹配。'));
        }

        return $session;
    }

    public function buildStreamUrl(string $sessionCode, string $token): string
    {
        return $this->url->getBackendUrl('payment/backend/dev-relay/stream', [
            'session_code' => $sessionCode,
            'token' => $token,
        ]);
    }

    public function buildConsoleUrl(string $sessionCode, string $token): string
    {
        return $this->url->getBackendUrl('payment/backend/dev-relay/console', [
            'session_code' => $sessionCode,
            'token' => $token,
        ]);
    }

    public function buildLocalInboundUrl(string $sessionCode, string $token): string
    {
        $publicOrigin = ObjectManager::getInstance(PayPalSandboxPublicOriginService::class)->resolvePublicOrigin();
        if ($publicOrigin !== '') {
            return rtrim($publicOrigin, '/') . '/payment/dev-relay/inbound?'
                . http_build_query(['session_code' => $sessionCode, 'token' => $token]);
        }

        return $this->url->getUrl('payment/dev-relay/inbound', [
            'session_code' => $sessionCode,
            'token' => $token,
        ]);
    }

    public function buildEventFetchUrl(string $eventCode, string $sessionCode, string $token): string
    {
        return $this->buildPublicEventFetchUrl($eventCode, $sessionCode, $token);
    }

    /**
     * 公网可达（免后台 Cookie）SSE Stream，供本机静默 worker 使用。
     */
    public function buildPublicStreamUrl(string $sessionCode, string $token): string
    {
        return $this->buildAbsolutePublicPath('/payment/dev-relay/stream', [
            'session_code' => $sessionCode,
            'token' => $token,
        ]);
    }

    public function buildPublicEventFetchUrl(string $eventCode, string $sessionCode, string $token): string
    {
        return $this->buildAbsolutePublicPath('/payment/dev-relay/event', [
            'event_code' => $eventCode,
            'session_code' => $sessionCode,
            'token' => $token,
        ]);
    }

    public function buildPublicAckUrl(): string
    {
        return $this->buildAbsolutePublicPath('/payment/dev-relay/ack');
    }

    public function buildPublicEventBaseUrl(): string
    {
        return $this->buildAbsolutePublicPath('/payment/dev-relay/event');
    }

    public function buildPublicPairUrl(): string
    {
        return $this->buildAbsolutePublicPath('/payment/dev-relay/pair');
    }

    public function buildPublicCloseUrl(): string
    {
        return $this->buildAbsolutePublicPath('/payment/dev-relay/close');
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function buildAbsolutePublicPath(string $path, array $query = []): string
    {
        $origin = $this->resolveOnlinePublicOrigin();
        $path = '/' . ltrim($path, '/');
        $url = $origin !== '' ? (rtrim($origin, '/') . $path) : $this->url->getUrl(ltrim($path, '/'));
        if ($query === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    private function resolveOnlinePublicOrigin(): string
    {
        $configured = rtrim(trim((string) ($this->gate->config()['online_base_url'] ?? '')), '/');
        if ($configured !== '') {
            return $configured;
        }

        $origin = ObjectManager::getInstance(PayPalSandboxPublicOriginService::class)->resolvePublicOrigin();
        $origin = rtrim(trim($origin), '/');
        if ($origin === '') {
            return '';
        }

        // Strip accidental internal Worker listen ports from absolute origins.
        $parts = parse_url($origin);
        if (!\is_array($parts) || empty($parts['host'])) {
            return $origin;
        }
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'http' ? 80 : 443;
        if ($port !== null && $port !== $defaultPort && $port >= 10000) {
            $port = null;
        }
        $authority = $host . ($port !== null && $port !== $defaultPort ? ':' . $port : '');

        return $scheme . '://' . $authority;
    }

    private function closeActiveSessions(string $role): void
    {
        $model = $this->newSessionModel();
        $rows = $model->reset()
            ->where(PaymentDevRelaySession::schema_fields_ROLE, $role)
            ->where(PaymentDevRelaySession::schema_fields_STATUS, PaymentDevRelaySession::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $code = (string) ($row[PaymentDevRelaySession::schema_fields_SESSION_CODE] ?? '');
            if ($code === '') {
                continue;
            }
            $this->newSessionModel()
                ->where(PaymentDevRelaySession::schema_fields_SESSION_CODE, $code)
                ->find()
                ->fetch()
                ->setData(PaymentDevRelaySession::schema_fields_STATUS, PaymentDevRelaySession::STATUS_CLOSED)
                ->setData(PaymentDevRelaySession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();
        }
    }

    private function findActiveByRole(string $role): ?PaymentDevRelaySession
    {
        $model = $this->newSessionModel();
        $model->where(PaymentDevRelaySession::schema_fields_ROLE, $role)
            ->where(PaymentDevRelaySession::schema_fields_STATUS, PaymentDevRelaySession::STATUS_ACTIVE)
            ->order(PaymentDevRelaySession::schema_fields_CREATED_AT, 'DESC')
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    private function loadSession(string $sessionCode): ?PaymentDevRelaySession
    {
        $model = $this->newSessionModel();
        $model->where(PaymentDevRelaySession::schema_fields_SESSION_CODE, trim($sessionCode))
            ->find()
            ->fetch();

        return $model->getId() ? $model : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(
        PaymentDevRelaySession $session,
        string $token,
        string $streamUrl = '',
        string $consoleUrl = '',
        string $localInboundUrl = '',
    ): array {
        return [
            'session_code' => (string) $session->getData(PaymentDevRelaySession::schema_fields_SESSION_CODE),
            'token' => $token,
            'role' => (string) $session->getData(PaymentDevRelaySession::schema_fields_ROLE),
            'status' => (string) $session->getData(PaymentDevRelaySession::schema_fields_STATUS),
            'local_inbound_url' => $localInboundUrl !== ''
                ? $localInboundUrl
                : (string) $session->getData(PaymentDevRelaySession::schema_fields_LOCAL_INBOUND_URL),
            'online_stream_url' => $streamUrl !== ''
                ? $streamUrl
                : (string) $session->getData(PaymentDevRelaySession::schema_fields_ONLINE_STREAM_URL),
            'console_url' => $consoleUrl,
            'outbound_mode' => (string) $session->getData(PaymentDevRelaySession::schema_fields_OUTBOUND_MODE),
            'expires_at' => (string) $session->getData(PaymentDevRelaySession::schema_fields_EXPIRES_AT),
            'last_event_seq' => (int) $session->getData(PaymentDevRelaySession::schema_fields_LAST_EVENT_SEQ),
        ];
    }

    private function normalizeOutboundMode(string $mode): string
    {
        return strtolower(trim($mode)) === PaymentDevRelaySession::OUTBOUND_ONLINE_PROXY
            ? PaymentDevRelaySession::OUTBOUND_ONLINE_PROXY
            : PaymentDevRelaySession::OUTBOUND_LOCAL_DIRECT;
    }

    private function newSessionModel(): PaymentDevRelaySession
    {
        return ObjectManager::getInstance(PaymentDevRelaySession::class);
    }
}
