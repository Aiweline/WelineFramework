<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Security\SecurityHeaderPolicyService;

/**
 * 可缓存的 CSP 策略文档（单独加载一次）。
 *
 * URL: /api/framework/csp-policy
 * 浏览器无法用 URL 自动 enforcement（policy-uri 已废止）；本端点供：
 * - 运维/CDN 读取当前有效策略
 * - 与 meta 交付配合做 ETag 校验
 * - 配置预览
 */
class CspPolicy extends FrontendRestController
{
    public function getIndex(): string
    {
        $service = new SecurityHeaderPolicyService();
        $csp = $service->resolveCurrentDocumentCsp();
        $etag = '"' . \hash('sha256', $csp) . '"';
        $inm = \trim((string)(WelineEnv::server('HTTP_IF_NONE_MATCH', '') ?? ''));

        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->setHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=60');
        $response->setHeader('ETag', $etag);
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Weline-Csp-Delivery', $service->cspDelivery());

        if ($inm !== '' && \trim($inm, '"') === \trim($etag, '"')) {
            $response->setHttpResponseCode(304);
            $response->setBody('');

            return '';
        }

        $response->setHttpResponseCode(200);
        $response->setBody($csp);

        return $csp;
    }
}
