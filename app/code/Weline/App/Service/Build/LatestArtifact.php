<?php
declare(strict_types=1);

namespace Weline\App\Service\Build;

use Weline\App\Model\AppArtifact;
use Weline\Framework\Manager\ObjectManager;

class LatestArtifact
{
    public const PLATFORMS = ['android', 'web', 'ios'];

    public function latest(string $platform): ?AppArtifact
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new \InvalidArgumentException(__('非法平台：%{1}', [$platform]));
        }

        /** @var AppArtifact $model */
        $model = ObjectManager::getInstance(AppArtifact::class);
        $result = $model->clear()
            ->where(AppArtifact::schema_fields_platform, $platform)
            ->where(AppArtifact::schema_fields_status, AppArtifact::STATUS_RELEASED)
            ->order(AppArtifact::schema_fields_build_ts, 'DESC')
            ->find()
            ->fetch();

        if (!$result) {
            return null;
        }

        // find() 已按 build_ts DESC + LIMIT 1 取到最新一条；
        // Model::getItems() 在多行/单行 fetch 后都恒为空，不可用于取列表。
        return $result->getData(AppArtifact::schema_fields_ID) ? clone $result : null;
    }
}
