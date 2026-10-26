<?php
declare(strict_types=1);

namespace Weline\App\Service\Build;

/**
 * 应用商店链接（iOS）。
 *
 * iOS 不走本机产物流式下发：必须经 App Store 安装。上架后把链接配到
 * `WELINE_APP_IOS_STORE_URL`（env）或 env.php 的 `app.ios_store_url`，
 * 下载中心与二维码弹窗会自动出现 iOS 入口。
 */
final class StoreLinks
{
    public static function iosStoreUrl(): string
    {
        $url = \trim((string)(\getenv('WELINE_APP_IOS_STORE_URL') ?: ''));
        if ($url === '') {
            try {
                $url = \trim((string)\w_env('app.ios_store_url', ''));
            } catch (\Throwable) {
                $url = '';
            }
        }

        return $url !== '' && \preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }
}
