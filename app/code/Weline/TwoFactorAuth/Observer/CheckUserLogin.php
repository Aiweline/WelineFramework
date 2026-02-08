<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Frontend\Session\FrontendUserSession;

/**
 * 检查用户登录状态
 * 确保TwoFactorAuth应用只能由登录用户访问
 */
class CheckUserLogin implements ObserverInterface
{
    private FrontendUserSession $session;
    private Request $request;
    private Response $response;

    public function __construct(
        FrontendUserSession $session,
        Request $request,
        Response $response
    ) {
        $this->session = $session;
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
            if (!$this->session->isLogin()) {
                // 保存当前URL作为登录后的跳转地址
                $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
                $loginUrl = '/frontend/account/login?referer=' . urlencode($currentUrl);
                
                // 如果是API请求，返回JSON
                if ($this->isApiRequest($path)) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'code' => 401,
                        'message' => '请先登录',
                        'redirect' => $loginUrl
                    ]);
                    exit;
                }
                
                // 普通请求，重定向到登录页
                header('Location: ' . $loginUrl);
                exit;
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

