<?php

declare(strict_types=1);

namespace Weline\Backend\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 后台 API 控制器初始化前 Observer
 * 
 * 注意：主要的 API 认证逻辑已由 Weline\Api\Observer\ApiControllerInitBefore 处理。
 * 此 Observer 作为简化的后台 Session 检查，用于非 Token 认证场景。
 */
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
        // WLS 兼容：从 ObjectManager 获取当前请求的 Request 实例
        // Observer 实例在 WLS 中是单例，$this->request 可能指向旧请求
        $this->request = ObjectManager::getInstance(Request::class);
        // 只处理API请求
        if (!$this->request->isApi() && !$this->request->isApiBackend()) {
            return;
        }

        // 使用统一的 SessionFactory 创建后台认证 Session
        /** @var AuthenticatedSessionInterface $backendSession */
        $backendSession = SessionFactory::getInstance()->createBackendSession();
        
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
        // 检查是否已登录（Session 认证）
        // 注意：Token 认证由 Weline\Api\Observer\ApiControllerInitBefore 处理
        if (!$backendSession->isLoggedIn()) {
            // 使用 ResponseTerminateException 替代 exit()，确保 WLS 兼容
            throw new \Weline\Framework\Http\ResponseTerminateException(
                401,
                \json_encode(['code' => 401, 'msg' => __('请先登录'), 'data' => ''], JSON_UNESCAPED_UNICODE),
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        }

        // 检查用户状态
        $user = $backendSession->getUser();
        if (!$user || (\method_exists($user, 'getIsEnabled') && !$user->getIsEnabled())) {
            // 使用 ResponseTerminateException 替代 exit()，确保 WLS 兼容
            throw new \Weline\Framework\Http\ResponseTerminateException(
                403,
                \json_encode(['code' => 403, 'msg' => __('用户已被禁用'), 'data' => ''], JSON_UNESCAPED_UNICODE),
                ['Content-Type' => 'application/json; charset=utf-8']
            );
        }
    }
} 