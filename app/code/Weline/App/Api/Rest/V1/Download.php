<?php

declare(strict_types=1);

namespace Weline\App\Api\Rest\V1;

use Weline\App\Model\AppArtifact;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Controller\FrontendRestController;

/**
 * 公开产物下发 API。缺 #[Acl] 即公开接口。仅读取登记行并重定向，不 stat 产物文件。
 */
class Download extends FrontendRestController
{
    /**
     * 下载中心二维码（SVG）。扫码落在 /app/download —— 手机按平台取包，不再直接甩产物目录。
     */
    public function getQr(): Response
    {
        $platform = (string)$this->request->getParam('platform', 'web');
        if (!in_array($platform, ['android', 'web', 'ios'], true)) {
            $platform = 'web';
        }
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = (string)(
            $this->request->getServer('HTTP_HOST')
            ?: $this->request->getServer('SERVER_NAME')
            ?: 'localhost'
        );
        // 二维码固定指向下载中心：扫码后按当次已发布产物给入口，不再绑死某个平台
        $target = $scheme . '://' . $host . '/app/download';
        try {
            $qrCode = new \Endroid\QrCode\QrCode(data: $target, size: 240, margin: 8);
            $svg = (new \Endroid\QrCode\Writer\SvgWriter())->write($qrCode)->getString();
        } catch (\Throwable $e) {
            return Response::text('QR unavailable: ' . $e->getMessage(), 500, 'text/plain; charset=utf-8');
        }

        $response = Response::text($svg, 200, 'image/svg+xml; charset=utf-8');
        $response->setHeader('Cache-Control', 'public, max-age=3600');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function getDownload(): string
    {
        $platform = (string)$this->request->getParam('platform', '');
        if (!in_array($platform, ['android', 'web', 'ios'], true)) {
            return $this->error(__('非法平台'), '', 400);
        }

        try {
            /** @var AppArtifact $model */
            $model = ObjectManager::getInstance(AppArtifact::class);
            $artifact = $model->clear()
                ->where(AppArtifact::schema_fields_platform, $platform)
                ->where(AppArtifact::schema_fields_status, AppArtifact::STATUS_RELEASED)
                ->order(AppArtifact::schema_fields_build_ts, 'DESC')
                ->find()
                ->fetch();

            if (!$artifact || !$artifact->getData('id')) {
                return $this->error(__('暂无产物'), '', 404);
            }

            $buildTs = (int)$artifact->getData(AppArtifact::schema_fields_build_ts);
            if ($buildTs <= 0) {
                return $this->error(__('产物未盖章（artifact_corrupt）'), '', 500);
            }

            $path = rtrim((string)$artifact->getData(AppArtifact::schema_fields_path), '/');
            // Web 版即在线商店（Flutter web 壳没有真实商品数据，不作为下载目标）。
            if ($platform === 'web') {
                $url = '/';
            } elseif ($platform === 'android') {
                // APK 由本接口流式下发（静态面大文件在 HTTP/2 下会被截断，见 App doc）
                $apk = PUB . $path . DIRECTORY_SEPARATOR . 'app.apk';
                if (!is_file($apk)) {
                    return $this->error(__('产物文件缺失'), '', 404);
                }
                $this->request->getResponse()->download(
                    $apk,
                    'weline-app-' . (string)$artifact->getData(AppArtifact::schema_fields_version) . '.apk'
                );
            } elseif ($platform === 'ios') {
                // iOS 出未签名 IPA（自签侧载），与 APK 同样走静态面
                $url = '/static/weline-app/' . basename(trim($path, '/')) . '/app.ipa';
            } else {
                $url = '/app/download?platform=' . \urlencode($platform);
            }

            $response = $this->request->getResponse();
            $response->setHeader('X-Weline-App-Sha256', (string)$artifact->getData(AppArtifact::schema_fields_sha256));
            try {
                $response->redirect($url, 302);
            } catch (\Weline\Framework\Http\ResponseTerminateException $terminate) {
                throw $terminate;
            }
        } catch (\Weline\Framework\Http\ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $e) {
            return $this->error(__('获取下载失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
}
