<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Service;

use Weline\Framework\App\Env;

/**
 * SecretStore 加密服务
 * 
 * 功能：
 * - API 密钥加密/解密
 * - 敏感数据保护
 * - 密钥派生
 * - 安全令牌生成
 * 
 * @package Weline_Geo
 */
class SecretStoreService
{
    /**
     * 加密算法
     */
    private const CIPHER_ALGO = 'AES-256-CBC';

    /**
     * 密钥派生算法
     */
    private const KDF_ALGO = 'sha256';

    /**
     * 主密钥（从环境配置获取）
     */
    private ?string $masterKey = null;

    public function __construct()
    {
        $this->initMasterKey();
    }

    /**
     * 初始化主密钥
     *
     * @return void
     */
    private function initMasterKey(): void
    {
        // 尝试从环境配置获取主密钥
        $config = Env::getInstance()->getConfig();
        
        if (isset($config['geo_secret_key']) && !empty($config['geo_secret_key'])) {
            $this->masterKey = $config['geo_secret_key'];
        } else {
            // 如果没有配置主密钥，生成一个默认的（不推荐用于生产环境）
            $this->masterKey = hash(self::KDF_ALGO, 'weline_geo_default_secret_' . ($config['admin'] ?? 'default'));
        }
    }

    /**
     * 加密 API 密钥
     *
     * @param string $apiKey
     * @return string 加密后的密钥（Base64编码）
     */
    public function encryptApiKey(string $apiKey): string
    {
        return $this->encrypt($apiKey);
    }

    /**
     * 解密 API 密钥
     *
     * @param string $encryptedKey
     * @return string|null 解密后的密钥，失败返回 null
     */
    public function decryptApiKey(string $encryptedKey): ?string
    {
        return $this->decrypt($encryptedKey);
    }

    /**
     * 加密数据
     *
     * @param string $data
     * @return string
     * @throws \Exception
     */
    public function encrypt(string $data): string
    {
        if (empty($this->masterKey)) {
            throw new \RuntimeException('Master key not initialized');
        }

        // 生成随机 IV
        $ivLength = openssl_cipher_iv_length(self::CIPHER_ALGO);
        $iv = openssl_random_pseudo_bytes($ivLength);

        // 加密数据
        $encrypted = openssl_encrypt(
            $data,
            self::CIPHER_ALGO,
            $this->masterKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed');
        }

        // 将 IV 和加密数据组合并编码
        $result = base64_encode($iv . $encrypted);

        return $result;
    }

    /**
     * 解密数据
     *
     * @param string $encryptedData
     * @return string|null
     */
    public function decrypt(string $encryptedData): ?string
    {
        if (empty($this->masterKey)) {
            return null;
        }

        try {
            // 解码数据
            $data = base64_decode($encryptedData);
            if ($data === false) {
                return null;
            }

            // 提取 IV
            $ivLength = openssl_cipher_iv_length(self::CIPHER_ALGO);
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);

            // 解密数据
            $decrypted = openssl_decrypt(
                $encrypted,
                self::CIPHER_ALGO,
                $this->masterKey,
                OPENSSL_RAW_DATA,
                $iv
            );

            return $decrypted !== false ? $decrypted : null;
        } catch (\Throwable $e) {
            // 记录错误但不抛出异常
            w_log_error('SecretStore decryption error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 生成安全的随机 API Token
     *
     * @param int $length
     * @return string
     */
    public function generateSecureToken(int $length = 32): string
    {
        $token = 'geo-' . bin2hex(random_bytes($length));
        return $token;
    }

    /**
     * 生成 API 密钥哈希（用于快速查找）
     *
     * @param string $apiKey
     * @return string
     */
    public function hashApiKey(string $apiKey): string
    {
        return hash(self::KDF_ALGO, $apiKey);
    }

    /**
     * 验证 API 密钥
     *
     * @param string $apiKey
     * @param string $encryptedKey
     * @return bool
     */
    public function verifyApiKey(string $apiKey, string $encryptedKey): bool
    {
        $decrypted = $this->decrypt($encryptedKey);
        return $decrypted === $apiKey;
    }

    /**
     * 加密敏感配置数据
     *
     * @param array $config
     * @return string
     */
    public function encryptConfig(array $config): string
    {
        return $this->encrypt(json_encode($config));
    }

    /**
     * 解密配置数据
     *
     * @param string $encryptedConfig
     * @return array|null
     */
    public function decryptConfig(string $encryptedConfig): ?array
    {
        $decrypted = $this->decrypt($encryptedConfig);
        if ($decrypted === null) {
            return null;
        }

        $config = json_decode($decrypted, true);
        return is_array($config) ? $config : null;
    }

    /**
     * 检查主密钥是否已配置
     *
     * @return bool
     */
    public function hasMasterKey(): bool
    {
        return !empty($this->masterKey);
    }
}
