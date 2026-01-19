<?php

declare(strict_types=1);

namespace Weline\Captcha\Provider;

use Weline\Captcha\Interface\CaptchaProviderInterface;

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
        // 生成随机验证码
        $code = $this->generateRandomCode(4);
        $token = uniqid('captcha_', true);
        
        // TODO: 生成验证码图片
        // 这里可以生成图形验证码图片并返回 base64 编码
        
        return [
            'code' => $code,
            'image' => null, // 图形验证码的 base64 编码
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
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $code;
    }
}
