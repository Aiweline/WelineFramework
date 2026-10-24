<?php

declare(strict_types=1);

namespace Weline\App\Api\Rest\V1;

use Weline\App\Model\AppArtifact;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Controller\FrontendRestController;

/**
 * 公开产物下发 API。缺 #[Acl] 即公开接口。仅读取登记行并重定向，不 stat 产物文件。
 */
class Download extends FrontendRestController
{
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
            $url = '/static/weline-app/' . basename(trim($path, '/'));
            $url .= $platform === 'android' ? '/app.apk' : '/entry.html';

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
