<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Deploy\Model\DeployProjectProfile;
use Weline\Deploy\Model\DeployRelease;
use Weline\Framework\Manager\ObjectManager;

/**
 * Release history CRUD and WLS project-scoped release queries.
 */
class DeployReleaseHistoryService
{
    /**
     * Create a running release record.
     *
     * @param array<string, mixed> $context
     */
    public function start(
        string $releaseId,
        string $trigger,
        string $refType,
        string $ref,
        ?string $deployVersionHint,
        ?string $gitTag,
        array $context = []
    ): DeployRelease {
        $scope = $this->normalizeProjectContext($context);

        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $model->setData([
            DeployRelease::schema_fields_ID             => $releaseId,
            DeployRelease::schema_fields_DEPLOY_VERSION => $deployVersionHint ?? '',
            DeployRelease::schema_fields_PROFILE_KEY    => $scope['profile_key'] !== '' ? $scope['profile_key'] : null,
            DeployRelease::schema_fields_PROJECT_ID     => $scope['project_id'] !== '' ? $scope['project_id'] : null,
            DeployRelease::schema_fields_DOMAIN         => $scope['domain'] !== '' ? $scope['domain'] : null,
            DeployRelease::schema_fields_PROJECT_TYPE   => $scope['project_type'] !== '' ? $scope['project_type'] : null,
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
     * Mark a release as successful.
     */
    public function markSuccess(
        string $releaseId,
        string $deployVersion,
        string $workerBuildId,
        string $gitCommit,
        ?string $gitBranch
    ): void {
        $now = time();
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

        $this->clearCurrentExcept($model, $releaseId);
    }

    /**
     * Mark a release as failed.
     */
    public function markFailed(string $releaseId, string $errorMessage, string $outputTail = ''): void
    {
        $now = time();
        $model = $this->loadById($releaseId);
        if (!$model) {
            return;
        }

        $startedAt = (int)$model->getData(DeployRelease::schema_fields_STARTED_AT);
        $model->setData([
            DeployRelease::schema_fields_STATUS        => 'failed',
            DeployRelease::schema_fields_FINISHED_AT   => $now,
            DeployRelease::schema_fields_DURATION_MS   => ($startedAt > 0) ? (int)(($now - $startedAt) * 1000) : null,
            DeployRelease::schema_fields_ERROR_MESSAGE => $errorMessage,
            DeployRelease::schema_fields_OUTPUT_TAIL   => $outputTail,
        ]);
        $model->save();
    }

    /**
     * Get the global current release. Project-scoped current markers are
     * intentionally excluded so one child project cannot mask the host runtime.
     */
    public function getCurrent(): ?DeployRelease
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $result = $model->reset()
            ->where(DeployRelease::schema_fields_PROFILE_KEY, null, 'is null')
            ->where(DeployRelease::schema_fields_IS_CURRENT, 1)
            ->where(DeployRelease::schema_fields_STATUS, 'success')
            ->select()
            ->pagination(1, 1)
            ->fetch();
        $items = $this->collectionItems($result);

        return $items[0] ?? null;
    }

    /**
     * Get recent release records across all scopes.
     *
     * @return DeployRelease[]
     */
    public function getRecent(int $limit = 20): array
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $collection = $model->reset()
            ->order(DeployRelease::schema_fields_STARTED_AT, 'DESC')
            ->select()
            ->pagination(1, $this->normalizeLimit($limit))
            ->fetch();

        return $this->collectionItems($collection);
    }

    /**
     * Get recent records for a WLS project context. Empty context falls back to
     * global records for backward compatibility.
     *
     * @param array<string, mixed> $context
     * @return DeployRelease[]
     */
    public function getRecentForContext(array $context, int $limit = 20): array
    {
        $scope = $this->normalizeProjectContext($context);
        if (!$this->hasProjectScope($scope)) {
            return $this->getRecentGlobal($limit);
        }

        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $collection = $model->reset()
            ->where(DeployRelease::schema_fields_PROFILE_KEY, $scope['profile_key'])
            ->order(DeployRelease::schema_fields_STARTED_AT, 'DESC')
            ->select()
            ->pagination(1, $this->normalizeLimit($limit))
            ->fetch();

        return $this->collectionItems($collection);
    }

    /**
     * Get total release count.
     */
    public function getCount(): int
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $result = $model->reset()->count()->fetchOne();
        return (int)$result;
    }

    public function findById(string $releaseId): ?DeployRelease
    {
        return $this->loadById($releaseId);
    }

    private function loadById(string $releaseId): ?DeployRelease
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $result = $model->reset()
            ->where(DeployRelease::schema_fields_ID, $releaseId)
            ->select()
            ->pagination(1, 1)
            ->fetch();
        $items = $this->collectionItems($result);

        return $items[0] ?? null;
    }

    /**
     * @return DeployRelease[]
     */
    private function getRecentGlobal(int $limit): array
    {
        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $collection = $model->reset()
            ->where(DeployRelease::schema_fields_PROFILE_KEY, null, 'is null')
            ->order(DeployRelease::schema_fields_STARTED_AT, 'DESC')
            ->select()
            ->pagination(1, $this->normalizeLimit($limit))
            ->fetch();

        return $this->collectionItems($collection);
    }

    private function clearCurrentExcept(DeployRelease $currentRelease, string $releaseId): void
    {
        $scope = $this->normalizeProjectContext([
            'profile_key' => (string)$currentRelease->getData(DeployRelease::schema_fields_PROFILE_KEY),
            'project_id' => (string)$currentRelease->getData(DeployRelease::schema_fields_PROJECT_ID),
            'domain' => (string)$currentRelease->getData(DeployRelease::schema_fields_DOMAIN),
            'project_type' => (string)$currentRelease->getData(DeployRelease::schema_fields_PROJECT_TYPE),
        ]);

        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $query = $model->reset()
            ->where(DeployRelease::schema_fields_IS_CURRENT, 1)
            ->where(DeployRelease::schema_fields_ID, $releaseId, '!=');

        if ($this->hasProjectScope($scope)) {
            $query->where(DeployRelease::schema_fields_PROFILE_KEY, $scope['profile_key']);
        } else {
            $query->where(DeployRelease::schema_fields_PROFILE_KEY, null, 'is null');
        }

        $query->update([DeployRelease::schema_fields_IS_CURRENT => 0])->fetch();
    }

    /**
     * @param array<string, mixed> $context
     * @return array{profile_key:string,project_id:string,domain:string,project_type:string}
     */
    private function normalizeProjectContext(array $context): array
    {
        $projectId = $this->normalizeToken($this->contextValue($context, 'project_id', 'PROJECT_ID'), 80);
        $domain = $this->normalizeDomain($this->contextValue($context, 'domain', 'DOMAIN'));
        $profileKey = $this->normalizeToken($this->contextValue($context, 'profile_key', 'PROFILE_KEY'), 190);
        if ($profileKey === '') {
            $profileKey = DeployProjectProfile::buildProfileKey($projectId, $domain);
        }

        return [
            'profile_key' => $profileKey,
            'project_id' => $projectId,
            'domain' => $domain,
            'project_type' => $this->normalizeToken($this->contextValue($context, 'project_type', 'PROJECT_TYPE'), 80),
        ];
    }

    /**
     * @param array{profile_key:string,project_id:string,domain:string,project_type:string} $scope
     */
    private function hasProjectScope(array $scope): bool
    {
        return $scope['profile_key'] !== '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function contextValue(array $context, string $lowerKey, string $upperKey): string
    {
        $value = $context[$lowerKey] ?? $context[$upperKey] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = explode('/', $domain, 2)[0] ?? $domain;
        return trim($domain);
    }

    private function normalizeToken(string $value, int $maxLength): string
    {
        $value = trim($value);
        $value = preg_replace('/[^a-zA-Z0-9:_\-.]/', '', $value) ?? '';
        return substr($value, 0, $maxLength);
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, min(100, $limit));
    }

    /**
     * @return DeployRelease[]
     */
    private function collectionItems(mixed $collection): array
    {
        if (!is_object($collection) || !method_exists($collection, 'getItems')) {
            return [];
        }

        return array_values(array_filter(
            $collection->getItems(),
            static fn (mixed $item): bool => $item instanceof DeployRelease
        ));
    }
}
