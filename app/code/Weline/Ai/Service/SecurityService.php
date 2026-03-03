<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Cache\Contract\CachePoolInterface;

/**
 * 安全服务
 * 
 * 功能：
 * - API密钥验证
 * - 速率限制
 * - 内容安全检查
 * - 访问控制
 */
class SecurityService
{
    /**
     * @var CachePoolInterface
     */
    private CachePoolInterface $cache;

    /**
     * 速率限制配置
     */
    private const RATE_LIMIT_PER_MINUTE = 60;
    private const RATE_LIMIT_PER_HOUR = 1000;
    private const RATE_LIMIT_PER_DAY = 10000;

    /**
     * 构造函数
     * 
     * @param CachePoolInterface $cache
     */
    public function __construct(CachePoolInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * 验证API密钥
     * 
     * @param string $apiKey
     * @return array 返回用户信息和配额
     * @throws Exception
     */
    public function validateApiKey(string $apiKey): array
    {
        // 检查格式
        if (!preg_match('/^sk-[a-zA-Z0-9]{64}$/', $apiKey)) {
            throw new Exception('无效的API密钥格式');
        }

        // 从缓存获取
        $cacheKey = 'ai_api_key_' . md5($apiKey);
        $cached = $this->cache->get($cacheKey);
        
        if ($cached) {
            return json_decode($cached, true);
        }

        // TODO: 从数据库查询API密钥信息
        // 这里应该查询 ai_api_key 表
        
        throw new Exception('API密钥不存在或已失效');
    }

    /**
     * 检查速率限制
     * 
     * @param string $identifier 识别符（用户ID、IP等）
     * @param string $action 操作类型
     * @return bool
     * @throws Exception
     */
    public function checkRateLimit(string $identifier, string $action = 'generate'): bool
    {
        $now = time();

        // 检查每分钟限制
        $minuteKey = "rate_limit_{$action}_minute_{$identifier}_" . floor($now / 60);
        $minuteCount = (int)$this->cache->get($minuteKey) ?: 0;
        
        if ($minuteCount >= self::RATE_LIMIT_PER_MINUTE) {
            throw new Exception('请求过于频繁，请稍后再试（每分钟限制）');
        }

        // 检查每小时限制
        $hourKey = "rate_limit_{$action}_hour_{$identifier}_" . floor($now / 3600);
        $hourCount = (int)$this->cache->get($hourKey) ?: 0;
        
        if ($hourCount >= self::RATE_LIMIT_PER_HOUR) {
            throw new Exception('今日请求次数已达上限（每小时限制）');
        }

        // 检查每天限制
        $dayKey = "rate_limit_{$action}_day_{$identifier}_" . date('Ymd');
        $dayCount = (int)$this->cache->get($dayKey) ?: 0;
        
        if ($dayCount >= self::RATE_LIMIT_PER_DAY) {
            throw new Exception('今日请求次数已达上限（每天限制）');
        }

        // 增加计数
        $this->cache->set($minuteKey, $minuteCount + 1, 60);
        $this->cache->set($hourKey, $hourCount + 1, 3600);
        $this->cache->set($dayKey, $dayCount + 1, 86400);

        return true;
    }

    /**
     * 检查内容安全
     * 
     * @param string $content
     * @return bool
     * @throws Exception
     */
    public function checkContentSafety(string $content): bool
    {
        // 检查内容长度
        if (mb_strlen($content) > 10000) {
            throw new Exception('内容过长，请分段处理');
        }

        // 敏感词检查
        $sensitiveWords = $this->getSensitiveWords();
        foreach ($sensitiveWords as $word) {
            if (stripos($content, $word) !== false) {
                throw new Exception('内容包含敏感词，请修改后重试');
            }
        }

        // SQL注入检查
        $sqlPatterns = [
            '/(\bSELECT\b.*\bFROM\b)/i',
            '/(\bINSERT\b.*\bINTO\b)/i',
            '/(\bUPDATE\b.*\bSET\b)/i',
            '/(\bDELETE\b.*\bFROM\b)/i',
            '/(\bDROP\b.*\bTABLE\b)/i',
        ];

        foreach ($sqlPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new Exception('内容包含非法字符，请修改后重试');
            }
        }

        return true;
    }

    /**
     * 检查用户权限
     * 
     * @param int $userId
     * @param string $permission
     * @return bool
     */
    public function checkPermission(int $userId, string $permission): bool
    {
        // TODO: 实现权限检查逻辑
        // 这里应该从数据库或缓存中获取用户权限
        
        return true;
    }

    /**
     * 记录安全事件
     * 
     * @param string $event
     * @param array $data
     * @return void
     */
    public function logSecurityEvent(string $event, array $data = []): void
    {
        // 记录到日志
        w_log_warning(sprintf(
            "Security Event: %s | Data: %s | Time: %s",
            $event,
            json_encode($data),
            date('Y-m-d H:i:s')
        ));
    }

    /**
     * 获取敏感词列表
     * 
     * @return array
     */
    private function getSensitiveWords(): array
    {
        // 从缓存获取
        $cacheKey = 'ai_sensitive_words';
        $cached = $this->cache->get($cacheKey);
        
        if ($cached) {
            return json_decode($cached, true);
        }

        // 默认敏感词列表
        $words = [
            // 这里应该从配置文件或数据库加载
        ];

        // 缓存1小时
        $this->cache->set($cacheKey, json_encode($words), 3600);

        return $words;
    }

    /**
     * 生成API密钥
     * 
     * @return string
     */
    public static function generateApiKey(): string
    {
        return 'sk-' . bin2hex(random_bytes(32));
    }

    /**
     * 验证IP白名单
     * 
     * @param string $ip
     * @param array $whitelist
     * @return bool
     */
    public function checkIpWhitelist(string $ip, array $whitelist = []): bool
    {
        if (empty($whitelist)) {
            return true;
        }

        foreach ($whitelist as $allowed) {
            if ($this->matchIp($ip, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 匹配IP地址
     * 
     * @param string $ip
     * @param string $pattern
     * @return bool
     */
    private function matchIp(string $ip, string $pattern): bool
    {
        // 支持通配符和CIDR notation
        if ($ip === $pattern) {
            return true;
        }

        if (strpos($pattern, '*') !== false) {
            $regex = str_replace('.', '\.', $pattern);
            $regex = str_replace('*', '\d+', $regex);
            return (bool)preg_match('/^' . $regex . '$/', $ip);
        }

        return false;
    }
}

