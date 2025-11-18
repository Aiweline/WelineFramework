<?php

namespace Weline\Backend\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class ApiControllerInitBefore implements ObserverInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 只处理API请求
        if (!$this->request->isApi() && !$this->request->isApiBackend()) {
            return;
        }

        // 获取API Session实例，它会自动尝试token登录
        $apiSession = ObjectManager::getInstance(\Weline\Framework\App\Session\BackendApiSession::class);
        
        // 如果是API认证相关的接口，不需要验证登录状态
        $currentUrl = $this->request->getRouteUrlPath();
        $authUrls = [
            'backend/api/auth/login',
            'backend/api/auth/refresh',
            'backend/api/auth/token-info'
        ];
        if (in_array($currentUrl, $authUrls)) {
            return;
        }
        // 检查是否已登录
        if (!$apiSession->isLogin()) {
            // 返回401未授权错误
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'code' => 401,
                'msg' => __('请先登录'),
                'data' => ''
            ]);
            exit;
        }

        // 检查用户状态
        $user = $apiSession->getApiUser();
        if (!$user || !$user->getIsEnabled()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'code' => 403,
                'msg' => __('用户已被禁用'),
                'data' => ''
            ]);
            exit;
        }
    }
} 