<?php

declare(strict_types=1);

namespace Weline\Captcha\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Observer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Captcha\Service\CaptchaService;

/**
 * 登录验证码验证观察者
 */
class LoginCaptchaVerifyObserver extends Observer implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        $eventData = $this->getEvent()->getData();
        
        // 检查是否需要验证码
        $ip = (string)\w_env('server.remote_addr', '');
        /** @var CaptchaService $captchaService */
        $captchaService = ObjectManager::getInstance(CaptchaService::class);
        
        if ($captchaService->shouldShowCaptcha($ip)) {
            // 需要验证码，检查是否提供了验证码
            $token = $eventData['captcha_token'] ?? '';
            $code = $eventData['captcha_code'] ?? '';
            
            if (empty($token) || empty($code)) {
                throw new \Exception(__('需要验证码验证'));
            }
            
            if (!$captchaService->verify($token, $code, $ip)) {
                throw new \Exception(__('验证码错误'));
            }
        }
    }
}
