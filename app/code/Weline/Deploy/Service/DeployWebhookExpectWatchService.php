<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Light CLI Cron reconciliation: remote tags / branch tips without Webhook enqueue.
 * Fail-open: ls-remote / IO errors skip the round (never fake webhook_miss).
 */
class DeployWebhookExpectWatchService
{
    public const DEFAULT_GRACE_SEC = 60;
    public const MIN_GRACE_SEC = 60;
    public const MAX_GRACE_SEC = 180;
    public const TIP_JOB_SKEW_SEC = 30;

    public const KIND_TAG = 'tag';
    public const KIND_BRANCH_TIP = 'branch_tip';

    public function __construct(
        private readonly DeployConfigService $configService,
        private readonly DeployWebhookRefResolver $refResolver,
        private readonly DeployGitMetadataService $gitService,
        private readonly DeployWebhookJobQueue $jobQueue,
        private readonly DeployReleaseRuntimeService $runtimeService,
        private readonly DeployReleaseHistoryService $historyService,
        private readonly DeployReleaseNotifier $releaseNotifier,
    ) {
    }

    /**
     * @return array{ok:bool,skipped:bool,reason:string,new_pending:int,cleared:int,notified:int}
     */
    public function tick(?int $now = null, ?int $graceSec = null): array
    {
        $now = $now ?? time();
        try {
            $config = $this->configService->getWebhookShellConfig();
            if ($config === []) {
                $config = $this->configService->getProjectDeployConfig();
            }

            $mode = $this->refResolver->resolveTriggerMode($config);
            $watchTags = $mode === DeployConfigService::TRIGGER_MODE_TAG
                || $mode === DeployConfigService::TRIGGER_MODE_BOTH;
            $watchTip = $mode === DeployConfigService::TRIGGER_MODE_BRANCH
                || $mode === DeployConfigService::TRIGGER_MODE_BOTH;
            if (!$watchTags && !$watchTip) {
                return $this->result(true, true, 'trigger_mode_none', 0, 0, 0);
            }

            $grace = $this->normalizeGrace($graceSec ?? $this->readGraceFromConfig($config));
            $remote = trim((string)($config['project_remote'] ?? $config['GIT_REMOTE'] ?? $config['GIT_REMOTE_NAME'] ?? 'origin'));
            if ($remote === '') {
                $remote = 'origin';
            }
            $branch = $this->resolveWatchBranch($config);

            $remoteTags = null;
            if ($watchTags) {
                $remoteTags = $this->gitService->listRemoteTagNames($remote);
                if ($remoteTags === null) {
                    return $this->result(true, true, 'ls_remote_failed', 0, 0, 0);
                }
            }

            $tipSha = null;
            if ($watchTip) {
                if ($branch === '') {
                    return $this->result(true, true, 'branch_unconfigured', 0, 0, 0);
                }
                $tipSha = $this->gitService->listRemoteBranchTip($branch, $remote);
                if ($tipSha === null) {
                    return $this->result(true, true, 'ls_remote_failed', 0, 0, 0);
                }
            }

            $state = $this->loadState();
            $bootstrapSeen = is_array($state['bootstrap_seen'] ?? null) ? $state['bootstrap_seen'] : [];
            $bootstrapTipSha = strtolower(trim((string)($state['bootstrap_tip_sha'] ?? '')));
            $bootstrapTipBranch = trim((string)($state['bootstrap_tip_branch'] ?? ''));
            $pending = is_array($state['pending'] ?? null) ? $state['pending'] : [];

            if (empty($state['bootstrapped'])) {
                if ($watchTags && is_array($remoteTags)) {
                    foreach ($remoteTags as $tag) {
                        if ($this->tagMatchesPolicy($tag, $config)) {
                            $bootstrapSeen[$tag] = true;
                        }
                    }
                }
                if ($watchTip && is_string($tipSha) && $tipSha !== '') {
                    $bootstrapTipSha = $tipSha;
                    $bootstrapTipBranch = $branch;
                }
                $this->saveState([
                    'bootstrapped' => true,
                    'bootstrap_seen' => $bootstrapSeen,
                    'bootstrap_tip_sha' => $bootstrapTipSha,
                    'bootstrap_tip_branch' => $bootstrapTipBranch,
                    'pending' => [],
                    'updated_at' => $now,
                ]);

                return $this->result(true, false, 'bootstrap', 0, 0, 0);
            }

            $newPending = 0;
            $cleared = 0;
            $notified = 0;

            if ($watchTags && is_array($remoteTags)) {
                foreach ($remoteTags as $tag) {
                    if (!$this->tagMatchesPolicy($tag, $config)) {
                        continue;
                    }
                    $key = $this->pendingKey(self::KIND_TAG, $tag);
                    if (isset($bootstrapSeen[$tag]) || isset($pending[$key])) {
                        continue;
                    }
                    $pending[$key] = [
                        'kind' => self::KIND_TAG,
                        'ref' => 'refs/tags/' . $tag,
                        'tag' => $tag,
                        'first_seen_at' => $now,
                        'notified_at' => null,
                    ];
                    $newPending++;
                }
            }

            if ($watchTip && is_string($tipSha) && $tipSha !== '') {
                $tipKey = $this->pendingKey(self::KIND_BRANCH_TIP, $branch . '@' . $tipSha);
                $sameAsBootstrap = ($bootstrapTipBranch === $branch && $bootstrapTipSha === $tipSha);
                if (!$sameAsBootstrap && !isset($pending[$tipKey])) {
                    // Drop older tip pending for the same branch (superseded by newer tip).
                    foreach ($pending as $pkey => $pentry) {
                        if (!is_array($pentry)) {
                            continue;
                        }
                        if (($pentry['kind'] ?? '') !== self::KIND_BRANCH_TIP) {
                            continue;
                        }
                        if (trim((string)($pentry['branch'] ?? '')) === $branch) {
                            unset($pending[$pkey]);
                        }
                    }
                    $pending[$tipKey] = [
                        'kind' => self::KIND_BRANCH_TIP,
                        'ref' => 'refs/heads/' . $branch,
                        'branch' => $branch,
                        'sha' => $tipSha,
                        'first_seen_at' => $now,
                        'notified_at' => null,
                    ];
                    $newPending++;
                }
            }

            foreach ($pending as $key => $entry) {
                if (!is_array($entry)) {
                    unset($pending[$key]);
                    continue;
                }
                $kind = (string)($entry['kind'] ?? self::KIND_TAG);
                if ($kind === self::KIND_BRANCH_TIP) {
                    $sha = strtolower(trim((string)($entry['sha'] ?? '')));
                    $entryBranch = trim((string)($entry['branch'] ?? $branch));
                    $ref = (string)($entry['ref'] ?? ('refs/heads/' . $entryBranch));
                    if ($this->hasBranchTipArrived($entryBranch, $sha, $ref, (int)($entry['first_seen_at'] ?? 0))) {
                        unset($pending[$key]);
                        $bootstrapTipSha = $sha;
                        $bootstrapTipBranch = $entryBranch;
                        $cleared++;
                        continue;
                    }
                } else {
                    $tag = (string)($entry['tag'] ?? '');
                    if ($tag === '' && str_starts_with($key, 'tag:')) {
                        $tag = substr($key, 4);
                    }
                    $ref = (string)($entry['ref'] ?? ('refs/tags/' . $tag));
                    if ($this->hasTagArrived($tag, $ref)) {
                        unset($pending[$key]);
                        $bootstrapSeen[$tag] = true;
                        $cleared++;
                        continue;
                    }
                }

                $firstSeen = (int)($entry['first_seen_at'] ?? 0);
                if (($entry['notified_at'] ?? null) !== null) {
                    continue;
                }
                if ($firstSeen <= 0 || ($now - $firstSeen) < $grace) {
                    continue;
                }

                $ref = (string)($entry['ref'] ?? '');
                $hint = $kind === self::KIND_BRANCH_TIP
                    ? (string)($entry['sha'] ?? '')
                    : (string)($entry['tag'] ?? '');
                $this->releaseNotifier->notifyPhase(DeployReleaseNotifier::PHASE_WEBHOOK_MISS, [
                    'ref' => $ref,
                    'deploy_version' => $hint,
                    'git_commit' => $kind === self::KIND_BRANCH_TIP ? (string)($entry['sha'] ?? '') : '',
                    'trigger' => 'expect_watch',
                    'waited_s' => (string)($now - $firstSeen),
                    'reason' => $kind === self::KIND_BRANCH_TIP
                        ? 'branch_tip_webhook_miss_after_grace'
                        : 'webhook_miss_after_grace',
                    'dedupe_salt' => (string)$key,
                ]);
                $pending[$key]['notified_at'] = $now;
                $notified++;
            }

            // Drop tag pending no longer on remote; tip pending kept until arrived/superseded.
            if (is_array($remoteTags)) {
                foreach ($pending as $key => $entry) {
                    if (!is_array($entry) || ($entry['kind'] ?? self::KIND_TAG) !== self::KIND_TAG) {
                        continue;
                    }
                    $tag = (string)($entry['tag'] ?? '');
                    if ($tag !== '' && !in_array($tag, $remoteTags, true)) {
                        unset($pending[$key]);
                    }
                }
            }

            $this->saveState([
                'bootstrapped' => true,
                'bootstrap_seen' => $bootstrapSeen,
                'bootstrap_tip_sha' => $bootstrapTipSha,
                'bootstrap_tip_branch' => $bootstrapTipBranch,
                'pending' => $pending,
                'updated_at' => $now,
            ]);

            return $this->result(true, false, 'ok', $newPending, $cleared, $notified);
        } catch (\Throwable $e) {
            return $this->result(true, true, 'fail_open:' . $e->getMessage(), 0, 0, 0);
        }
    }

    public function statePath(): string
    {
        return rtrim(BP, "/\\") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'deploy'
            . DIRECTORY_SEPARATOR . 'webhook-expect.json';
    }

    public function normalizeGrace(int $graceSec): int
    {
        return max(self::MIN_GRACE_SEC, min(self::MAX_GRACE_SEC, $graceSec));
    }

    /**
     * @param array<string,mixed> $config
     */
    public function tagMatchesPolicy(string $tag, array $config): bool
    {
        $resolved = $this->refResolver->resolve('refs/tags/' . $tag, $config);

        return empty($resolved['skipped']);
    }

    public function hasTagArrived(string $tag, string $ref = ''): bool
    {
        $ref = $ref !== '' ? $ref : ('refs/tags/' . $tag);
        if ($this->jobQueue->findByRef($ref) !== null) {
            return true;
        }
        if ($this->jobQueue->findByRef($tag) !== null) {
            return true;
        }

        $current = $this->runtimeService->getCurrent();
        if (is_array($current)) {
            $gitTag = trim((string)($current['git_tag'] ?? ''));
            $deployVersion = trim((string)($current['deploy_version'] ?? ''));
            if ($gitTag === $tag || $deployVersion === $tag) {
                return true;
            }
        }

        try {
            if ($this->historyService->findByGitTag($tag) !== null) {
                return true;
            }
        } catch (\Throwable) {
            // DB may be unavailable in pure CLI fixtures — ignore.
        }

        return false;
    }

    /**
     * Branch-tip arrival: SHA match on current/history, or same-branch job within time window.
     * Must NOT use bare findByRef alone (would false-clear on historical jobs).
     */
    public function hasBranchTipArrived(
        string $branch,
        string $tipSha,
        string $ref = '',
        int $firstSeenAt = 0
    ): bool {
        $tipSha = strtolower(trim($tipSha));
        $branch = trim($branch);
        if ($tipSha === '' || $branch === '') {
            return false;
        }
        $ref = $ref !== '' ? $ref : ('refs/heads/' . $branch);

        $current = $this->runtimeService->getCurrent();
        if (is_array($current)) {
            $commit = strtolower(trim((string)($current['git_commit'] ?? '')));
            if ($commit !== '' && $this->shaEquals($commit, $tipSha)) {
                return true;
            }
        }

        try {
            if ($this->historyService->findByGitCommit($tipSha) !== null) {
                return true;
            }
        } catch (\Throwable) {
            // ignore
        }

        $minCreated = $firstSeenAt > 0 ? max(0, $firstSeenAt - self::TIP_JOB_SKEW_SEC) : null;
        if ($minCreated !== null && $this->jobQueue->findByRefCreatedSince($ref, $minCreated) !== null) {
            return true;
        }

        return false;
    }

    /** @deprecated Use hasTagArrived / hasBranchTipArrived */
    public function hasArrived(string $tag, string $ref = ''): bool
    {
        return $this->hasTagArrived($tag, $ref);
    }

    /**
     * @param array<string,mixed> $config
     */
    public function resolveWatchBranch(array $config): string
    {
        foreach (['webhook_branch', 'WEBHOOK_BRANCH', 'project_branch', 'GIT_BRANCH'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function pendingKey(string $kind, string $id): string
    {
        return $kind . ':' . $id;
    }

    private function shaEquals(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        return str_starts_with($a, $b) || str_starts_with($b, $a);
    }

    /**
     * @param array<string,mixed> $config
     */
    private function readGraceFromConfig(array $config): int
    {
        $raw = $config['webhook_expect_grace_sec']
            ?? $config['WEBHOOK_EXPECT_GRACE_SEC']
            ?? self::DEFAULT_GRACE_SEC;

        return (int)$raw;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadState(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function saveState(array $state): void
    {
        $path = $this->statePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }
        @file_put_contents($path, $json . "\n");
    }

    /**
     * @return array{ok:bool,skipped:bool,reason:string,new_pending:int,cleared:int,notified:int}
     */
    private function result(
        bool $ok,
        bool $skipped,
        string $reason,
        int $newPending,
        int $cleared,
        int $notified
    ): array {
        return [
            'ok' => $ok,
            'skipped' => $skipped,
            'reason' => $reason,
            'new_pending' => $newPending,
            'cleared' => $cleared,
            'notified' => $notified,
        ];
    }
}
