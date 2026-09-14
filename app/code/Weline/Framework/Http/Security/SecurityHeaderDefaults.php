<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

/**
 * Env Global 安全响应头不可变基线默认值。
 *
 * 空串回退到此处：运行时必须输出可用 CSP；CORS 留空表示禁止跨域回显。
 */
final class SecurityHeaderDefaults
{
    /**
     * 后台模板依赖内联 style/script，以及主题内嵌 data:image 图标。
     *
     * worker-src 须显式放行 'self' + blob:：QueryBin 可用同源 Worker，
     * 也可在同源挂起时回退到 Blob Worker（CSP 缺 worker-src 时会回落到 script-src，禁止 blob:）。
     *
     * Framework 基线只保留自有面（'self' / data / blob / unsafe-inline 等）。
     * 业务第三方 / CDN（支付含 Stripe、人机、社媒登录、融媒体、分析、商品视频、DataTable CDN 等）
     * 必须由所属模块经 Extends `Security/Csp`（CspSourceContributionProviderInterface）贡献，
     * **不要**再写回本 Defaults。
     *
     * 字面量保持 ContentSecurityPolicyNormalizer::canonicalize 稳定形态（指令名排序）。
     */
    public const CSP = "connect-src 'self'; default-src 'self'; font-src 'self' data: https:; frame-src 'self'; img-src 'self' blob: data: https:; media-src 'self' blob: https:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; worker-src 'self' blob:";

    /**
     * Report-Only 默认关闭：与强制 CSP 相同时再输出会把响应头体积翻倍（约 +3KB），
     * 且不增加强制执行能力。需要观察期时再在 Env/Scope 显式配置不同策略。
     */
    public const CSP_REPORT_ONLY = '';

    /**
     * CSP 交付方式：
     * - header：写入 Content-Security-Policy 响应头（传统）
     * - meta：写入 HTML `<meta http-equiv>`，HTTP 头不再携带大 CSP（文档加载一次即可）
     */
    public const CSP_DELIVERY_HEADER = 'header';

    public const CSP_DELIVERY_META = 'meta';

    public const CSP_DELIVERY = self::CSP_DELIVERY_META;

    /**
     * 空 = 不输出 Access-Control-Allow-Origin（最严安全默认）。
     */
    public const CORS_ORIGINS = '';
}
