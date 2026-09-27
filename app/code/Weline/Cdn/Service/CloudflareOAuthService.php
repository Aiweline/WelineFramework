<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\Account;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Confidential Cloudflare OAuth Authorization Code client.
 */
final class CloudflareOAuthService
{
    private const AUTHORIZATION_URL = 'https://dash.cloudflare.com/oauth2/auth';
    private const ACCOUNT_NAME = 'Cloudflare OAuth';
    /**
     * API scopes the OAuth Client must allow (Cloudflare console checkboxes).
     * Do NOT include protocol scopes — CF adds offline_access/openid from grant_types.
     *
     * @see https://developers.cloudflare.com/api/resources/iam/subresources/oauth_clients/methods/create/
     */
    private const REQUIRED_SCOPES = [
        'zone.read',
        'dns.write',
        'cache.purge',
        'cache-settings.write',
    ];
    /**
     * Managed by Cloudflare from grant_types / response_types — never request or require as Client scopes.
     *
     * @var list<string>
     */
    private const PROTOCOL_SCOPES = [
        'offline_access',
        'openid',
        'offline',
    ];
    private const CONFIG_CLIENT_ID = 'cdn/cloudflare/oauth_client_id';
    private const CONFIG_CLIENT_SECRET = 'cdn/cloudflare/oauth_client_secret';
    private const CONFIG_SCOPES = 'cdn/cloudflare/oauth_scopes';

    public function __construct(
        private readonly CloudflareHttpClient $http,
        private readonly CloudflareOAuthStateStore $stateStore,
        private readonly AccountManager $accountManager,
        private readonly ?ConfigReader $configReader = null,
    ) {
    }

    public function isConfigured(): bool
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        return $clientId !== ''
            && $clientSecret !== ''
            && !hash_equals($clientId, $clientSecret)
            && !$this->hasMisplacedClientCredentials();
    }

    /**
     * Space-delimited scopes sent on authorize (for admin UI / invalid_scope guidance).
     */
    public function requestedScopesLabel(): string
    {
        try {
            return implode(' ', $this->scopes());
        } catch (\Throwable) {
            return implode(' ', self::REQUIRED_SCOPES);
        }
    }

    /**
     * True when both fields are filled but identical (almost always a paste mistake).
     */
    public function hasIdenticalClientCredentials(): bool
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        return $clientId !== ''
            && $clientSecret !== ''
            && hash_equals($clientId, $clientSecret);
    }

    /**
     * True when values look swapped relative to Cloudflare's create-client dialog.
     *
     * Current Cloudflare UI (and API examples): Client ID is 32-char hex; Client Secret is a
     * one-time value that often starts with {@code cfoc_}. Account ID is also 32-hex and cannot
     * be distinguished from Client ID by format alone — do not flag hex Client IDs as misplaced.
     */
    public function hasMisplacedClientCredentials(): bool
    {
        return self::credentialsLookMisplaced($this->clientId(), $this->clientSecret());
    }

    /**
     * @internal Prefer {@see hasMisplacedClientCredentials()} in runtime code.
     */
    public static function credentialsLookMisplaced(string $clientId, string $clientSecret): bool
    {
        $clientId = trim($clientId);
        $clientSecret = trim($clientSecret);
        if ($clientId === '' || $clientSecret === '') {
            return false;
        }
        if (hash_equals($clientId, $clientSecret)) {
            return false;
        }

        // Swap: Secret pasted into Client ID (cfoc_…), and/or hex Client ID pasted into Secret.
        return self::looksLikeOauthClientSecret($clientId)
            || (self::looksLikeOauthClientId($clientSecret) && self::looksLikeOauthClientSecret($clientId));
    }

    public function authorizationUrl(string $callbackUrl, string $returnRoute): string
    {
        $this->assertConfigured();
        $issued = $this->stateStore->issue($callbackUrl, $returnRoute);

        return self::AUTHORIZATION_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $callbackUrl,
            'scope' => implode(' ', $this->scopes()),
            'state' => $issued['state'],
            'code_challenge' => $issued['code_challenge'],
            'code_challenge_method' => $issued['code_challenge_method'],
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
            'code_verifier' => $context['code_verifier'],
        ], $this->clientId(), $this->clientSecret(), $this->authenticationMethod());

        $refreshToken = trim((string)($token['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new \RuntimeException(
                (string)__('Cloudflare OAuth 未返回可续期令牌。请确认 OAuth Client 的授权类型包含 Refresh Token（offline_access 由 Cloudflare 按 grant 自动追加，控制台勾选列表里没有该项）。')
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
        $context = $this->stateStore->consume($state, $callbackUrl);

        return ['return_route' => $context['return_route']];
    }

    /**
     * Best-effort revoke at Cloudflare token endpoint (ignore transport failures).
     */
    public function revokeToken(string $token, string $tokenTypeHint = 'refresh_token'): void
    {
        $token = trim($token);
        if ($token === '' || !$this->isConfigured()) {
            return;
        }
        try {
            $this->http->oauthRevoke(
                $token,
                $tokenTypeHint,
                $this->clientId(),
                $this->clientSecret(),
                $this->authenticationMethod(),
            );
        } catch (\Throwable) {
            // Revoke is best-effort; local credential wipe still proceeds.
        }
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

        $previous = $account->getCredentialsArray();
        if (($previous['oauth_provider'] ?? '') === 'cloudflare') {
            $oldRefresh = trim((string)($previous['oauth_refresh_token'] ?? ''));
            if ($oldRefresh !== '') {
                $this->revokeToken($oldRefresh, 'refresh_token');
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
        $configured = $this->resolveSetting(
            'WELINE_CLOUDFLARE_OAUTH_SCOPES',
            self::CONFIG_SCOPES,
        );
        $scopes = $configured === ''
            ? self::REQUIRED_SCOPES
            : preg_split('/[\s,]+/', strtolower($configured), -1, PREG_SPLIT_NO_EMPTY);
        $scopes = is_array($scopes) ? $scopes : [];
        // Drop legacy/protocol scopes (old configs often still list offline_access).
        $scopes = array_values(array_unique(array_filter(
            $scopes,
            static fn(string $scope): bool => $scope !== ''
                && !in_array($scope, self::PROTOCOL_SCOPES, true)
        )));

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
        return $this->resolveSetting(
            'WELINE_CLOUDFLARE_OAUTH_CLIENT_ID',
            self::CONFIG_CLIENT_ID,
        );
    }

    private function clientSecret(): string
    {
        return $this->resolveSetting(
            'WELINE_CLOUDFLARE_OAUTH_CLIENT_SECRET',
            self::CONFIG_CLIENT_SECRET,
        );
    }

    /**
     * Prefer SystemConfig (后台「Cloudflare OAuth 应用」)，再回退环境变量。
     */
    private function resolveSetting(string $envKey, string $configKey): string
    {
        $fromConfig = '';
        $reader = $this->configReader;
        if ($reader === null) {
            try {
                $reader = ObjectManager::getInstance(ConfigReader::class);
            } catch (\Throwable) {
                $reader = null;
            }
        }
        if ($reader instanceof ConfigReader) {
            try {
                $fromConfig = trim((string)$reader->get(
                    key: $configKey,
                    module: 'Weline_Cdn',
                    area: ConfigReader::area_BACKEND,
                    default: null,
                    scope: ConfigReader::SCOPE_GLOBAL,
                ));
            } catch (\Throwable) {
                $fromConfig = '';
            }
        }

        if ($fromConfig !== '') {
            return $fromConfig;
        }

        return trim((string)(getenv($envKey) ?: ''));
    }

    private function assertConfigured(): void
    {
        if ($this->hasIdenticalClientCredentials()) {
            throw new \RuntimeException(
                (string)__('Cloudflare OAuth Client ID 与 Client Secret 相同。请到系统配置分别粘贴 Cloudflare 控制台里的两项，不要把 Client ID 填进 Secret。')
            );
        }
        if ($this->hasMisplacedClientCredentials()) {
            throw new \RuntimeException(
                (string)__('Cloudflare OAuth 凭据位置不对：创建弹窗里「Your Client ID」是 32 位十六进制，「客户端密钥」才是以 cfoc_ 开头的 Secret。不要对调粘贴。')
            );
        }
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                (string)__('Cloudflare OAuth 客户端未配置，请先在系统配置填写 OAuth Client ID/Secret，或设置服务器环境变量。')
            );
        }
        $this->scopes();
    }

    /**
     * Cloudflare OAuth Client ID (create dialog / API): 32-char hex.
     * Note: Account ID uses the same shape — copy Client ID from the OAuth client dialog, not the account URL.
     */
    private static function looksLikeOauthClientId(string $value): bool
    {
        return (bool)preg_match('/^[a-f0-9]{32}$/i', $value);
    }

    /**
     * Cloudflare OAuth Client Secret from the create/rotate dialog often starts with cfoc_.
     */
    private static function looksLikeOauthClientSecret(string $value): bool
    {
        return str_starts_with($value, 'cfoc_');
    }
}
