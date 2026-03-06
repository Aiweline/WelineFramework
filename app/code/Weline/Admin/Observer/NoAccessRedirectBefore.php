<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/2/2 17:13:52
 */

namespace Weline\Admin\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;

class NoAccessRedirectBefore implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var \Weline\Framework\Http\Request
     */
    private Request $request;

    function __construct(
        Request $request
    )
    {
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $isBackend = false;
        try {
            $isBackend = $this->request->isBackend();
        } catch (\Throwable $e) {
            throw $e;
        }
        
        if ($isBackend) {
            try {
                // 使用当前请求同源登录 URL，避免与 Cookie 域不一致导致 admin ↔ login 循环重定向
                $loginUrl = $this->getBackendLoginUrlSameOrigin();
                $response = $this->request->getResponse();
                $response->redirect($loginUrl);
            } catch (\Throwable $e) {
                throw $e;
            }
        }
    }

    /**
     * 使用当前请求的 scheme+host 及后台路由前缀生成登录 URL（如 .../admin_xxx/CNY/zh_Hans_CN/admin/login）。
     */
    private function getBackendLoginUrlSameOrigin(): string
    {
        $pathPart = $this->getBackendPathWithPrefix('admin/login');
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = $this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: 'localhost';
        return $scheme . '://' . $host . $pathPart;
    }

    /**
     * 获取带后台路由前缀的路径；仅当 WELINE_AREA_ROUTE 已含后端 prefix 时使用，否则用 Env 拼接。
     */
    private function getBackendPathWithPrefix(string $path): string
    {
        $backendPrefix = \Weline\Framework\App\Env::getAreaRoutePrefix('backend');
        $areaRoute = $this->request->getServer('WELINE_AREA_ROUTE') ?? '';
        if ($areaRoute !== '' && $backendPrefix !== null && $backendPrefix !== ''
            && (str_starts_with($areaRoute, $backendPrefix . '/') || $areaRoute === $backendPrefix)) {
            return '/' . \trim($areaRoute, '/') . '/' . \ltrim($path, '/');
        }
        if ($backendPrefix !== null && $backendPrefix !== '') {
            $currency = $this->request->getServer('WELINE_USER_CURRENCY') ?? $_SERVER['WELINE_USER_CURRENCY'] ?? 'CNY';
            $language = $this->request->getServer('WELINE_USER_LANG') ?? $_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN';
            return '/' . $backendPrefix . '/' . $currency . '/' . $language . '/' . \ltrim($path, '/');
        }
        return $this->request->getUrlBuilder()->getBackendUrlPath($path);
    }
}