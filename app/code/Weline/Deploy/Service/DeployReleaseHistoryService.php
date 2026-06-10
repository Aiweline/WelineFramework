<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Deploy\Model\DeployRelease;
use Weline\Framework\Manager\ObjectManager;

/**
 * 发布历史记录 CRUD。
 */
class DeployReleaseHistoryService
{
    /**
     * 创建一条 running 状态的发布记录。
     */
    public function start(
        string $releaseId,
        string $trigger,
        string $refType,
        string $ref,
        ?string $deployVersionHint,
        ?string $gitTag
    ): DeployRelease {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $model->setData([
            DeployRelease::schema_fields_ID            => $releaseId,
            DeployRelease::schema_fields_DEPLOY_VERSION => $deployVersionHint ?? '',
            DeployRelease::schema_fields_GIT_REF_TYPE   => $refType,
            DeployRelease::schema_fields_GIT_REF        => $ref,
            DeployRelease::schema_fields_GIT_TAG        => $gitTag,
            DeployRelease::schema_fields_TRIGGER_TYPE   => $trigger,
            DeployRelease::schema_fields_TRIGGER_REF    => $ref,
            DeployRelease::schema_fields_STATUS         => 'running',
            DeployRelease::schema_fields_STARTED_AT     => time(),
            DeployRelease::schema_fields_IS_CURRENT     => 0,
        ]);
        $model->save();
        return $model;
    }

    /**
     * 标记成功。
     */
    public function markSuccess(
        string $releaseId,
        string $deployVersion,
        string $workerBuildId,
        string $gitCommit,
        ?string $gitBranch
    ): void {
        $now = time();
        /** @var DeployRelease $model */
        $model = $this->loadById($releaseId);
        if (!$model) {
            return;
        }
        $startedAt = (int)$model->getData(DeployRelease::schema_fields_STARTED_AT);
        $model->setData([
            DeployRelease::schema_fields_DEPLOY_VERSION  => $deployVersion,
            DeployRelease::schema_fields_WORKER_BUILD_ID => $workerBuildId,
            DeployRelease::schema_fields_GIT_COMMIT      => $gitCommit,
            DeployRelease::schema_fields_GIT_BRANCH      => $gitBranch ?? '',
            DeployRelease::schema_fields_STATUS          => 'success',
            DeployRelease::schema_fields_FINISHED_AT     => $now,
            DeployRelease::schema_fields_DURATION_MS     => ($startedAt > 0) ? (int)(($now - $startedAt) * 1000) : null,
            DeployRelease::schema_fields_IS_CURRENT      => 1,
        ]);
        $model->save();

        // 取消旧版本的 is_current 标记
        $this->clearCurrentExcept($releaseId);
    }

    /**
     * 标记失败。
     */
    public function markFailed(string $releaseId, string $errorMessage, string $outputTail = ''): void
    {
        $now = time();
        /** @var DeployRelease $model */
        $model = $this->loadById($releaseId);
        if (!$model) {
            return;
        }
        $startedAt = (int)$model->getData(DeployRelease::schema_fields_STARTED_AT);
        $model->setData([
            DeployRelease::schema_fields_STATUS         => 'failed',
            DeployRelease::schema_fields_FINISHED_AT    => $now,
            DeployRelease::schema_fields_DURATION_MS    => ($startedAt > 0) ? (int)(($now - $startedAt) * 1000) : null,
            DeployRelease::schema_fields_ERROR_MESSAGE  => $errorMessage,
            DeployRelease::schema_fields_OUTPUT_TAIL    => $outputTail,
        ]);
        $model->save();
    }

    /**
     * 获取当前生效版本。
     */
    public function getCurrent(): ?DeployRelease
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        return $model->reset()
            ->where(DeployRelease::schema_fields_IS_CURRENT, 1)
            ->where(DeployRelease::schema_fields_STATUS, 'success')
            ->select()
            ->fetchRow();
    }

    /**
     * 获取最近 N 条记录。
     *
     * @return DeployRelease[]
     */
    public function getRecent(int $limit = 20): array
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        return $model->reset()
            ->orderBy(DeployRelease::schema_fields_STARTED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
    }

    /**
     * 获取总数。
     */
    public function getCount(): int
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $result = $model->reset()->count()->fetchOne();
        return (int)$result;
    }

    private function loadById(string $releaseId): ?DeployRelease
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        return $model->reset()
            ->where(DeployRelease::schema_fields_ID, $releaseId)
            ->select()
            ->fetchRow();
    }

    private function clearCurrentExcept(string $releaseId): void
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $model->reset()
            ->where(DeployRelease::schema_fields_IS_CURRENT, 1)
            ->where(DeployRelease::schema_fields_ID, $releaseId, '!=')
            ->update([DeployRelease::schema_fields_IS_CURRENT => 0]);
    }
}
