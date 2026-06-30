<?php
declare(strict_types=1);

namespace Weline\AppStore\Service;

use GuzzleHttp\Client;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\AppStore\Model\AppStoreAccount;

/**
 * 账户绑定服务
 *
 * 负责与官网平台账户绑定、验证、令牌管理
 */
class AccountBindService
{
    /**
     * 平台 API 默认基础 URL（当配置存在但值为 null/空时兜底）
     */
    private const DEFAULT_PLATFORM_URL = AppStorePlatformUrlResolver::DEFAULT_PLATFORM_URL;

    /**
     * 平台 API 基础 URL
     */
    private string $platformUrl;

    /**
     * @var array{platform_url?:string,source?:string,environment?:string}
     */
    private array $platformResolution = [];

    private string $platformApiUrl;

    /**
     * HTTP 客户端
     */
    private Client $httpClient;

    /**
     * 加密密钥
     */
    private string $encryptionKey;

    public function __construct()
    {
        $platformUrl = $this->resolvePlatformUrl();
        // Env::get 如果配置项存在但显式为 null，会直接返回 null（不会走 default），因此这里做非空兜底。
        $this->platformUrl = $platformUrl;
        $this->platformApiUrl = self::normalizePlatformApiBaseUrl($platformUrl);
        
        // 加密密钥必须稳定；未配置时使用确定性后备值，避免重启后无法解密历史 token。
        $encryptionKey = Env::get('appstore.encryption_key');
        if (empty($encryptionKey) || $encryptionKey === 'default_encryption_key_change_me') {
            $encryptionKey = Env::get('appstore.generated_key');
            if (empty($encryptionKey)) {
                $encryptionKey = hash('sha256', BP . '|appstore|fallback-key');
                Env::log_warning('appstore', 'AppStore: 使用后备加密密钥，建议尽快配置 appstore.encryption_key');
            }
        }
        $this->encryptionKey = $encryptionKey;
        
        $this->httpClient = new Client($this->getHttpClientOptions([
            'timeout' => 30,
        ]));
    }

    public function getPlatformUrl(): string
    {
        return $this->platformUrl;
    }

    /**
     * @return array{platform_url:string,source:string,environment:string}
     */
    public function getPlatformResolution(): array
    {
        if ($this->platformResolution === []) {
            $this->platformResolution = (new AppStorePlatformUrlResolver())->resolve();
        }

        return [
            'platform_url' => $this->platformUrl,
            'source' => (string)($this->platformResolution['source'] ?? ''),
            'environment' => (string)($this->platformResolution['environment'] ?? ''),
        ];
    }

    public function getPlatformApiUrl(string $path = ''): string
    {
        if ($path === '') {
            return $this->platformApiUrl;
        }

        return rtrim($this->platformApiUrl, '/') . '/' . ltrim($path, '/');
    }

    public function getHttpClientOptions(array $options = []): array
    {
        $options['verify'] = $this->resolveHttpVerifyOption($options['verify'] ?? true);
        return $options;
    }

    public function getAuthorizationUrl(string $redirectUri, ?string $state = null): string
    {
        $params = [
            'client_id' => 'weline-terminal',
            'redirect_uri' => $redirectUri,
            'domain' => $this->getDomainFromUrl($redirectUri) ?: $this->getCurrentDomain(),
        ];
        if ($state !== null && $state !== '') {
            $params['state'] = $state;
        }

        return rtrim($this->platformUrl, '/') . '/oauth/authorize?' . http_build_query($params);
    }

    private function deactivateActiveAccounts(): void
    {
        /** @var AppStoreAccount $account */
        $account = ObjectManager::getInstance(AppStoreAccount::class);
        $account->reset()
            ->where(AppStoreAccount::schema_fields_status, AppStoreAccount::STATUS_ACTIVE)
            ->update([AppStoreAccount::schema_fields_status => AppStoreAccount::STATUS_INACTIVE])
            ->fetch();
    }

    /**
     * 绑定官网账户
     *
     * @param string $email 平台邮箱
     * @param string $password 平台密码
     * @return array 绑定结果
     * @throws Exception
     */
    public function bind(string $email, string $password): array
    {
        try {
            // 调用平台 API 验证账户
            $response = $this->httpClient->post($this->getPlatformApiUrl('/api/v1/auth/verify'), [
                'json' => [
                    'email' => $email,
                    'password' => $password,
                    'source' => 'appstore',
                    'domain' => $this->getCurrentDomain(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data['success']) {
                return [
                    'success' => false,
                    'error' => $data['error'] ?? 'auth_failed',
                    'message' => $data['message'] ?? __('账户验证失败'),
                ];
            }

            // 获取平台用户信息
            $platformUserId = $data['user_id'];
            $platformToken = $data['token'];
            $platformUsername = $data['username'] ?? '';
            $tokenExpiresAt = $data['expires_at'] ?? null;
            $boundDomain = $this->getCurrentDomain();

            // 检查是否已绑定
            /** @var AppStoreAccount $existingAccount */
            $existingAccount = ObjectManager::getInstance(AppStoreAccount::class);
            $existingAccount = $existingAccount->clear()
                ->where(AppStoreAccount::schema_fields_platform_user_id, $platformUserId)
                ->find()
                ->fetch();

            // 加密存储 token
            $encryptedToken = $this->encryptToken($platformToken);

            if ($existingAccount->getAccountId()) {
                $this->deactivateActiveAccounts();
                // 更新已存在的绑定
                $existingAccount->setPlatformToken($encryptedToken);
                $existingAccount->setPlatformEmail($email);
                $existingAccount->setPlatformUsername($platformUsername);
                $existingAccount->setBoundDomain($boundDomain);
                $existingAccount->setStatus(AppStoreAccount::STATUS_ACTIVE);
                $existingAccount->setTokenExpiresAt($tokenExpiresAt);
                $existingAccount->updateSyncTime();
                $existingAccount->save();

                return [
                    'success' => true,
                    'message' => __('账户绑定已更新'),
                    'account' => $this->getAccountInfo($existingAccount),
                ];
            }

            // 创建新绑定
            /** @var AppStoreAccount $account */
            $account = ObjectManager::getInstance(AppStoreAccount::class);
            $this->deactivateActiveAccounts();
            $account->setPlatformUserId($platformUserId);
            $account->setPlatformToken($encryptedToken);
            $account->setPlatformEmail($email);
            $account->setPlatformUsername($platformUsername);
            $account->setBoundDomain($boundDomain);
            $account->setStatus(AppStoreAccount::STATUS_ACTIVE);
            $account->setBoundAt(date('Y-m-d H:i:s'));
            $account->setTokenExpiresAt($tokenExpiresAt);
            $account->save();

            return [
                'success' => true,
                'message' => __('账户绑定成功'),
                'account' => $this->getAccountInfo($account),
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => false,
                'error' => 'auth_failed',
                'message' => $body['message'] ?? __('账户验证失败'),
            ];
        } catch (\Exception $e) {
            throw new Exception(__('账户绑定失败：') . $e->getMessage());
        }
    }

    /**
     * 使用 OAuth 授权码绑定账户
     *
     * @param string $code 授权码
     * @return array 绑定结果
     * @throws Exception
     */
    public function bindWithOAuth(string $code, ?string $redirectUri = null): array
    {
        try {
            // 使用授权码换取令牌
            $response = $this->httpClient->post($this->getPlatformApiUrl('/api/v1/oauth/token'), [
                'json' => [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->getOAuthRedirectUri($redirectUri),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data['success']) {
                return [
                    'success' => false,
                    'error' => $data['error'] ?? 'oauth_failed',
                    'message' => $data['message'] ?? __('OAuth 授权失败'),
                ];
            }

            // 获取用户信息
            $userInfo = $this->getUserInfo($data['token']);

            if (!$userInfo['success']) {
                return [
                    'success' => false,
                    'error' => 'user_info_failed',
                    'message' => __('获取用户信息失败'),
                ];
            }

            // 创建或更新绑定
            return $this->createOrUpdateBinding($userInfo['user'], $data['token'], $data['expires_at']);
        } catch (\Exception $e) {
            throw new Exception(__('OAuth 绑定失败：') . $e->getMessage());
        }
    }

    /**
     * 解绑账户
     *
     * @return array
     */
    public function unbind(): array
    {
        /** @var AppStoreAccount $account */
        $account = $this->getCurrentAccount();

        if (!$account || !$account->getAccountId()) {
            return [
                'success' => false,
                'message' => __('未找到已绑定的账户'),
            ];
        }

        $account->setStatus(AppStoreAccount::STATUS_INACTIVE);
        $account->setPlatformToken(null);
        $account->save();

        return [
            'success' => true,
            'message' => __('账户已解绑'),
        ];
    }

    /**
     * 获取当前绑定的账户
     *
     * @return AppStoreAccount|null
     */
    public function getCurrentAccount(): ?AppStoreAccount
    {
        /** @var AppStoreAccount $account */
        $account = ObjectManager::getInstance(AppStoreAccount::class);
        $account = $account->reset()
            ->where('status', AppStoreAccount::STATUS_ACTIVE)
            ->limit(1)
            ->find()
            ->fetch();

        if ($account instanceof AppStoreAccount && $account->getAccountId() > 0) {
            return $account;
        }

        return null;
    }

    /**
     * 检查是否已绑定账户
     *
     * @return bool
     */
    public function isBound(): bool
    {
        $account = $this->getCurrentAccount();
        return $account && $account->isActive();
    }

    /**
     * 刷新令牌
     *
     * @return array
     * @throws Exception
     */
    public function refreshToken(): array
    {
        $account = $this->getCurrentAccount();

        if (!$account || !$account->getAccountId()) {
            return [
                'success' => false,
                'message' => __('未找到已绑定的账户'),
            ];
        }

        try {
            $token = $this->decryptToken((string)$account->getPlatformToken());
        } catch (\Exception) {
            return [
                'success' => false,
                'message' => __('令牌无效，请重新绑定账户'),
            ];
        }

        try {
            $response = $this->httpClient->post($this->getPlatformApiUrl('/api/v1/auth/refresh'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'json' => [
                    'domain' => $this->getCurrentDomain(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data['success']) {
                return [
                    'success' => false,
                    'message' => $data['message'] ?? __('令牌刷新失败'),
                ];
            }

            // 更新令牌
            $account->setPlatformToken($this->encryptToken($data['token']));
            $account->setTokenExpiresAt($data['expires_at']);
            $account->setBoundDomain($this->getCurrentDomain());
            $account->updateSyncTime();
            $account->save();

            return [
                'success' => true,
                'message' => __('令牌已刷新'),
            ];
        } catch (\Exception $e) {
            throw new Exception(__('令牌刷新失败：') . $e->getMessage());
        }
    }

    /**
     * 获取用户的许可证列表
     *
     * @return array
     * @throws Exception
     */
    public function getUserLicenses(): array
    {
        $account = $this->getCurrentAccount();

        if (!$account || !$account->getAccountId()) {
            return [
                'success' => false,
                'message' => __('未绑定账户'),
                'licenses' => [],
            ];
        }

        try {
            $token = $this->decryptToken((string)$account->getPlatformToken());
        } catch (\Exception) {
            return [
                'success' => false,
                'message' => __('账户令牌无效，请重新绑定'),
                'licenses' => [],
            ];
        }

        try {
            $response = $this->httpClient->get($this->getPlatformApiUrl('/api/v1/user/licenses'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'licenses' => $data['licenses'] ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('获取许可证列表失败：') . $e->getMessage(),
                'licenses' => [],
            ];
        }
    }

    /**
     * 获取平台 API 令牌
     *
     * @return string|null
     */
    public function getApiToken(): ?string
    {
        $account = $this->getCurrentAccount();

        if (!$account || !$account->getAccountId()) {
            return null;
        }

        // 检查令牌是否过期
        if ($account->isTokenExpired()) {
            // 尝试刷新令牌
            $result = $this->refreshToken();
            if (!$result['success']) {
                return null;
            }
            $account = $this->getCurrentAccount();
        }

        try {
            return $this->decryptToken((string)$account->getPlatformToken());
        } catch (\Exception) {
            Env::log_warning('appstore', 'AppStore: 令牌解密失败，请重新绑定账户');
            return null;
        }
    }

    /**
     * 获取 OAuth 重定向 URI
     *
     * @return string
     */
    private function getOAuthRedirectUri(?string $redirectUri = null): string
    {
        if (is_string($redirectUri) && $redirectUri !== '') {
            return $redirectUri;
        }

        $configuredRedirectUri = Env::get('appstore.oauth_redirect_uri', '');
        if (is_string($configuredRedirectUri) && $configuredRedirectUri !== '') {
            return $configuredRedirectUri;
        }

        $scheme = (\w_env('server.https') === 'on' || \w_env('server.server_port') === '443') ? 'https' : 'http';
        return $scheme . '://' . $this->getCurrentDomain() . '/appstore/backend/account/callback';
    }

    /**
     * 获取用户信息
     *
     * @param string $token 访问令牌
     * @return array
     */
    private function getUserInfo(string $token): array
    {
        try {
            $response = $this->httpClient->get($this->getPlatformApiUrl('/api/v1/user/info'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => $data['success'] ?? false,
                'user' => $data['user'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'user' => null,
            ];
        }
    }

    /**
     * 创建或更新绑定
     *
     * @param array $user 用户信息
     * @param string $token 令牌
     * @param string|null $expiresAt 过期时间
     * @return array
     */
    private function createOrUpdateBinding(array $user, string $token, ?string $expiresAt): array
    {
        $platformUserId = $user['id'];
        $platformEmail = $user['email'];
        $platformUsername = $user['username'] ?? '';

        /** @var AppStoreAccount $account */
        $account = ObjectManager::getInstance(AppStoreAccount::class);
        $account = $account->clear()
            ->where(AppStoreAccount::schema_fields_platform_user_id, $platformUserId)
            ->find()
            ->fetch();

        $encryptedToken = $this->encryptToken($token);

        if ($account->getAccountId()) {
            $this->deactivateActiveAccounts();
            $account->setPlatformToken($encryptedToken);
            $account->setPlatformEmail($platformEmail);
            $account->setPlatformUsername($platformUsername);
            $account->setBoundDomain($this->getCurrentDomain());
            $account->setStatus(AppStoreAccount::STATUS_ACTIVE);
            $account->setTokenExpiresAt($expiresAt);
            $account->updateSyncTime();
        } else {
            $this->deactivateActiveAccounts();
            $account->setPlatformUserId($platformUserId);
            $account->setPlatformToken($encryptedToken);
            $account->setPlatformEmail($platformEmail);
            $account->setPlatformUsername($platformUsername);
            $account->setBoundDomain($this->getCurrentDomain());
            $account->setStatus(AppStoreAccount::STATUS_ACTIVE);
            $account->setBoundAt(date('Y-m-d H:i:s'));
            $account->setTokenExpiresAt($expiresAt);
        }

        $account->save();

        return [
            'success' => true,
            'message' => __('账户绑定成功'),
            'account' => $this->getAccountInfo($account),
        ];
    }

    /**
     * 获取账户信息（不含敏感数据）
     *
     * @param AppStoreAccount $account
     * @return array
     */
    private function getAccountInfo(AppStoreAccount $account): array
    {
        return [
            'account_id' => $account->getAccountId(),
            'platform_user_id' => $account->getPlatformUserId(),
            'platform_email' => $account->getPlatformEmail(),
            'platform_username' => $account->getPlatformUsername(),
            'bound_domain' => $account->getBoundDomain(),
            'status' => $account->getStatus(),
            'bound_at' => $account->getBoundAt(),
            'token_expires_at' => $account->getTokenExpiresAt(),
            'is_active' => $account->isActive(),
        ];
    }

    /**
     * 加密令牌
     *
     * @param string $token
     * @return string
     */
    private function encryptToken(string $token): string
    {
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($token, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * 解密令牌
     *
     * @param string $encryptedToken
     * @return string
     */
    private function decryptToken(string $encryptedToken): string
    {
        if ($encryptedToken === '') {
            throw new Exception(__('令牌为空'));
        }
        $data = base64_decode($encryptedToken, true);
        if (!is_string($data) || $data === '') {
            throw new Exception(__('令牌格式无效'));
        }
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        if (strlen($data) <= $ivLength) {
            throw new Exception(__('令牌数据损坏'));
        }
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        if (!is_string($decrypted) || $decrypted === '') {
            throw new Exception(__('令牌解密失败'));
        }
        return $decrypted;
    }

    /**
     * 获取当前域名
     *
     * @return string
     */
    public function getCurrentDomain(): string
    {
        $requestHost = '';
        try {
            /** @var \Weline\Framework\Http\Request $request */
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $requestHost = $this->normalizeDomain((string)$request->getServer('HTTP_HOST'));
        } catch (\Throwable) {
            $requestHost = '';
        }

        if ($requestHost !== '' && !$this->isLoopbackDomain($requestHost)) {
            return $requestHost;
        }

        $serverHost = $this->normalizeDomain((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($serverHost !== '' && !$this->isLoopbackDomain($serverHost)) {
            return $serverHost;
        }

        $envHost = $this->normalizeDomain((string)\w_env('server.http_host', ''));
        if ($envHost !== '' && !$this->isLoopbackDomain($envHost)) {
            return $envHost;
        }

        if ($requestHost !== '') {
            return $requestHost;
        }

        if ($serverHost !== '') {
            return $serverHost;
        }

        return $envHost !== '' ? $envHost : 'localhost';
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }

        $parsedHost = parse_url($domain, PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '') {
            $port = parse_url($domain, PHP_URL_PORT);
            return $port ? $parsedHost . ':' . $port : $parsedHost;
        }

        $pathStart = strpos($domain, '/');
        if ($pathStart !== false) {
            $domain = substr($domain, 0, $pathStart);
        }

        return trim($domain);
    }

    private function isLoopbackDomain(string $domain): bool
    {
        $host = strtolower($domain);
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            if ($end !== false) {
                $host = substr($host, 1, $end - 1);
            }
        } elseif (substr_count($host, ':') === 1) {
            $host = strstr($host, ':', true) ?: $host;
        }

        return in_array($host, ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true);
    }

    private function getDomainFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $domain = (string)$parts['host'];
        if (!empty($parts['port'])) {
            $domain .= ':' . (string)$parts['port'];
        }

        return $domain;
    }

    private function resolvePlatformUrl(): string
    {
        $this->platformResolution = (new AppStorePlatformUrlResolver())->resolve();
        return (string)($this->platformResolution['platform_url'] ?? AppStorePlatformUrlResolver::DEFAULT_PLATFORM_URL);
    }

    private function resolveHttpVerifyOption(mixed $default): mixed
    {
        $caBundle = getenv('WELINE_APPSTORE_CA_BUNDLE');
        if (!is_string($caBundle) || trim($caBundle) === '') {
            $caBundle = Env::get('appstore.ca_bundle');
        }

        if (!is_string($caBundle) || trim($caBundle) === '') {
            if ($this->shouldTrustLocalSelfSignedPlatformCertificate()) {
                return false;
            }

            return $default;
        }

        $path = $this->normalizeCaBundlePath($caBundle);
        if (is_file($path) && is_readable($path)) {
            return $path;
        }

        Env::log_warning('appstore', 'AppStore: configured CA bundle is not readable: ' . $path);
        return $default;
    }

    private function shouldTrustLocalSelfSignedPlatformCertificate(): bool
    {
        if (($this->platformResolution['environment'] ?? '') !== 'local') {
            return false;
        }

        $host = strtolower((string)parse_url($this->platformUrl, PHP_URL_HOST));
        return in_array($host, ['app.weline.test', '127.0.0.1', 'localhost'], true);
    }

    private function normalizeCaBundlePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) || str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            return $path;
        }

        return BP . ltrim(str_replace(['/', '\\'], DS, $path), DS);
    }

    private static function normalizePlatformApiBaseUrl(string $platformUrl): string
    {
        $platformUrl = rtrim(trim($platformUrl), '/');
        if ($platformUrl === '') {
            return self::DEFAULT_PLATFORM_URL;
        }

        return preg_replace('#/([A-Za-z]{3})/([a-z]{2}(?:_[A-Za-z0-9]+){1,2})$#', '', $platformUrl) ?: $platformUrl;
    }
}
