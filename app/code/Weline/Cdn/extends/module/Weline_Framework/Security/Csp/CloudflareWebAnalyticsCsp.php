<?php

declare(strict_types=1);

namespace Weline\Cdn\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;

/**
 * Cloudflare Web Analytics / RUM beacon（边缘自动注入或手动 snippet）。
 *
 * 归属 Weline_Cdn：CF 边缘能力与 CSP 放行同模块维护。
 *
 * script: https://static.cloudflareinsights.com/beacon.min.js
 * connect: 非代理/手动 → cloudflareinsights.com；代理自动注入 → 同源 /cdn-cgi/rum（基线已有 'self'）
 *
 * Zero-arg constructor required by CspSourceContributionRegistry (Extends).
 */
final class CloudflareWebAnalyticsCsp implements CspSourceContributionProviderInterface
{
    public function contribution(): CspSourceContribution
    {
        return new CspSourceContribution([
            'script-src' => [
                'https://static.cloudflareinsights.com',
            ],
            'connect-src' => [
                'https://cloudflareinsights.com',
            ],
        ]);
    }
}
