<?php

declare(strict_types=1);

namespace Weline\Customer\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Customer\Session\CustomerSession;

/**
 * 自动登录Observer
 * 在用户访问时检查"记住我"token，自动登录
 */
class AutoLogin implements ObserverInterface
{
    private CustomerSession $session;

    public function __construct(
        CustomerSession $session
    ) {
        $this->session = $session;
    }

    /**
     * 执行自动登录
     */
    public function execute(Event &$event): void
    {
        // 如果已经登录，跳过
        if ($this->session->isLogin()) {
            return;
        }

        // 获取token cookie
        $token = Cookie::get('w_ut');
        if (empty($token)) {
            return;
        }

        try {
            // 查找token记录
            /** @var CustomerToken $userToken */
            $userToken = ObjectManager::getInstance(CustomerToken::class);
            $userToken->where('token', $token)
                ->where('type', 'remember_me')
                ->find()
                ->fetch();

            // Token不存在
            if (!$userToken->getId()) {
                // 清除无效的cookie
                $this->clearTokenCookie();
                return;
            }

            // 检查token是否过期
            if ($userToken->isExpired()) {
                // 删除过期token
                $userToken->delete();
                $this->clearTokenCookie();
                return;
            }

            // 获取用户信息
            /** @var Customer $user */
            $user = ObjectManager::getInstance(Customer::class);
            $user->load($userToken->getUserId());

            // 用户不存在
            if (!$user->getId()) {
                $userToken->delete();
                $this->clearTokenCookie();
                return;
            }

            // 执行自动登录
            $this->session->login($user);
            $user->setSessionId($this->session->getSessionId())
                ->save();

            // 更新token最后使用时间
            $userToken->updateLastUsedAt()->save();

        } catch (\Exception $e) {
            // 发生错误时清除cookie，避免循环错误
            $this->clearTokenCookie();
        }
    }

    /**
     * 清除token cookie
     */
    private function clearTokenCookie(): void
    {
        Cookie::set('w_ut', '', -3600, ['path' => '/']);
    }
}
