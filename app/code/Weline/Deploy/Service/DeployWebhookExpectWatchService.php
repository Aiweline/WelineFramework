<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * CLI Cron: remote tip/tag watch.
 * - cron_update_enabled: coalesce to one newest ref and enqueue via AsyncDispatcher (no backlog).
 * - webhook_enabled only: pending + grace → webhook_miss (never auto-deploy on miss).
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
        private readonly DeployWebhookAsyncDispatcher $asyncDispatcher,
    ) {
    }

    /**
     * @return array{ok:bool,skipped:bool,reason:string,new_pending:int,cleared:int,notified:int,enqueued:int}
     */
    public function tick(?int $now = null, ?int $graceSec = null): array
    {
        $now = $now ?? time();
        try {
            $cronOn = $this->configService->isCronUpdateEnabled();
            $webhookOn = $this->configService->isWebhookEnabled();
            if (!$cronOn && !$webhookOn) {
                return $this->result(true, true, 'channels_disabled', 0, 0, 0, 0);
            }

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
                return $this->result(true, true, 'trigger_mode_none', 0, 0, 0, 0);
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
                    return $this->result(true, true, 'ls_remote_failed', 0, 0, 0, 0);
                }
            }

            $tipSha = null;
            if ($watchTip) {
                if ($branch === '') {
                    return $this->result(true, true, 'branch_unconfigured', 0, 0, 0, 0);
                }
                $tipSha = $this->gitService->listRemoteBranchTip($branch, $remote);
                if ($tipSha === null) {
                    return $this->result(true, true, 'ls_remote_failed', 0, 0, 0, 0);
                }
            }

            $state = $this->loadState();
            $bootstrapSeen = is_array($state['bootstrap_seen'] ?? null) ? $state['bootstrap_seen'] : [];
            $bootstrapTipSha = strtolower(trim((string)($state['bootstrap_tip_sha'] ?? '')));
            $bootstrapTipBranch = trim((string)($state['bootstrap_tip_branch'] ?? ''));
            $pending = is_array($state['pending'] ?? null) ? $state['pending'] : [];
            $bootstrapRemote = trim((string)($state['bootstrap_remote'] ?? ''));

            // Switching project_remote (e.g. gitee→github) must re-baseline: never treat the whole
            // remote tag catalog as "new" (would coalesce to a random lexicographic winner).
            $needsBootstrap = empty($state['bootstrapped'])
                || ($bootstrapRemote !== '' && $bootstrapRemote !== $remote)
                || ($bootstrapRemote === '' && !empty($state['bootstrapped']));
            if ($needsBootstrap) {
                $bootstrapSeen = [];
                if ($watchTags && is_array($remoteTags)) {
                    foreach ($remoteTags as $tag) {
                        if ($this->tagMatchesPolicy($tag, $config)) {
                            $bootstrapSeen[$tag] = true;
                        }
                    }
                }
                $bootstrapTipSha = '';
                $bootstrapTipBranch = '';
                if ($watchTip && is_string($tipSha) && $tipSha !== '') {
                    $bootstrapTipSha = $tipSha;
                    $bootstrapTipBranch = $branch;
                }
                $this->saveState([
                    'bootstrapped' => true,
                    'bootstrap_remote' => $remote,
                    'bootstrap_seen' => $bootstrapSeen,
                    'bootstrap_tip_sha' => $bootstrapTipSha,
                    'bootstrap_tip_branch' => $bootstrapTipBranch,
                    'pending' => [],
                    'updated_at' => $now,
                ]);

                return $this->result(true, false, 'bootstrap', 0, 0, 0, 0);
            }

            /** @var list<string> $newTags */
            $newTags = [];
            if ($watchTags && is_array($remoteTags)) {
                foreach ($remoteTags as $tag) {
                    if (!$this->tagMatchesPolicy($tag, $config)) {
                        continue;
                    }
                    if (isset($bootstrapSeen[$tag])) {
                        continue;
                    }
                    $newTags[] = $tag;
                }
            }

            $tipIsNew = false;
            if ($watchTip && is_string($tipSha) && $tipSha !== '') {
                $tipIsNew = !($bootstrapTipBranch === $branch && $bootstrapTipSha === $tipSha);
            }

            if ($cronOn) {
                $enqueued = 0;
                $winner = $this->selectCronWinnerRef($newTags, $tipIsNew ? $tipSha : null, $branch);
                $dispatchOk = false;
                $winnerTag = '';
                if ($winner !== null) {
                    $winnerTag = (string)($winner['tag'] ?? '');
                    $dispatched = $this->asyncDispatcher->dispatchRelease($winner['ref'], false, false);
                    $dispatchOk = !empty($dispatched['ok']);
                    if ($dispatchOk) {
                        $enqueued = 1;
                    }
                }

                // Coalesce: mark non-winner new tags as seen (skip history). Winner only on success.
                foreach ($newTags as $tag) {
                    if ($winnerTag !== '' && $tag === $winnerTag && !$dispatchOk) {
                        continue;
                    }
                    $bootstrapSeen[$tag] = true;
                }
                if ($watchTip && is_string($tipSha) && $tipSha !== '') {
                    $tipWasWinner = $winner !== null && ($winner['kind'] ?? '') === self::KIND_BRANCH_TIP;
                    if (!$tipWasWinner || $dispatchOk) {
                        $bootstrapTipSha = $tipSha;
                        $bootstrapTipBranch = $branch;
                    }
                }

                // Cron path does not accumulate miss pending.
                $pending = [];
                $this->saveState([
                    'bootstrapped' => true,
                    'bootstrap_remote' => $remote,
                    'bootstrap_seen' => $bootstrapSeen,
                    'bootstrap_tip_sha' => $bootstrapTipSha,
                    'bootstrap_tip_branch' => $bootstrapTipBranch,
                    'pending' => $pending,
                    'updated_at' => $now,
                ]);

                if (!$webhookOn) {
                    return $this->result(
                        true,
                        false,
                        $enqueued > 0 ? 'cron_enqueued' : ($winner !== null ? 'cron_enqueue_failed' : 'cron_noop'),
                        0,
                        0,
                        0,
                        $enqueued
                    );
                }
                // webhook also on: miss watch only for failures / no cron candidate — fall through with empty pending
                if ($enqueued > 0 || $winner === null) {
                    return $this->result(true, false, $enqueued > 0 ? 'cron_enqueued' : 'cron_noop', 0, 0, 0, $enqueued);
                }
                // dispatch failed: re-seed a single pending for miss if webhook waiting
            }

            if (!$webhookOn) {
                return $this->result(true, true, 'webhook_disabled', 0, 0, 0, 0);
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

            // Webhook-only miss path: never enqueue / never call dispatchRelease here.
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
                    'deploy_root' => $this->resolveNotifyDeployRoot($config),
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
                'bootstrap_remote' => $remote,
                'bootstrap_seen' => $bootstrapSeen,
                'bootstrap_tip_sha' => $bootstrapTipSha,
                'bootstrap_tip_branch' => $bootstrapTipBranch,
                'pending' => $pending,
                'updated_at' => $now,
            ]);

            return $this->result(true, false, 'ok', $newPending, $cleared, $notified, 0);
        } catch (\Throwable $e) {
            return $this->result(true, true, 'fail_open:' . $e->getMessage(), 0, 0, 0, 0);
        }
    }

    /**
     * Pick at most one ref for cron enqueue. Tag winner preferred over branch tip when both.
     *
     * @param list<string> $newTags
     * @return array{kind:string,ref:string,tag?:string}|null
     */
    public function selectCronWinnerRef(array $newTags, ?string $tipSha, string $branch): ?array
    {
        $tagWinner = $this->pickNewestTag($newTags);
        if ($tagWinner !== '') {
            return [
                'kind' => self::KIND_TAG,
                'ref' => 'refs/tags/' . $tagWinner,
                'tag' => $tagWinner,
            ];
        }
        $tipSha = $tipSha !== null ? strtolower(trim($tipSha)) : '';
        $branch = trim($branch);
        if ($tipSha !== '' && $branch !== '') {
            return [
                'kind' => self::KIND_BRANCH_TIP,
                'ref' => 'refs/heads/' . $branch,
            ];
        }

        return null;
    }

    /**
     * @param list<string> $tags
     */
    public function pickNewestTag(array $tags): string
    {
        $tags = array_values(array_filter(array_map(static fn ($t) => trim((string)$t), $tags), static fn ($t) => $t !== ''));
        if ($tags === []) {
            return '';
        }
        // Product release tags win over incidental tags (skills/deploy-* helpers) when coalescing.
        $release = array_values(array_filter($tags, static fn (string $t): bool => str_starts_with($t, 'release-')));
        $pool = $release !== [] ? $release : $tags;
        usort($pool, static function (string $a, string $b): int {
            $aVer = self::versionLikeCore($a);
            $bVer = self::versionLikeCore($b);
            if ($aVer !== null && $bVer !== null) {
                $vc = version_compare($aVer, $bVer);
                if ($vc !== 0) {
                    return $vc;
                }
            }

            return strcmp($a, $b);
        });

        return (string)end($pool);
    }

    /** @return string|null numeric core for version_compare, or null if not version-like */
    private static function versionLikeCore(string $tag): ?string
    {
        $tag = trim($tag);
        if ($tag === '') {
            return null;
        }
        if (!preg_match('/^v?(\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.\-]+)?)$/i', $tag, $m)) {
            return null;
        }

        return (string)$m[1];
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
    private function resolveNotifyDeployRoot(array $config): string
    {
        $root = trim((string)($config['deploy_root'] ?? $config['DEPLOY_ROOT'] ?? ''));
        if ($root === '') {
            try {
                $settings = $this->configService->getSettings();
                $root = trim((string)($settings['deploy_root'] ?? ''));
            } catch (\Throwable) {
                $root = '';
            }
        }
        if ($root === '' && \defined('BP')) {
            $root = (string)BP;
        }

        return $root !== '' ? rtrim($root, "/\\") : '';
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
     * @return array{ok:bool,skipped:bool,reason:string,new_pending:int,cleared:int,notified:int,enqueued:int}
     */
    private function result(
        bool $ok,
        bool $skipped,
        string $reason,
        int $newPending,
        int $cleared,
        int $notified,
        int $enqueued = 0
    ): array {
        return [
            'ok' => $ok,
            'skipped' => $skipped,
            'reason' => $reason,
            'new_pending' => $newPending,
            'cleared' => $cleared,
            'notified' => $notified,
            'enqueued' => $enqueued,
        ];
    }
}
