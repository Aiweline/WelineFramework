<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Multipass\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Multipass\Model\MultipassSite;

/**
 * Multipass 服务类
 * 实现类似 Shopify Multipass 的加密和验证功能
 */
class MultipassService
{
    /**
     * 生成 Multipass Token
     * 使用 AES-128-CBC 加密用户数据，严格按照 Shopify Multipass 方式实现
     * 
     * @param MultipassSite $site 站点配置
     * @param array $userData 用户数据 ['username' => string, 'email' => string, ...]
     * @return string 加密后的 token（URL安全的Base64编码）
     */
    public function generateToken(MultipassSite $site, array $userData): string
    {
        $secretKey = $site->getSecretKey();
        
        // 确保密钥长度为 16 字节（AES-128 需要）
        $key = $this->deriveKey($secretKey);
        
        // 添加时间戳到用户数据
        $userData['created_at'] = date('c'); // ISO 8601 格式
        
        // 将用户数据编码为 JSON
        $jsonData = json_encode($userData, JSON_UNESCAPED_UNICODE);
        
        // 生成随机 IV（初始化向量）
        $iv = openssl_random_pseudo_bytes(16);
        
        // 使用 AES-128-CBC 加密
        $encrypted = openssl_encrypt(
            $jsonData,
            'AES-128-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($encrypted === false) {
            throw new \RuntimeException('加密失败');
        }
        
        // 将 IV 和加密数据组合：IV + 加密数据
        $combined = $iv . $encrypted;
        
        // 生成 HMAC 签名
        $signature = hash_hmac('sha256', $combined, $key, true);
        
        // 组合：签名 + IV + 加密数据
        $final = $signature . $combined;
        
        // 使用 URL 安全的 Base64 编码
        return $this->base64UrlEncode($final);
    }

    /**
     * 验证并解密 Multipass Token
     * 
     * @param MultipassSite $site 站点配置
     * @param string $token 加密的 token
     * @return array|null 解密后的用户数据，失败返回 null
     */
    public function verifyToken(MultipassSite $site, string $token): ?array
    {
        try {
            $secretKey = $site->getSecretKey();
            $key = $this->deriveKey($secretKey);
            
            // 解码 Base64
            $decoded = $this->base64UrlDecode($token);
            if ($decoded === false || strlen($decoded) < 32 + 16) {
                return null; // 数据太短，无法包含签名和IV
            }
            
            // 提取签名（前32字节）
            $signature = substr($decoded, 0, 32);
            
            // 提取签名验证的数据（从第32字节开始）
            $combined = substr($decoded, 32);
            
            // 验证签名
            $expectedSignature = hash_hmac('sha256', $combined, $key, true);
            if (!hash_equals($signature, $expectedSignature)) {
                return null; // 签名验证失败
            }
            
            // 提取 IV（前16字节）
            $iv = substr($combined, 0, 16);
            
            // 提取加密数据（从第16字节开始）
            $encrypted = substr($combined, 16);
            
            // 解密
            $jsonData = openssl_decrypt(
                $encrypted,
                'AES-128-CBC',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($jsonData === false) {
                return null;
            }
            
            // 解析 JSON
            $userData = json_decode($jsonData, true);
            if (!is_array($userData)) {
                return null;
            }
            
            // 验证时间戳（可选，如果 token 有过期时间限制）
            // 这里可以根据业务需求添加过期时间检查
            
            // 移除时间戳，返回用户数据
            unset($userData['created_at']);
            
            return $userData;
            
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 从密钥派生加密密钥
     * 确保密钥长度为 16 字节（AES-128）
     * 
     * @param string $secretKey 原始密钥
     * @return string 派生后的密钥
     */
    private function deriveKey(string $secretKey): string
    {
        // 使用 SHA-256 哈希，然后取前16字节
        $hash = hash('sha256', $secretKey, true);
        return substr($hash, 0, 16);
    }

    /**
     * URL 安全的 Base64 编码
     * 
     * @param string $data 要编码的数据
     * @return string 编码后的字符串
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * URL 安全的 Base64 解码
     * 
     * @param string $data 要解码的数据
     * @return string|false 解码后的数据，失败返回 false
     */
    private function base64UrlDecode(string $data): string|false
    {
        // 添加填充
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}

