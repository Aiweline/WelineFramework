<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Account;
use Weline\Framework\Manager\ObjectManager;

/**
 * Confidential Cloudflare OAuth Authorization Code client.
 */
final class CloudflareOAuthService
{
    private const AUTHORIZATION_URL = 'https://dash.cloudflare.com/oauth2/auth';
    private const ACCOUNT_NAME = 'Cloudflare OAuth';
    private const REQUIRED_SCOPES = ['zone.read', 'dns.write', 'offline_access'];

    public function __construct(
        private readonly CloudflareHttpClient $http,
        private readonly CloudflareOAuthStateStore $stateStore,
        private readonly AccountManager $accountManager,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function authorizationUrl(string $callbackUrl, string $returnRoute): string
    {
        $this->assertConfigured();
        $state = $this->stateStore->issue($callbackUrl, $returnRoute);

        return self::AUTHORIZATION_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $callbackUrl,
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{account_id: int, return_route: string, scope: string}
     */
    public function completeAuthorization(string $code, string $state, string $callbackUrl): array
    {
        $context = $this->stateStore->consume($state, $callbackUrl);
        if (trim($code) === '') {
            throw new \DomainException((string)__('Cloudflare OAuth 未返回授权码。'));
        }
        $this->assertConfigured();

        $token = $this->http->oauthToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $callbackUrl,
        ], $this->clientId(), $this->clientSecret(), $this->authenticationMethod());

        $refreshToken = trim((string)($token['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new \RuntimeException(
                (string)__('Cloudflare OAuth 未返回可续期令牌，请检查 offline_access scope。')
            );
        }

        $account = $this->persistToken($token, $refreshToken);

        return [
            'account_id' => (int)$account->getData(Account::schema_fields_ACCOUNT_ID),
            'return_route' => $context['return_route'],
            'scope' => (string)($token['scope'] ?? implode(' ', $this->scopes())),
        ];
    }

    /**
     * Validate and consume state when Cloudflare returns an OAuth error.
     *
     * @return array{return_route: string}
     */
    public function consumeFailureState(string $state, string $callbackUrl): array
    {
        return $this->stateStore->consume($state, $callbackUrl);
    }

    /**
     * Refresh an OAuth-backed account when needed. API-token accounts remain
     * compatible and are returned unchanged.
     *
     * @return array<string, mixed>
     */
    public function credentialsForAccount(Account $account): array
    {
        $credentials = $account->getCredentialsArray();
        if (($credentials['oauth_provider'] ?? '') !== 'cloudflare') {
            return $credentials;
        }

        $expiresAt = (int)($credentials['oauth_expires_at'] ?? 0);
        if ($expiresAt === 0 || $expiresAt > time() + 120) {
            return $credentials;
        }

        $refreshToken = trim((string)($credentials['oauth_refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new \RuntimeException((string)__('Cloudflare OAuth 授权已过期，请重新连接。'));
        }
        $this->assertConfigured();

        $token = $this->http->oauthToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], $this->clientId(), $this->clientSecret(), $this->authenticationMethod());

        $credentials['api_token'] = trim((string)$token['access_token']);
        $credentials['oauth_refresh_token'] = trim((string)($token['refresh_token'] ?? $refreshToken));
        $credentials['oauth_expires_at'] = time() + max(60, (int)($token['expires_in'] ?? 3600));
        $credentials['oauth_scope'] = (string)($token['scope'] ?? ($credentials['oauth_scope'] ?? ''));
        $account->setCredentialsArray($credentials)->save();

        return $credentials;
    }

    /**
     * @param array<string, mixed> $token
     */
    private function persistToken(array $token, string $refreshToken): Account
    {
        $account = $this->accountManager->getDefaultAccount('cloudflare');
        if (
            !$account instanceof Account
            || ($account->getCredentialsArray()['oauth_provider'] ?? '') !== 'cloudflare'
        ) {
            $candidate = ObjectManager::getInstance(Account::class)->reset()
                ->where(Account::schema_fields_ADAPTER, 'cloudflare')
                ->where(Account::schema_fields_NAME, self::ACCOUNT_NAME)
                ->find()
                ->fetch();
            if (
                $candidate instanceof Account
                && $candidate->getId()
                && ($candidate->getCredentialsArray()['oauth_provider'] ?? '') === 'cloudflare'
            ) {
                $account = $candidate;
            } else {
                $account = ObjectManager::getInstance(Account::class)->reset();
                $account->setData(Account::schema_fields_IS_DEFAULT, 0);
            }
        }

        $credentials = [
            'api_token' => trim((string)$token['access_token']),
            'oauth_provider' => 'cloudflare',
            'oauth_refresh_token' => $refreshToken,
            'oauth_expires_at' => time() + max(60, (int)($token['expires_in'] ?? 3600)),
            'oauth_scope' => (string)($token['scope'] ?? implode(' ', $this->scopes())),
            'oauth_authorized_at' => time(),
        ];

        $account->setData(Account::schema_fields_ADAPTER, 'cloudflare');
        $account->setData(Account::schema_fields_NAME, self::ACCOUNT_NAME);
        $account->setData(
            Account::schema_fields_DESCRIPTION,
            (string)__('由 Cloudflare OAuth 管理；令牌会自动刷新。')
        );
        $account->setData(Account::schema_fields_STATUS, Account::STATUS_ACTIVE);
        $account->setCredentialsArray($credentials);
        $account->save();

        $accountId = (int)$account->getData(Account::schema_fields_ACCOUNT_ID);
        if ($accountId < 1) {
            throw new \RuntimeException((string)__('Cloudflare OAuth 账户保存失败。'));
        }
        $this->accountManager->setDefaultAccount($accountId);

        return $account;
    }

    /**
     * @return array<int, string>
     */
    private function scopes(): array
    {
        $configured = trim((string)(getenv('WELINE_CLOUDFLARE_OAUTH_SCOPES') ?: ''));
        $scopes = $configured === ''
            ? self::REQUIRED_SCOPES
            : preg_split('/[\s,]+/', strtolower($configured), -1, PREG_SPLIT_NO_EMPTY);
        $scopes = array_values(array_unique(is_array($scopes) ? $scopes : []));

        foreach (self::REQUIRED_SCOPES as $required) {
            if (!in_array($required, $scopes, true)) {
                throw new \RuntimeException(
                    (string)__('Cloudflare OAuth 缺少必需 scope：%{1}', $required)
                );
            }
        }

        return $scopes;
    }

    private function authenticationMethod(): string
    {
        $method = strtolower(trim((string)(
            getenv('WELINE_CLOUDFLARE_OAUTH_TOKEN_AUTH_METHOD') ?: 'client_secret_post'
        )));

        return in_array($method, ['client_secret_post', 'client_secret_basic'], true)
            ? $method
            : 'client_secret_post';
    }

    private function clientId(): string
    {
        return trim((string)(getenv('WELINE_CLOUDFLARE_OAUTH_CLIENT_ID') ?: ''));
    }

    private function clientSecret(): string
    {
        return trim((string)(getenv('WELINE_CLOUDFLARE_OAUTH_CLIENT_SECRET') ?: ''));
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                (string)__('Cloudflare OAuth 客户端未配置，请先设置服务器环境变量。')
            );
        }
        $this->scopes();
    }
}
