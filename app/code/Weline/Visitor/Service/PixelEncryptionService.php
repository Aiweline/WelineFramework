<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Visitor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelEncryptionToken;

/**
 * 像素加密服务
 * 
 * 提供像素数据的加密解密功能，支持基于版本号的token管理
 */
class PixelEncryptionService
{
    private const CIPHER_METHOD = 'aes-256-gcm';
    private const IV_LENGTH = 12; // GCM模式推荐12字节IV
    private const TAG_LENGTH = 16; // GCM模式认证标签长度

    /**
     * 加密数据
     * 
     * @param mixed $data 要加密的数据（数组或字符串）
     * @param string|null $version 版本号，如果为null则使用当前版本
     * @return string 加密后的数据（base64编码）
     * @throws \Exception
     */
    public function encrypt($data, ?string $version = null): string
    {
        // 获取token
        if ($version === null) {
            $token = $this->getCurrentVersionToken();
        } else {
            $token = $this->getTokenByVersion($version);
        }
        
        if (!$token) {
            throw new \Exception(__('未找到版本号 %{1} 对应的加密token', [$version ?? '当前版本']));
        }

        $encryptionToken = $token->getEncryptionToken();
        
        // 将数据转换为JSON字符串
        $plaintext = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
        
        // 生成随机IV
        $iv = random_bytes(self::IV_LENGTH);
        
        // 加密
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_METHOD,
            $encryptionToken,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($ciphertext === false) {
            throw new \Exception(__('加密失败：%{1}', [openssl_error_string()]));
        }
        
        // 组合IV、tag和密文
        $encrypted = $iv . $tag . $ciphertext;
        
        // Base64编码
        return base64_encode($encrypted);
    }

    /**
     * 解密数据
     * 
     * @param string $encryptedData 加密的数据（base64编码）
     * @param string|null $version 版本号，如果为null则尝试所有有效token
     * @return array|string 解密后的数据
     * @throws \Exception
     */
    public function decrypt(string $encryptedData, ?string $version = null)
    {
        // Base64解码
        $encrypted = base64_decode($encryptedData, true);
        if ($encrypted === false) {
            throw new \Exception(__('无效的加密数据格式'));
        }
        
        // 提取IV、tag和密文
        $iv = substr($encrypted, 0, self::IV_LENGTH);
        $tag = substr($encrypted, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($encrypted, self::IV_LENGTH + self::TAG_LENGTH);
        
        if (strlen($iv) !== self::IV_LENGTH || strlen($tag) !== self::TAG_LENGTH) {
            throw new \Exception(__('无效的加密数据格式'));
        }
        
        // 获取token
        if ($version !== null) {
            // 使用指定版本号的token
            $token = $this->getTokenByVersion($version);
            if (!$token) {
                throw new \Exception(__('未找到版本号 %{1} 对应的加密token', [$version]));
            }
            $tokens = [$token];
        } else {
            // 尝试所有有效的token
            $tokens = $this->getValidTokens();
        }
        
        // 尝试使用每个token解密
        $lastError = null;
        foreach ($tokens as $tokenData) {
            // 如果$tokenData是数组，转换为对象
            if (is_array($tokenData)) {
                /** @var PixelEncryptionToken $tokenModel */
                $tokenModel = ObjectManager::getInstance(PixelEncryptionToken::class);
                $token = clone $tokenModel;
                $token->setData($tokenData);
            } else {
                $token = $tokenData;
            }
            
            $encryptionToken = $token->getEncryptionToken();
            
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER_METHOD,
                $encryptionToken,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($plaintext !== false) {
                // 尝试解析JSON
                $decoded = json_decode($plaintext, true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : $plaintext;
            }
            
            $lastError = openssl_error_string();
        }
        
        throw new \Exception(__('解密失败：%{1}', [$lastError ?? '无法使用任何token解密']));
    }

    /**
     * 获取当前版本号的token
     * 
     * @return PixelEncryptionToken|null
     */
    public function getCurrentVersionToken(): ?PixelEncryptionToken
    {
        /** @var PixelEncryptionToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(PixelEncryptionToken::class);
        return $tokenModel->getCurrentVersionToken();
    }

    /**
     * 根据版本号获取token
     * 
     * @param string $version 版本号
     * @return PixelEncryptionToken|null
     */
    public function getTokenByVersion(string $version): ?PixelEncryptionToken
    {
        /** @var PixelEncryptionToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(PixelEncryptionToken::class);
        return $tokenModel->findByVersion($version);
    }

    /**
     * 获取所有有效的token（未过期且未删除）
     * 
     * @return array
     */
    public function getValidTokens(): array
    {
        /** @var PixelEncryptionToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(PixelEncryptionToken::class);
        return $tokenModel->getValidTokens();
    }

    /**
     * 为指定版本号生成新token
     * 
     * @param string $version 版本号
     * @return PixelEncryptionToken
     * @throws \Exception
     */
    public function generateTokenForVersion(string $version): PixelEncryptionToken
    {
        /** @var PixelEncryptionToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(PixelEncryptionToken::class);
        
        // 检查是否已存在该版本号的token
        $existing = $tokenModel->findByVersion($version);
        if ($existing && $existing->getTokenId()) {
            return $existing;
        }
        
        // 生成新的加密token（32字节，256位）
        $encryptionToken = bin2hex(random_bytes(32));
        
        // 计算过期时间（90天后）
        $createdAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));
        
        // 保存token
        $tokenModel->reset()
            ->setVersion($version)
            ->setEncryptionToken($encryptionToken)
            ->setCreatedAt($createdAt)
            ->setExpiresAt($expiresAt)
            ->setIsDeleted(false)
            ->setDeletedAt(null)
            ->save();
        
        // 标记90天前的旧token为已删除
        $this->markOldTokensAsDeleted();
        
        return $tokenModel;
    }

    /**
     * 标记90天前的旧token为已删除
     * 
     * @return int 标记的token数量
     */
    public function markOldTokensAsDeleted(): int
    {
        /** @var PixelEncryptionToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(PixelEncryptionToken::class);
        return $tokenModel->markOldTokensAsDeleted();
    }
}

