<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 检查用户登录状态
 * 确保TwoFactorAuth应用只能由登录用户访问
 */
class CheckUserLogin implements ObserverInterface
{
    private AuthenticatedSessionInterface $session;
    private Request $request;
    private Response $response;

    public function __construct(
        Request $request,
        Response $response
    ) {
        $this->session = SessionFactory::getInstance()->createFrontendSession();
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * 执行登录检查
     */
    public function execute(\Weline\Framework\Event\Event &$event): void
    {
        // 获取当前路由路径
        $path = $this->request->getPathInfo();

        // 检查是否是TwoFactorAuth相关路径
        if ($this->isTwoFactorAuthPath($path)) {
            // 检查用户是否登录
            if (!$this->session->isLoggedIn()) {
                // 保存当前URL作为登录后的跳转地址
                $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
                $loginUrl = '/frontend/account/login?referer=' . urlencode($currentUrl);
                
                // 如果是API请求，返回JSON
                // 使用 ResponseTerminateException 替代 exit()，确保 WLS 兼容
                if ($this->isApiRequest($path)) {
                    throw new \Weline\Framework\Http\ResponseTerminateException(
                        401,
                        \json_encode(['success' => false, 'code' => 401, 'message' => '请先登录', 'redirect' => $loginUrl], JSON_UNESCAPED_UNICODE),
                        ['Content-Type' => 'application/json; charset=utf-8']
                    );
                }
                
                // 普通请求，重定向到登录页
                throw new \Weline\Framework\Http\RedirectException($loginUrl, 302);
            }
        }
    }

    /**
     * 检查是否是TwoFactorAuth相关路径
     */
    private function isTwoFactorAuthPath(string $path): bool
    {
        $twoFactorPaths = [
            '/twofa',
            '/frontend/twofa',
            '/api/2fa'
        ];

        foreach ($twoFactorPaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查是否是API请求
     */
    private function isApiRequest(string $path): bool
    {
        return str_starts_with($path, '/api/');
    }
}

