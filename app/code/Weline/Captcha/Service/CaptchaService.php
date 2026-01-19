<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Captcha\Model\Config;
use Weline\Captcha\Model\CaptchaResult;
use Weline\Captcha\Model\FailedAttempt;
use Weline\Captcha\Interface\CaptchaProviderInterface;

/**
 * 验证码服务
 */
class CaptchaService
{
    /**
     * 生成验证码
     * 
     * @param string $type 验证码类型（image, math, etc.）
     * @param array $options 选项
     * @return array ['code' => string, 'image' => string|null, 'token' => string]
     */
    public function generate(string $type = 'image', array $options = []): array
    {
        /** @var Config $config */
        $config = ObjectManager::getInstance(Config::class);
        
        // 获取验证码提供者
        $provider = $this->getProvider($type);
        
        if (!$provider) {
            throw new \Exception(__('不支持的验证码类型: %{1}', [$type]));
        }
        
        // 生成验证码
        $result = $provider->generate($options);
        
        // 保存验证码结果
        /** @var CaptchaResult $captchaResult */
        $captchaResult = ObjectManager::getInstance(CaptchaResult::class);
        $captchaResult->clearData()
            ->setData('token', $result['token'] ?? '')
            ->setData('code', $result['code'] ?? '')
            ->setData('type', $type)
            ->setData('expires_at', date('Y-m-d H:i:s', time() + 300)) // 5分钟过期
            ->save();
        
        return $result;
    }
    
    /**
     * 验证验证码
     * 
     * @param string $token 验证码令牌
     * @param string $code 用户输入的验证码
     * @param string|null $ip IP地址
     * @return bool
     */
    public function verify(string $token, string $code, ?string $ip = null): bool
    {
        if (empty($token) || empty($code)) {
            return false;
        }
        
        /** @var CaptchaResult $captchaResult */
        $captchaResult = ObjectManager::getInstance(CaptchaResult::class);
        $captchaResult->load($token, 'token');
        
        if (!$captchaResult->getId()) {
            $this->recordFailedAttempt($ip);
            return false;
        }
        
        // 检查是否过期
        $expiresAt = $captchaResult->getData('expires_at');
        if ($expiresAt && strtotime($expiresAt) < time()) {
            $captchaResult->delete();
            $this->recordFailedAttempt($ip);
            return false;
        }
        
        // 验证码比较（不区分大小写）
        $storedCode = strtolower(trim($captchaResult->getData('code') ?? ''));
        $inputCode = strtolower(trim($code));
        
        if ($storedCode !== $inputCode) {
            $this->recordFailedAttempt($ip);
            $captchaResult->delete(); // 验证失败后删除
            return false;
        }
        
        // 验证成功，删除验证码记录
        $captchaResult->delete();
        
        return true;
    }
    
    /**
     * 记录失败尝试
     * 
     * @param string|null $ip IP地址
     * @return void
     */
    protected function recordFailedAttempt(?string $ip): void
    {
        if (!$ip) {
            return;
        }
        
        /** @var FailedAttempt $failedAttempt */
        $failedAttempt = ObjectManager::getInstance(FailedAttempt::class);
        $failedAttempt->clearData()
            ->setData('ip', $ip)
            ->setData('attempted_at', date('Y-m-d H:i:s'))
            ->save();
    }
    
    /**
     * 检查是否需要显示验证码（基于失败尝试次数）
     * 
     * @param string|null $ip IP地址
     * @param int $threshold 阈值（默认5次）
     * @return bool
     */
    public function shouldShowCaptcha(?string $ip, int $threshold = 5): bool
    {
        if (!$ip) {
            return false;
        }
        
        /** @var FailedAttempt $failedAttempt */
        $failedAttempt = ObjectManager::getInstance(FailedAttempt::class);
        
        // 统计最近1小时内的失败次数
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        $count = $failedAttempt->clear()
            ->where('ip', $ip)
            ->where('attempted_at', ['>=', $oneHourAgo])
            ->count();
        
        return $count >= $threshold;
    }
    
    /**
     * 获取验证码提供者
     * 
     * @param string $type 类型
     * @return CaptchaProviderInterface|null
     */
    protected function getProvider(string $type): ?CaptchaProviderInterface
    {
        // 根据类型返回相应的提供者
        // 这里可以根据配置动态加载提供者
        try {
            $providerClass = "Weline\\Captcha\\Provider\\" . ucfirst($type) . "Captcha";
            if (class_exists($providerClass)) {
                return ObjectManager::getInstance($providerClass);
            }
        } catch (\Exception $e) {
            // 忽略错误，返回默认提供者
        }
        
        // 返回默认提供者
        return ObjectManager::getInstance(\Weline\Captcha\Provider\FallbackCaptcha::class);
    }
    
    /**
     * 清理过期的验证码记录
     * 
     * @return int 清理的记录数
     */
    public function cleanExpiredCaptchas(): int
    {
        /** @var CaptchaResult $captchaResult */
        $captchaResult = ObjectManager::getInstance(CaptchaResult::class);
        
        $now = date('Y-m-d H:i:s');
        $expired = $captchaResult->clear()
            ->where('expires_at', ['<', $now])
            ->select()
            ->fetchArray();
        
        $count = 0;
        foreach ($expired as $item) {
            $captchaResult->load($item['id']);
            if ($captchaResult->delete()) {
                $count++;
            }
        }
        
        return $count;
    }
}
