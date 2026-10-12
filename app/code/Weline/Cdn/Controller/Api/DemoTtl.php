<?php

declare(strict_types=1);

namespace Weline\Cdn\Controller\Api;

use Weline\Frontend\Controller\FrontendRestController;

/**
 * CDN 注解 TTL 演示（API）。
 *
 * 仅用于验证 @Cdn → 收集 → 边缘推送链路；生产业务请勿依赖本路径。
 * 标记：WELINE_DEMO_TTL_777_API / edge TTL=777s。
 */
class DemoTtl extends FrontendRestController
{
    /**
     * GET 演示探针；CDN 表达式钉死演示路径。
     *
     * @Cdn expression='starts_with(http.request.uri.path, "/__weline_cdn_demo_ttl_api")' action='{"cache":{"ttl":777,"status_code":[200],"mode":"override_origin"}}' description="WELINE_DEMO_TTL_777_API" trigger=cron
     */
    public function getProbe(): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        // 源站头与注解 TTL 对齐，便于 Free 桥接（bypass_by_default）侧抽检。
        $response->setHeader('Cache-Control', 'public, max-age=777');
        $response->setHeader('CDN-Cache-Control', 'public, max-age=777');
        $response->setHeader('Cloudflare-CDN-Cache-Control', 'public, max-age=777');
        $response->setHeader('X-Weline-Demo-Ttl', '777');

        return \json_encode([
            'ok' => true,
            'kind' => 'api',
            'marker' => 'WELINE_DEMO_TTL_777_API',
            'ttl_sec' => 777,
        ], \JSON_UNESCAPED_UNICODE);
    }
}
