<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Framework\Http\Url;

/**
 * 运营台车道深链：跳转到域后台时携带当前 website 范围。
 */
final class OpsLaneDeepLink
{
    /**
     * @param array<string, scalar|null> $extra
     */
    public static function backend(Url $url, string $path, int $websiteId, array $extra = []): string
    {
        $params = array_merge(
            ['website_id' => max(0, $websiteId)],
            $extra,
        );

        return $url->getBackendUrl($path, $params);
    }

    /**
     * Visitor Pixel Dashboard 同时认 website_id / websiteId。
     */
    public static function visitorDashboard(Url $url, int $websiteId): string
    {
        $websiteId = max(0, $websiteId);

        return self::backend($url, 'visitor/backend/pixel-dashboard', $websiteId, [
            'websiteId' => $websiteId,
        ]);
    }

    /**
     * 社媒后台以 scope_* / scope_key 解析当前站。
     */
    public static function socialAdmin(Url $url, int $websiteId): string
    {
        $websiteId = max(0, $websiteId);

        return self::backend($url, 'weline_social/backend/social', $websiteId, [
            'scope_type' => 'website',
            'scope_id' => $websiteId,
            'child_scope_type' => 'website_default',
            'child_scope_id' => 0,
            'scope_key' => 'website:' . $websiteId . ':website_default:0',
            'tab' => 'accounts',
        ]);
    }

    /**
     * 网站编辑（Admin Website）。
     */
    public static function websiteEdit(Url $url, int $websiteId): string
    {
        $websiteId = max(0, $websiteId);

        return self::backend($url, 'websites/admin/website/edit', $websiteId, [
            'id' => $websiteId,
        ]);
    }
}
