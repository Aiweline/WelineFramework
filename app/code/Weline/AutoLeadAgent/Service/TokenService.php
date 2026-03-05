<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Service;

use Weline\AutoLeadAgent\Model\AgentToken;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Exception;

/**
 * Token服务类
 * 
 * 负责生成、验证和管理Agent Token
 */
class TokenService
{
    /**
     * JWT密钥（应该从配置中读取）
     */
    private string $secretKey;

    public function __construct()
    {
        // 从配置或环境变量读取密钥
        $this->secretKey = $_ENV['AUTO_LEAD_AGENT_SECRET_KEY'] ?? 'weline_auto_lead_agent_secret_key_change_in_production';
    }

    /**
     * 生成JWT Token
     * 
     * @param string $domain 授权域名
     * @param int $ttl 过期时间（秒），默认3600秒（1小时）
     * @param string $wasmHash WASM文件哈希值
     * @return string JWT Token
     * @throws Exception
     */
    public function generateToken(string $domain, int $ttl = 3600, string $wasmHash = ''): string
    {
        try {
            // 获取WASM哈希值
            if (empty($wasmHash)) {
                /** @var \Weline\AutoLeadAgent\Service\WasmService $wasmService */
                $wasmService = ObjectManager::getInstance(\Weline\AutoLeadAgent\Service\WasmService::class);
                $wasmHash = $wasmService->getLatestHash();
            }

            // 计算过期时间
            $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
            $exp = time() + $ttl;

            // 创建JWT Payload
            $payload = [
                'domain' => $domain,
                'exp' => $exp,
                'wasm_hash' => $wasmHash,
                'iat' => time(),
                'iss' => 'Weline_AutoLeadAgent'
            ];

            // 生成JWT（简化版，使用base64编码）
            $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
            $payloadEncoded = base64_encode(json_encode($payload));
            $signature = hash_hmac('sha256', $header . '.' . $payloadEncoded, $this->secretKey, true);
            $signatureEncoded = base64_encode($signature);

            $token = $header . '.' . $payloadEncoded . '.' . $signatureEncoded;

            // 保存Token到数据库
            /** @var AgentToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(AgentToken::class);
            $tokenModel->clear()
                ->setData(AgentToken::schema_fields_TOKEN, $token)
                ->setData(AgentToken::schema_fields_DOMAIN, $domain)
                ->setData(AgentToken::schema_fields_EXPIRES_AT, $expiresAt)
                ->setData(AgentToken::schema_fields_WASM_HASH, $wasmHash)
                ->save();

            return $token;

        } catch (\Exception $e) {
            throw new Exception(__('生成Token失败：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 验证Token
     * 
     * @param string $token JWT Token
     * @param string $domain 当前域名
     * @return bool 是否有效
     */
    public function validateToken(string $token, string $domain): bool
    {
        try {
            // 解析Token
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return false;
            }

            [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

            // 验证签名
            $signature = base64_decode($signatureEncoded);
            $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secretKey, true);
            
            if (!hash_equals($signature, $expectedSignature)) {
                return false;
            }

            // 解析Payload
            $payload = json_decode(base64_decode($payloadEncoded), true);
            if (!$payload) {
                return false;
            }

            // 验证域名
            if ($payload['domain'] !== $domain) {
                return false;
            }

            // 验证过期时间
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return false;
            }

            // 验证数据库中的Token记录
            /** @var AgentToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(AgentToken::class);
            $tokenRecord = $tokenModel->clear()
                ->where(AgentToken::schema_fields_TOKEN, $token)
                ->where(AgentToken::schema_fields_DOMAIN, $domain)
                ->find()
                ->fetch();

            if (!$tokenRecord->getId()) {
                return false;
            }

            // 检查数据库中的过期时间
            $expiresAt = strtotime($tokenRecord->getData(AgentToken::schema_fields_EXPIRES_AT));
            if ($expiresAt < time()) {
                return false;
            }

            return true;

        } catch (\Exception $e) {
            w_log_error('TokenService validateToken error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取Token信息
     * 
     * @param string $token JWT Token
     * @return array|null Token信息，如果Token无效返回null
     */
    public function getTokenInfo(string $token): ?array
    {
        try {
            // 解析Token
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            $payloadEncoded = $parts[1];
            $payload = json_decode(base64_decode($payloadEncoded), true);
            
            if (!$payload) {
                return null;
            }

            // 从数据库获取完整信息
            /** @var AgentToken $tokenModel */
            $tokenModel = ObjectManager::getInstance(AgentToken::class);
            $tokenRecord = $tokenModel->clear()
                ->where(AgentToken::schema_fields_TOKEN, $token)
                ->find()
                ->fetch();

            if (!$tokenRecord->getId()) {
                return null;
            }

            return [
                'token_id' => $tokenRecord->getId(),
                'domain' => $payload['domain'] ?? $tokenRecord->getData(AgentToken::schema_fields_DOMAIN),
                'exp' => $payload['exp'] ?? strtotime($tokenRecord->getData(AgentToken::schema_fields_EXPIRES_AT)),
                'wasm_hash' => $payload['wasm_hash'] ?? $tokenRecord->getData(AgentToken::schema_fields_WASM_HASH),
                'expires_at' => $tokenRecord->getData(AgentToken::schema_fields_EXPIRES_AT),
                'created_at' => $tokenRecord->getData(AgentToken::schema_fields_CREATED_AT),
            ];

        } catch (\Exception $e) {
            w_log_error('TokenService getTokenInfo error: ' . $e->getMessage());
            return null;
        }
    }
}

