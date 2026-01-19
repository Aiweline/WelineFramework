<?php

declare(strict_types=1);

namespace Weline\Captcha\Provider;

use Weline\Captcha\Interface\CaptchaProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Captcha\Model\Config;

/**
 * Google reCAPTCHA v2 提供者
 */
class GoogleRecaptchaV2 implements CaptchaProviderInterface
{
    /**
     * @inheritDoc
     */
    public function generate(array $options = []): array
    {
        /** @var Config $config */
        $config = ObjectManager::getInstance(Config::class);
        $siteKey = $config->getConfig('google_recaptcha_v2_site_key', 'Weline_Captcha', 'frontend');
        
        $token = uniqid('recaptcha_v2_', true);
        
        return [
            'code' => '', // reCAPTCHA v2 不需要服务端生成验证码
            'image' => null,
            'token' => $token,
            'site_key' => $siteKey,
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function verify(string $token, string $code): bool
    {
        /** @var Config $config */
        $config = ObjectManager::getInstance(Config::class);
        $secretKey = $config->getConfig('google_recaptcha_v2_secret_key', 'Weline_Captcha', 'frontend');
        
        if (empty($secretKey) || empty($code)) {
            return false;
        }
        
        // 调用 Google reCAPTCHA API 验证
        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => $secretKey,
            'response' => $code,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $result = json_decode($response, true);
        
        return isset($result['success']) && $result['success'] === true;
    }
}
