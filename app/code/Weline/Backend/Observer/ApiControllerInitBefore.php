<?php

declare(strict_types=1);

namespace Weline\Backend\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\PublicApiAuthRouteMatcher;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 后台 API 控制器初始化前 Observer
 *
 * 注意：主要的 Token API 认证由 Weline\Api\Observer\ApiControllerInitBefore 处理。
 * 此 Observer 仅在 rest_backend 下做 Session 兜底；公开 Auth 路由必须放行。
 */
class ApiControllerInitBefore implements ObserverInterface
{
    private Request $request;
    private PublicApiAuthRouteMatcher $publicApiAuthRouteMatcher;

    public function __construct(
        Request $request,
        PublicApiAuthRouteMatcher $publicApiAuthRouteMatcher,
    ) {
        $this->request = $request;
        $this->publicApiAuthRouteMatcher = $publicApiAuthRouteMatcher;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // WLS 兼容：从 ObjectManager 获取当前请求的 Request 实例
        // Observer 实例在 WLS 中是单例，$this->request 可能指向旧请求
        $this->request = ObjectManager::getInstance(Request::class);

        // 仅后台 REST；前台 REST Token 认证由 Weline_Api 处理
        if (!$this->request->isApiBackend()) {
            return;
        }

        if ($this->publicApiAuthRouteMatcher->matches($this->request)) {
            return;
        }

        // 使用统一的 SessionFactory 创建后台认证 Session
        /** @var AuthenticatedSessionInterface $backendSession */
        $backendSession = SessionFactory::getInstance()->createBackendSession();

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
