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
     * Hard caps for failure-column writes.
     *
     * Schema declares error_message / output_tail as unbounded text, but some production
     * schemas still use short VARCHAR. Overflow masks the real failure with
     * "Data too long for column …". Cap both; prefer the error-summary first segment.
     */
    public const ERROR_MESSAGE_MAX_CHARS = 500;

    public const OUTPUT_TAIL_MAX_CHARS = 1000;

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

        $messages = $this->truncateFailedMessages($errorMessage, $outputTail);
        $startedAt = (int)$model->getData(DeployRelease::schema_fields_STARTED_AT);
        $model->setData([
            DeployRelease::schema_fields_STATUS        => 'failed',
            DeployRelease::schema_fields_FINISHED_AT   => $now,
            DeployRelease::schema_fields_DURATION_MS   => ($startedAt > 0) ? (int)(($now - $startedAt) * 1000) : null,
            DeployRelease::schema_fields_ERROR_MESSAGE => $messages['error_message'],
            DeployRelease::schema_fields_OUTPUT_TAIL   => $messages['output_tail'],
        ]);
        $model->save();
    }

    /**
     * Cap error_message and output_tail for short production VARCHAR columns.
     * Prefer the error-summary first segment in both fields (never park unbounded logs).
     *
     * @return array{error_message:string,output_tail:string}
     */
    public function truncateFailedMessages(string $errorMessage, string $outputTail = ''): array
    {
        $errorMessage = trim($errorMessage);
        $outputTail = trim($outputTail);
        $errorMax = self::ERROR_MESSAGE_MAX_CHARS;
        $tailMax = self::OUTPUT_TAIL_MAX_CHARS;

        $shortError = $this->clipUtf8($errorMessage, $errorMax);

        if (mb_strlen($errorMessage, 'UTF-8') <= $errorMax) {
            return [
                'error_message' => $shortError,
                'output_tail' => $this->clipUtf8($outputTail, $tailMax),
            ];
        }

        // Overflow: merge context into output_tail, then hard-cap (prefer summary first segment).
        if ($outputTail === '' || str_contains($errorMessage, $outputTail)) {
            $tail = $errorMessage;
        } elseif (mb_strlen($outputTail, 'UTF-8') > mb_strlen($errorMessage, 'UTF-8')) {
            $tail = str_contains($outputTail, $errorMessage)
                ? $outputTail
                : ($errorMessage . "\n---\n" . $outputTail);
        } else {
            $tail = $errorMessage . "\n---\n" . $outputTail;
        }

        return [
            'error_message' => $shortError,
            'output_tail' => $this->clipUtf8($tail, $tailMax),
        ];
    }

    private function clipUtf8(string $value, int $maxChars): string
    {
        if ($value === '' || $maxChars <= 0) {
            return '';
        }
        if (mb_strlen($value, 'UTF-8') <= $maxChars) {
            return $value;
        }

        return mb_substr($value, 0, $maxChars, 'UTF-8');
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

    /**
     * Locate a recent release that matches a git tag name (git_tag or deploy_version).
     */
    public function findByGitTag(string $tag): ?DeployRelease
    {
        $tag = trim($tag);
        if ($tag === '') {
            return null;
        }

        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        $byTag = $model->reset()
            ->where(DeployRelease::schema_fields_GIT_TAG, $tag)
            ->order(DeployRelease::schema_fields_STARTED_AT, 'DESC')
            ->select()
            ->pagination(1, 1)
            ->fetch();
        $items = $this->collectionItems($byTag);
        if ($items !== []) {
            return $items[0];
        }

        $byVersion = $model->reset()
            ->where(DeployRelease::schema_fields_DEPLOY_VERSION, $tag)
            ->order(DeployRelease::schema_fields_STARTED_AT, 'DESC')
            ->select()
            ->pagination(1, 1)
            ->fetch();
        $items = $this->collectionItems($byVersion);

        return $items[0] ?? null;
    }

    /**
     * Locate a recent release whose git_commit matches the given SHA (full or prefix).
     */
    public function findByGitCommit(string $commit): ?DeployRelease
    {
        $commit = strtolower(trim($commit));
        if ($commit === '' || !preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
            return null;
        }

        /** @var DeployRelease $model */
        $model = ObjectManager::getInstance(DeployRelease::class);
        if (strlen($commit) === 40) {
            $exact = $model->reset()
                ->where(DeployRelease::schema_fields_GIT_COMMIT, $commit)
                ->order(DeployRelease::schema_fields_STARTED_AT, 'DESC')
                ->select()
                ->pagination(1, 1)
                ->fetch();
            $items = $this->collectionItems($exact);
            if ($items !== []) {
                return $items[0];
            }
        }

        // Prefix match: scan a recent window (portable; no SQL dialect LIKE required for short SHA).
        $recent = $this->getRecent(50);
        foreach ($recent as $row) {
            $stored = strtolower(trim((string)$row->getData(DeployRelease::schema_fields_GIT_COMMIT)));
            if ($stored === '') {
                continue;
            }
            if ($stored === $commit || str_starts_with($stored, $commit) || str_starts_with($commit, $stored)) {
                return $row;
            }
        }

        return null;
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
