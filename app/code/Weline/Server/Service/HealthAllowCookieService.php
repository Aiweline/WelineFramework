<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\SessionFactory;

class HealthAllowCookieService
{
    public function __construct(
        private readonly Request $request
    ) {
    }

    public function issue(): array
    {
        $backendSession = SessionFactory::getInstance()->createBackendSession();
        if (!$backendSession->isLoggedIn()) {
            return ['success' => false, 'message' => __('请先登录后台')];
        }

        $deploy = (string) (Env::getInstance()->getConfig('deploy') ?? 'prod');
        if ($deploy !== 'dev' && $deploy !== 'development') {
            return ['success' => false, 'message' => __('仅开发模式可用，当前部署模式：%{1}', [$deploy])];
        }

        $secret = Env::getInstance()->getConfig('wls.health_cookie_secret');
        if ($secret === null || $secret === '') {
            return [
                'success' => false,
                'message' => __('请在 env.php 中配置 wls.health_cookie_secret（与 worker 校验一致）'),
            ];
        }

        $slot = (string) \floor(\time() / 3600);
        $token = \hash_hmac('sha256', 'wls_health_' . $slot, (string) $secret);
        $expire = \time() + 7200;
        $secure = $this->request->isSecure();

        $this->request->getResponse()->setCookie(
            'wls_health_allow',
            $token,
            $expire,
            '/',
            '',
            $secure,
            true,
            'Lax'
        );

        return [
            'success' => true,
            'message' => __('已设置健康检查放行 Cookie，本浏览器可访问 /_wls/health?detail=1，约 2 小时内有效'),
        ];
    }
}
