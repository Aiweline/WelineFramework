<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\Social\Interface\SocialPlatformProviderInterface;

class SocialOauthService
{
    private const SESSION_KEY = 'weline_social_oauth_state';
    private const STATE_TTL = 900;

    public function __construct(
        private readonly SocialPlatformRegistry $registry,
        private readonly SocialAccountService $accountService,
        private readonly SocialPlatformAppConfig $appConfig,
        private readonly Url $url,
        private readonly ?ObjectManager $objectManager = null
    ) {
    }

    /**
     * @param array<string, mixed> $accountContext
     * @return array<string, mixed>
     */
    public function start(string $platformCode, array $accountContext = [], ?string $redirectUri = null): array
    {
        $platformCode = \strtolower(\trim($platformCode));
        $provider = $this->registry->getProvider($platformCode);
        if ($provider === null) {
            throw new \InvalidArgumentException((string)__('未知社媒平台：%{1}', [$platformCode]));
        }
        if (!$this->supportsOneClick($provider)) {
            return [
                'success' => false,
                'authorization_url' => '',
                'state' => '',
                'message' => (string)__('该平台不支持一键授权，请在账户表单中人工配置凭据。'),
            ];
        }

        $redirectUri = \trim((string)($redirectUri ?: $this->defaultCallbackUrl()));
        $state = \bin2hex(\random_bytes(16));
        if ($platformCode === 'x' && empty($accountContext['code_verifier'])) {
            $accountContext['code_verifier'] = \bin2hex(\random_bytes(32));
        }
        $this->storeState($state, [
            'platform_code' => $platformCode,
            'redirect_uri' => $redirectUri,
            'account_context' => $accountContext,
            'created_at' => \time(),
        ]);

        $url = $provider->buildAuthorizationUrl($accountContext, $redirectUri, $state);
        if ($url === null || $url === '') {
            return [
                'success' => false,
                'authorization_url' => '',
                'state' => $state,
                'message' => (string)__('未配置平台应用凭据，请先到统一配置中心填写 App/Client 信息。'),
            ];
        }

        return [
            'success' => true,
            'authorization_url' => $url,
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'message' => (string)__('授权地址已生成。'),
        ];
    }

    /**
     * @param array<string, mixed> $callbackData
     * @return array<string, mixed>
     */
    public function complete(array $callbackData): array
    {
        $state = \trim((string)($callbackData['state'] ?? ''));
        $payload = $this->consumeState($state);
        if ($payload === null) {
            throw new \InvalidArgumentException((string)__('授权状态无效或已过期，请重新发起一键授权。'));
        }

        $platformCode = (string)($payload['platform_code'] ?? '');
        $provider = $this->registry->getProvider($platformCode);
        if ($provider === null) {
            throw new \InvalidArgumentException((string)__('未知社媒平台：%{1}', [$platformCode]));
        }

        $result = $provider->handleAuthorizationCallback($callbackData, [
            'redirect_uri' => (string)($payload['redirect_uri'] ?? ''),
            'account_context' => \is_array($payload['account_context'] ?? null) ? $payload['account_context'] : [],
            'app_config' => $this->appConfig->getPlatformApp($platformCode),
        ]);

        if (empty($result['success'])) {
            return [
                'success' => false,
                'message' => (string)($result['message'] ?? __('授权失败。')),
                'platform_code' => $platformCode,
            ];
        }

        $context = \is_array($payload['account_context'] ?? null) ? $payload['account_context'] : [];
        $credentials = \is_array($result['credentials'] ?? null) ? $result['credentials'] : [];
        $save = $this->accountService->saveCredentialAccount([
            'account_id' => (int)($context['account_id'] ?? 0),
            'platform_code' => $platformCode,
            'account_name' => (string)($result['account_name'] ?? $context['account_name'] ?? ($provider->getDefinition()['title'] ?? $platformCode)),
            'auth_mode' => 'oauth2',
            'profile_url' => (string)($result['profile_url'] ?? $context['profile_url'] ?? ''),
            'widget_enabled' => !empty($context['widget_enabled']) || ((string)($result['profile_url'] ?? '') !== ''),
            'publish_enabled' => true,
            'credentials' => $credentials,
            'scopes' => \is_array($result['scopes'] ?? null) ? $result['scopes'] : [],
            'token_expires_at' => (string)($result['token_expires_at'] ?? ''),
            'remote_account_id' => (string)($result['remote_account_id'] ?? ''),
            'remote_account_name' => (string)($result['remote_account_name'] ?? ''),
        ]);

        return [
            'success' => !empty($save['success']),
            'message' => (string)($save['message'] ?? $result['message'] ?? __('授权完成。')),
            'platform_code' => $platformCode,
            'account' => $save['account'] ?? [],
        ];
    }

    public function defaultCallbackUrl(): string
    {
        return $this->url->getBackendUrl('weline_social/backend/oauth/callback');
    }

    private function supportsOneClick(SocialPlatformProviderInterface $provider): bool
    {
        $definition = $provider->getDefinition();
        if (\array_key_exists('supports_one_click_auth', $definition)) {
            return (bool)$definition['supports_one_click_auth'];
        }

        return \in_array('oauth2', (array)($definition['auth_modes'] ?? []), true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeState(string $state, array $payload): void
    {
        $session = $this->session();
        $bucket = $session->getData(self::SESSION_KEY);
        if (!\is_array($bucket)) {
            $bucket = [];
        }
        $bucket[$state] = $payload;
        $session->setData(self::SESSION_KEY, $bucket);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function consumeState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }
        $session = $this->session();
        $bucket = $session->getData(self::SESSION_KEY);
        if (!\is_array($bucket) || !isset($bucket[$state]) || !\is_array($bucket[$state])) {
            return null;
        }
        $payload = $bucket[$state];
        unset($bucket[$state]);
        $session->setData(self::SESSION_KEY, $bucket);

        $createdAt = (int)($payload['created_at'] ?? 0);
        if ($createdAt > 0 && (\time() - $createdAt) > self::STATE_TTL) {
            return null;
        }

        return $payload;
    }

    private function session(): Session
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(Session::class);
    }
}
