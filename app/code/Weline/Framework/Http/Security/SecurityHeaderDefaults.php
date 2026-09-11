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
     * script/frame/connect 放行社媒、支付、
     * 主流 CDN 等大厂域名；img/font/media 对 https: 开放以便外链资源。
     * 业务专属 SDK 域名请经 Extends `Security/Csp`（CspSourceContributionProviderInterface）贡献。
     *
     * 字面量保持 ContentSecurityPolicyNormalizer::canonicalize 稳定形态（指令名排序）。
     */
    public const CSP = "connect-src 'self' https://api.stripe.com https://api.tiktok.com https://api.twitter.com https://cdn.jsdelivr.net https://cdn.syndication.twimg.com https://cdnjs.cloudflare.com https://connect.facebook.net https://graph.facebook.com https://open.weixin.qq.com https://region1.google-analytics.com https://unpkg.com https://www.google-analytics.com https://www.google.com https://www.googletagmanager.com https://www.gstatic.com https://www.linkedin.com https://www.paypal.com; default-src 'self'; font-src 'self' data: https:; frame-src 'self' https://js.stripe.com https://open.weixin.qq.com https://platform.twitter.com https://player.bilibili.com https://twitter.com https://www.facebook.com https://www.google.com https://www.gstatic.com https://www.instagram.com https://www.linkedin.com https://www.paypal.com https://www.tiktok.com https://www.youtube-nocookie.com https://www.youtube.com https://x.com; img-src 'self' blob: data: https:; media-src 'self' blob: https:; script-src 'self' 'unsafe-inline' https://ajax.googleapis.com https://apis.google.com https://cdn.jsdelivr.net https://cdn.syndication.twimg.com https://cdnjs.cloudflare.com https://connect.facebook.net https://graph.facebook.com https://js.stripe.com https://open.weixin.qq.com https://platform.linkedin.com https://platform.twitter.com https://player.bilibili.com https://res.wx.qq.com https://unpkg.com https://www.google-analytics.com https://www.google.com https://www.googletagmanager.com https://www.gstatic.com https://www.instagram.com https://www.paypal.com https://www.paypalobjects.com https://www.tiktok.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://fonts.gstatic.com; worker-src 'self' blob:";

    /**
     * 观察策略默认与强制 CSP 一致，避免配置页/基线留空。
     */
    public const CSP_REPORT_ONLY = self::CSP;

    /**
     * 空 = 不输出 Access-Control-Allow-Origin（最严安全默认）。
     */
    public const CORS_ORIGINS = '';
}
