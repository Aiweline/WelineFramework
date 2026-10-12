<?php

declare(strict_types=1);

namespace Weline\Cdn\Controller\Frontend;

use Weline\Framework\Controller\PcController;

/**
 * CDN 注解 TTL 演示（页面控制器）。
 *
 * 仅用于验证 @Cdn → 收集 → 边缘推送链路；生产业务请勿依赖本路径。
 * 标记：WELINE_DEMO_TTL_777_PAGE / edge TTL=777s。
 */
class DemoTtl extends PcController
{
    /**
     * @Cdn expression='starts_with(http.request.uri.path, "/__weline_cdn_demo_ttl_page")' action='{"cache":{"ttl":777,"status_code":[200],"mode":"override_origin"}}' description="WELINE_DEMO_TTL_777_PAGE" trigger=cron
     */
    public function probe(): string
    {
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->setHeader('Cache-Control', 'public, max-age=777');
        $response->setHeader('CDN-Cache-Control', 'public, max-age=777');
        $response->setHeader('Cloudflare-CDN-Cache-Control', 'public, max-age=777');
        $response->setHeader('X-Weline-Demo-Ttl', '777');

        return "WELINE_DEMO_TTL_777_PAGE ttl=777\n";
    }
}
