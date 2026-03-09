<?php

namespace Weline\Admin\Observer;

use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Backend\Model\BackendUserToken;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Admin\Observer\BackendWhitelistUrl;

class BackendControllerInitAfter implements ObserverInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }   

    private function getSession(): AuthenticatedSessionInterface
    {
        return SessionFactory::getInstance()->createBackendSession();
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $currentRoutePath = trim($this->request->getRouteUrlPath(), '/');
        # 检测记住我
        // 真实登录提交阶段不执行记住我自动登录，避免干扰本次账号密码登录流程。
        if ($currentRoutePath !== 'admin/login/post' && ($token = Cookie::get('w_ut')) && !$this->getSession()->getUserId()) {
            /**@var BackendUserToken $backendUserToken */
            $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
            $backendUserToken->where($backendUserToken::schema_fields_token, $token)->where($backendUserToken::schema_fields_type, 'admin_login_remember_me')->find()->fetch();
            # 存在正确的token，但是过期了
            if ($backendUserToken->getId() && $backendUserToken->getData($backendUserToken::schema_fields_token_expire_time) <= time()) {
                $this->invalidateRememberToken($backendUserToken);
                ObjectManager::getInstance(MessageManager::class)->addWarning(__('记住登录已过期，请重新登录！'));
                $this->clearRememberCookie();
                $this->getSession()->delete('remember_expire_time');
            } else if ($user_id = $backendUserToken->getId()) {
                # 存在正确的token，并且没有过期 直接登录
                $adminUser = ObjectManager::getInstance(BackendUser::class)->load($user_id);
                if ($adminUser->getId()) {
                    $this->getSession()->login($adminUser);
                    $expireAt = (int)$backendUserToken->getData($backendUserToken::schema_fields_token_expire_time);
                    if ($expireAt > \time()) {
                        $this->getSession()->set('remember_expire_time', $expireAt);
                    }
                    $adminUser->setSessionId($this->getSession()->getId())
                        ->setLoginIp($this->request->clientIP());
                    # 重置 尝试登录次数
                    $adminUser->resetAttemptTimes()->save();
                } else {
                    $this->invalidateRememberToken($backendUserToken);
                    $this->clearRememberCookie();
                    ObjectManager::getInstance(MessageManager::class)->addWarning(__('用户不存在！'));
                }
            } else {
                $this->clearRememberCookie();
                $this->getSession()->delete('remember_expire_time');
            }
        }
        # 设置referer
        if (!$this->request->isBackend()) {
            return;
        }
        // 绕过ajax请求
        if ($this->request->isAjax()) {
            return;
        }
        // 绕过ajax请求
        if ($this->request->isIframe()) {
            return;
        }
        $white_urls = BackendWhitelistUrl::white_urls;
        $white_urls[] = ['path' => 'admin/login/logout'];
        foreach ($white_urls as &$white_url) {
            $white_url = $white_url['path'];
        }
        // 白名单跳过验证
        if (!in_array($currentRoutePath, $white_urls) and !$this->request->getParam('isIframe')) {
            // 只存储菜单链接
            if (MenuUrlValidator::isMenuUrl($currentRoutePath)) {
                $this->getSession()->set('referer', $this->request->getUrlBuilder()->getCurrentUrl());
            }
        }
    }

    private function clearRememberCookie(): void
    {
        Cookie::set('w_ut', '', -1, ['path' => '/']);
        Cookie::set('w_ut', '', -1, ['path' => '/' . $this->request->getAreaRouter()]);
    }

    private function invalidateRememberToken(BackendUserToken $backendUserToken): void
    {
        if (!$backendUserToken->getId()) {
            return;
        }
        $backendUserToken->setData($backendUserToken::schema_fields_token, '')
            ->setData($backendUserToken::schema_fields_token_expire_time, 0)
            ->save();
    }
}
