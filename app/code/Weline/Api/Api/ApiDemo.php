<?php

declare(strict_types=1);

namespace Weline\Api\Api;

use Weline\Api\Service\ApiDemoPackageService;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;

/**
 * 模块 source/api-demo 统一下载协助（公开文档下载；响应不得泄露 Token）。
 *
 * 浏览器入口：GET /api/api-demo/download?module={Vendor_Module}&demo={demo_id}&lang=php|js
 * （外层 /api 为 rest_frontend 区域前缀；匹配前剥落后为 api-demo/download，见路由别名。）
 */
class ApiDemo extends FrontendRestController
{
    public function getDownload(): Response
    {
        $module = \trim((string)$this->request->getParam('module', ''));
        $demo = \trim((string)$this->request->getParam('demo', ''));
        $lang = \strtolower(\trim((string)$this->request->getParam('lang', '')));

        /** @var ApiDemoPackageService $service */
        $service = ObjectManager::getInstance(ApiDemoPackageService::class);

        try {
            $content = $service->zipDemo($module, $demo, $lang);
        } catch (\InvalidArgumentException) {
            return Response::json(
                [
                    'ok' => false,
                    'error' => (string)__('Demo 不存在或参数无效'),
                ],
                404
            );
        } catch (\Throwable) {
            return Response::json(
                [
                    'ok' => false,
                    'error' => (string)__('生成 Demo 下载包失败'),
                ],
                500
            );
        }

        $filename = $demo . '-' . $lang . '-api-demo.zip';
        $response = Response::text($content, 200, 'application/zip');
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->setHeader('Content-Length', (string)\strlen($content));
        $response->setHeader('Cache-Control', 'no-store, max-age=0');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
