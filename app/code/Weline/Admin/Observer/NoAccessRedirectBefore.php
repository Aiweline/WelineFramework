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
use Weline\Framework\Manager\MessageManager;

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
                $data = $event->getData('data') ?? [];
                $reason = $data['reason'] ?? '';
                [$title, $message] = $this->getMessageByReason($reason);
                // 将 302 原因写入 MessageManager，登录页通过 message 组件可看到
                MessageManager::setSingleError($message, $title, 'warning');
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
     * 根据 Weline_Acl::no_access_redirect_before 传入的 reason 返回对应标题与正文。
     * reason: not_logged_in | no_role | no_any_permission | no_permission_for_route | no_usable_permission
     *
     * @return array{0: string, 1: string} [title, message]
     */
    private function getMessageByReason(string $reason): array
    {
        switch ($reason) {
            case 'not_logged_in':
                return [__('未登录'), __('访问后台需要先登录。')];
            case 'no_role':
                return [__('无权限'), __('用户没有分配角色，请联系管理员。')];
            case 'no_any_permission':
                return [__('无权限'), __('您没有任何后台权限，请联系管理员。')];
            case 'no_permission_for_route':
                return [__('无权限'), __('您没有访问该页面的权限，请联系管理员。')];
            case 'no_usable_permission':
                return [__('无权限'), __('当前没有可用的访问入口，请重新登录或联系管理员。')];
            default:
                return [__('无权限'), __('您没有访问该页面的权限，请先登录或联系管理员。')];
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