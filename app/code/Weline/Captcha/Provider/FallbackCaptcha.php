<?php

declare(strict_types=1);

namespace Weline\Captcha\Provider;

use Weline\Captcha\Interface\CaptchaProviderInterface;
use Weline\Captcha\Service\LocalChallengeImage;

/**
 * 备用验证码提供者（图形验证码）
 */
class FallbackCaptcha implements CaptchaProviderInterface
{
    /**
     * @inheritDoc
     */
    public function generate(array $options = []): array
    {
        $length = \max(5, \min(6, (int)($options['length'] ?? 6)));
        $code = $this->generateRandomCode($length);
        $token = bin2hex(random_bytes(24));
        
        return [
            'code_hash' => password_hash(strtoupper($code), PASSWORD_DEFAULT),
            'image' => LocalChallengeImage::dataUri($code),
            'token' => $token,
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function verify(string $token, string $code): bool
    {
        // 验证逻辑由 CaptchaService 处理
        // 这里只是接口实现
        return false;
    }
    
    /**
     * 生成随机验证码
     * 
     * @param int $length 长度
     * @return string
     */
    protected function generateRandomCode(int $length = 4): string
    {
        $characters = '23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $code;
    }
}
