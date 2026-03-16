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
     * 平台 API 基础 URL
     */
    private string $platformUrl;

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
        $this->platformUrl = Env::get('appstore.platform_url', 'https://app.aiweline.com');
        
        // 加密密钥必须配置，否则抛出警告
        $encryptionKey = Env::get('appstore.encryption_key');
        if (empty($encryptionKey) || $encryptionKey === 'default_encryption_key_change_me') {
            // 使用安装时生成的随机密钥作为后备
            $encryptionKey = Env::get('appstore.generated_key');
            if (empty($encryptionKey)) {
                $encryptionKey = bin2hex(random_bytes(32));
                // 记录警告日志
                Env::log_warning('AppStore: 使用自动生成的加密密钥，建议在配置中设置 appstore.encryption_key');
            }
        }
        $this->encryptionKey = $encryptionKey;
        
        $this->httpClient = new Client([
            'timeout' => 30,
            'verify' => true,
        ]);
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
            $response = $this->httpClient->post($this->platformUrl . '/api/v1/auth/verify', [
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

            // 检查是否已绑定
            /** @var AppStoreAccount $existingAccount */
            $existingAccount = ObjectManager::getInstance(AppStoreAccount::class);
            $existingAccount->load($platformUserId, AppStoreAccount::schema_fields_platform_user_id);

            // 加密存储 token
            $encryptedToken = $this->encryptToken($platformToken);

            if ($existingAccount->getAccountId()) {
                // 更新已存在的绑定
                $existingAccount->setPlatformToken($encryptedToken);
                $existingAccount->setPlatformEmail($email);
                $existingAccount->setPlatformUsername($platformUsername);
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
            $account->setPlatformUserId($platformUserId);
            $account->setPlatformToken($encryptedToken);
            $account->setPlatformEmail($email);
            $account->setPlatformUsername($platformUsername);
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
    public function bindWithOAuth(string $code): array
    {
        try {
            // 使用授权码换取令牌
            $response = $this->httpClient->post($this->platformUrl . '/api/v1/oauth/token', [
                'json' => [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->getOAuthRedirectUri(),
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
        $accounts = $account->reset()
            ->where('status', AppStoreAccount::STATUS_ACTIVE)
            ->limit(1)
            ->select()
            ->fetch();

        if (empty($accounts)) {
            return null;
        }

        $accountData = is_array($accounts) ? $accounts[0] : $accounts;
        $account->setData($accountData);
        return $account;
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

        $token = $this->decryptToken($account->getPlatformToken());

        try {
            $response = $this->httpClient->post($this->platformUrl . '/api/v1/auth/refresh', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
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

        $token = $this->decryptToken($account->getPlatformToken());

        try {
            $response = $this->httpClient->get($this->platformUrl . '/api/v1/user/licenses', [
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

        return $this->decryptToken($account->getPlatformToken());
    }

    /**
     * 获取 OAuth 重定向 URI
     *
     * @return string
     */
    private function getOAuthRedirectUri(): string
    {
        return Env::get('appstore.oauth_redirect_uri', $this->platformUrl . '/appstore/oauth/callback');
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
            $response = $this->httpClient->get($this->platformUrl . '/api/v1/user/info', [
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
        $account->load($platformUserId, AppStoreAccount::schema_fields_platform_user_id);

        $encryptedToken = $this->encryptToken($token);

        if ($account->getAccountId()) {
            $account->setPlatformToken($encryptedToken);
            $account->setPlatformEmail($platformEmail);
            $account->setPlatformUsername($platformUsername);
            $account->setStatus(AppStoreAccount::STATUS_ACTIVE);
            $account->setTokenExpiresAt($expiresAt);
            $account->updateSyncTime();
        } else {
            $account->setPlatformUserId($platformUserId);
            $account->setPlatformToken($encryptedToken);
            $account->setPlatformEmail($platformEmail);
            $account->setPlatformUsername($platformUsername);
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
        $data = base64_decode($encryptedToken);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
    }

    /**
     * 获取当前域名
     *
     * @return string
     */
    private function getCurrentDomain(): string
    {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
}
