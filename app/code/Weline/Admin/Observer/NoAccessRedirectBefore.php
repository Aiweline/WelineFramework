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

use Weline\Admin\Service\BackendLoginReturnUrlService;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
class NoAccessRedirectBefore implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var \Weline\Framework\Http\Request
     */
    private Request $request;
    private ?BackendLoginReturnUrlService $returnUrlService;

    function __construct(
        Request $request,
        ?BackendLoginReturnUrlService $returnUrlService = null
    )
    {
        $this->request = $request;
        $this->returnUrlService = $returnUrlService;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // WLS 下 Observer 可能复用旧实例，这里强制切到当前请求上下文。
        $this->request = ObjectManager::getInstance(Request::class);

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
                // 通过 URL 参数传递原因，登录页当次请求显示一次；不写 Session，避免刷新后仍重复显示
                $loginUrl = $this->getReturnUrlService()->buildLoginUrlWithReturn(
                    $this->getBackendLoginUrlSameOrigin(),
                    $this->request->getUrlBuilder()->getCurrentUrl(),
                    (string)$reason
                );
                $response = $this->request->getResponse();
                $response->redirect($loginUrl);
            } catch (\Throwable $e) {
                throw $e;
            }
        }
    }

    /**
     * 使用当前请求的 scheme+host 及后台路由前缀生成登录 URL。
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
            return '/' . \trim($backendPrefix, '/') . '/' . \ltrim($path, '/');
        }
        return $this->request->getUrlBuilder()->getBackendUrlPath($path);
    }

    private function getReturnUrlService(): BackendLoginReturnUrlService
    {
        if (!$this->returnUrlService instanceof BackendLoginReturnUrlService) {
            $this->returnUrlService = ObjectManager::getInstance(BackendLoginReturnUrlService::class);
        }

        return $this->returnUrlService;
    }
}
