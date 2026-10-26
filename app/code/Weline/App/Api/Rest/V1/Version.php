<?php

declare(strict_types=1);

namespace Weline\App\Api\Rest\V1;

use Weline\App\Model\AppArtifact;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Controller\FrontendRestController;

/**
 * 公开版本检测 API。缺 #[Acl] 即公开接口。
 */
class Version extends FrontendRestController
{
    public function getVersion(): string
    {
        $platform = (string)$this->request->getParam('platform', '');
        if (!in_array($platform, ['android', 'web', 'ios'], true)) {
            return $this->error(__('非法平台'), '', 400);
        }

        try {
            $artifact = $this->latestReleased($platform);
            if (!$artifact || !$artifact->getData('id')) {
                return $this->success(__('暂无产物'), [
                    'version' => null,
                    'build_id' => null,
                    'url' => null,
                    'force_update' => false,
                ]);
            }

            return $this->success(__('获取版本成功'), [
                'version' => (string)$artifact->getData(AppArtifact::schema_fields_version),
                'build_id' => (string)$artifact->getData(AppArtifact::schema_fields_build_id),
                'build_ts' => (int)$artifact->getData(AppArtifact::schema_fields_build_ts),
                'sha256' => (string)$artifact->getData(AppArtifact::schema_fields_sha256),
                'size_bytes' => (int)$artifact->getData(AppArtifact::schema_fields_size_bytes),
                'url' => '/api/weline_app/rest/v1/download/download?platform=' . urlencode($platform),
                'force_update' => false,
                'changelog' => __((string)$artifact->getData(AppArtifact::schema_fields_changelog)),
            ]);
        } catch (\Throwable $e) {
            return $this->error(__('获取版本失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    private function latestReleased(string $platform): ?AppArtifact
    {
        /** @var AppArtifact $model */
        $model = ObjectManager::getInstance(AppArtifact::class);
        return $model->clear()
            ->where(AppArtifact::schema_fields_platform, $platform)
            ->where(AppArtifact::schema_fields_status, AppArtifact::STATUS_RELEASED)
            ->order(AppArtifact::schema_fields_build_ts, 'DESC')
            ->find()
            ->fetch();
    }
}
