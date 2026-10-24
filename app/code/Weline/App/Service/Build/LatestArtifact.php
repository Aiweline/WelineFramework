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

        $items = $result->getItems();
        if (!$items) {
            return $result->getData(AppArtifact::schema_fields_ID) ? clone $result : null;
        }

        $latest = null;
        foreach ($items as $item) {
            if (!$item instanceof AppArtifact
                || $item->getData(AppArtifact::schema_fields_platform) !== $platform
                || $item->getData(AppArtifact::schema_fields_status) !== AppArtifact::STATUS_RELEASED
            ) {
                continue;
            }
            if (!$latest
                || (int)$item->getData(AppArtifact::schema_fields_build_ts) > (int)$latest->getData(AppArtifact::schema_fields_build_ts)
            ) {
                $latest = clone $item;
            }
        }

        return $latest;
    }
}
