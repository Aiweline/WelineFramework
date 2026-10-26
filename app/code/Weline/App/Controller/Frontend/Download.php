<?php

declare(strict_types=1);

namespace Weline\App\Controller\Frontend;

use Weline\App\Service\Build\LatestArtifact;
use Weline\App\Service\Build\StoreLinks;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;

/**
 * 下载中心（店面）：/app/download
 *
 * 只呈现**已发布产物**：构建发布即自动出现版本/体积/时间与下载入口，
 * 未构建的平台整块不渲染（不写「构建中/待上架」这类占位）。
 * Web 版即在线商店，免安装；二维码固定指向本页，扫码后按当次构建的产物给入口。
 */
class Download extends FrontendController
{
    public function index(): Response
    {
        $latest = new LatestArtifact();
        $labels = ['android' => 'Android', 'ios' => 'iOS', 'web' => 'Web'];
        $platforms = [];
        $latestBuildTs = 0;
        foreach ($labels as $platform => $label) {
            $artifact = $latest->latest($platform);
            if (!$artifact || !$artifact->getData('id')) {
                continue;
            }
            $buildTs = (int)$artifact->getData('build_ts');
            $latestBuildTs = \max($latestBuildTs, $buildTs);
            $platforms[$platform] = [
                'label' => $label,
                'version' => (string)$artifact->getData('version'),
                'size_bytes' => (int)$artifact->getData('size_bytes'),
                'build_ts' => $buildTs,
                'path' => \trim((string)$artifact->getData('path'), '/'),
            ];
        }

        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = (string)(
            $this->request->getServer('HTTP_HOST')
            ?: $this->request->getServer('SERVER_NAME')
            ?: 'localhost'
        );
        $siteUrl = $scheme . '://' . $host;
        $qrUrl = $siteUrl . '/api/weline_app/rest/v1/download/qr';
        $storeUrl = '/';
        $builtAt = $latestBuildTs > 0 ? \date('Y-m-d H:i', $latestBuildTs) : '';
        $iosStoreUrl = StoreLinks::iosStoreUrl();
        $locale = '';
        try {
            $locale = \trim((string)\Weline\Framework\Env\WelineEnv::getLang());
        } catch (\Throwable) {
            $locale = '';
        }
        if ($locale === '') {
            $locale = 'zh_Hans_CN';
        }
        $htmlLang = \str_starts_with($locale, 'zh') ? 'zh-Hans' : \str_replace('_', '-', $locale);

        \ob_start();
        include BP . 'app/code/Weline/App/view/templates/frontend/download/index.phtml';

        $response = Response::text((string)\ob_get_clean(), 200, 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store, max-age=0');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
