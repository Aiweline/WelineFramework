<?php

declare(strict_types=1);

namespace Weline\Captcha\Interface;

/**
 * 验证码提供者接口
 */
interface CaptchaProviderInterface
{
    /**
     * 生成验证码
     * 
     * @param array $options 选项
     * @return array ['code' => string, 'image' => string|null, 'token' => string]
     */
    public function generate(array $options = []): array;
    
    /**
     * 验证验证码
     * 
     * @param string $token 令牌
     * @param string $code 验证码
     * @return bool
     */
    public function verify(string $token, string $code): bool;
}
