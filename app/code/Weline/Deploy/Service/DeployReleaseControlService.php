<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;

class DeployReleaseControlService
{
    public function __construct(
        private readonly DeployConfigService $configService,
        private readonly DeployGitMetadataService $gitService,
        private readonly DeployReleaseRuntimeService $runtimeService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPageContext(): array
    {
        $settings = $this->configService->getSettings();
        $current = $this->runtimeService->getCurrent();
        $deployRoot = $this->resolveDeployRoot($settings);
        $defaultBranch = trim((string)($settings['project_branch'] ?? ''));
        if ($defaultBranch === '') {
            $defaultBranch = 'master';
        }

        return [
            'settings' => [
                'project_repo_url' => (string)($settings['project_repo_url'] ?? ''),
                'project_branch' => $defaultBranch,
                'project_remote' => trim((string)($settings['project_remote'] ?? 'origin')) ?: 'origin',
            ],
            'current' => $current,
            'deploy_root' => $deployRoot,
            'is_production' => $this->isProductionSite(),
            'repo_configured' => trim((string)($settings['project_repo_url'] ?? '')) !== '',
        ];
    }

    /**
     * @return list<string>
     */
    public function listRemoteBranches(?string $deployRoot = null): array
    {
        $context = $this->buildPageContext();
        $remote = (string)$context['settings']['project_remote'];
        $root = $deployRoot ?? (string)$context['deploy_root'];
        $this->gitService->fetch(true, $remote, $root);

        return $this->gitService->listRemoteBranches($remote, $root);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCommits(string $branch, int $limit = 50, int $offset = 0, ?string $deployRoot = null): array
    {
        $context = $this->buildPageContext();
        $remote = (string)$context['settings']['project_remote'];
        $root = $deployRoot ?? (string)$context['deploy_root'];
        $branch = $this->sanitizeBranch($branch);
        $this->gitService->fetch(false, $remote, $root);
        $currentSha = $this->resolveCurrentCommitSha($root);

        $commits = $this->gitService->listCommits($branch, $remote, $limit, $offset, $root);
        foreach ($commits as &$commit) {
            $sha = (string)($commit['sha'] ?? '');
            $commit['is_current'] = $sha !== '' && $sha === $currentSha;
            $commit['is_older'] = $this->isOlderThanCurrent($sha, $currentSha, $root);
        }
        unset($commit);

        return $commits;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTags(int $limit = 100, ?string $deployRoot = null): array
    {
        $context = $this->buildPageContext();
        $remote = (string)$context['settings']['project_remote'];
        $root = $deployRoot ?? (string)$context['deploy_root'];
        $this->gitService->fetch(true, $remote, $root);
        $currentSha = $this->resolveCurrentCommitSha($root);

        $tags = $this->gitService->listTags($limit, $root);
        foreach ($tags as &$tag) {
            $sha = (string)($tag['sha'] ?? '');
            $tag['is_current'] = $sha !== '' && $sha === $currentSha;
            $tag['is_older'] = $this->isOlderThanCurrent($sha, $currentSha, $root);
        }
        unset($tag);

        return $tags;
    }

    /**
     * @return array{
     *     target_sha:string,
     *     current_sha:string,
     *     is_current:bool,
     *     is_older:bool,
     *     requires_older_confirm:bool
     * }
     */
    public function previewRelease(string $refType, string $ref, ?string $deployRoot = null): array
    {
        $context = $this->buildPageContext();
        $root = $deployRoot ?? (string)$context['deploy_root'];
        $remote = (string)$context['settings']['project_remote'];
        $targetSha = $this->resolveTargetSha($refType, $ref, $remote, $root);
        $currentSha = $this->resolveCurrentCommitSha($root);
        $isCurrent = $targetSha !== '' && $targetSha === $currentSha;
        $isOlder = $this->isOlderThanCurrent($targetSha, $currentSha, $root);

        return [
            'target_sha' => $targetSha,
            'current_sha' => $currentSha,
            'is_current' => $isCurrent,
            'is_older' => $isOlder,
            'requires_older_confirm' => $isOlder,
        ];
    }

    /**
     * @return array{
     *     trigger:string,
     *     ref_type:string,
     *     ref:string,
     *     deploy_version_hint:?string,
     *     git_checkout:?string,
     *     git_tag:?string,
     *     force:bool,
     *     no_backup:bool,
     *     config:array<string,mixed>
     * }
     */
    public function buildReleaseParams(string $refType, string $ref, ?string $branch = null): array
    {
        $settings = $this->configService->getSettings();
        $config = $this->configService->getProjectDeployConfig();
        if (!isset($config['DEPLOY_ROOT']) || trim((string)$config['DEPLOY_ROOT']) === '') {
            $config['DEPLOY_ROOT'] = $this->resolveDeployRoot($settings);
        }

        $remote = trim((string)($settings['project_remote'] ?? 'origin')) ?: 'origin';
        $force = (string)($settings['deploy_force_reset'] ?? '0') === '1';
        $backupSetting = strtolower(trim((string)($settings['backup_before_deploy'] ?? '')));
        $backupEnabled = !in_array($backupSetting, ['', '0', 'false', 'no', 'off'], true);
        $noBackup = !$backupEnabled && !$this->isProductionSite();

        $refType = strtolower(trim($refType));
        $ref = trim($ref);
        $gitCheckout = null;
        $gitTag = null;
        $versionHint = null;

        if ($refType === 'tag') {
            $tagName = str_starts_with($ref, 'refs/tags/') ? substr($ref, 10) : $ref;
            $gitTag = $tagName;
            $versionHint = $tagName;
            $ref = str_starts_with($ref, 'refs/tags/') ? $ref : 'refs/tags/' . $tagName;
        } elseif ($refType === 'commit') {
            $gitCheckout = $ref;
            $versionHint = mb_substr($ref, 0, 12);
            $refType = 'commit';
        } else {
            $branch = $this->sanitizeBranch($branch ?? $ref);
            $config['GIT_BRANCH'] = $branch;
            $ref = 'refs/heads/' . $branch;
            $refType = 'branch';
            $versionHint = $branch;
        }

        return [
            'trigger' => 'manual',
            'ref_type' => $refType,
            'ref' => $ref,
            'deploy_version_hint' => $versionHint,
            'git_checkout' => $gitCheckout,
            'git_tag' => $gitTag,
            'force' => $force,
            'no_backup' => $noBackup,
            'config' => $config,
            'backup_trigger' => 'manual_release',
        ];
    }

    public function isProductionSite(): bool
    {
        $envFile = Env::path_ENV_FILE;
        if (is_file($envFile)) {
            try {
                $config = include $envFile;
                if (is_array($config)) {
                    $system = is_array($config['system'] ?? null) ? $config['system'] : [];
                    foreach ([$system['deploy'] ?? null, $config['deploy'] ?? null] as $mode) {
                        $mode = strtolower(trim((string)$mode));
                        if ($mode === 'prod' || $mode === 'production') {
                            return true;
                        }
                        if (in_array($mode, ['dev', 'local', 'pre', 'staging', 'test'], true)) {
                            return false;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        $effectiveMode = strtolower(trim((string)Env::system('deploy', '')));
        if ($effectiveMode !== '') {
            if (in_array($effectiveMode, ['dev', 'local', 'pre', 'staging', 'test'], true)) {
                return false;
            }
            return !in_array($effectiveMode, ['dev', 'local'], true);
        }

        return true;
    }

    public function resolveCurrentCommitSha(?string $root = null): string
    {
        $current = $this->runtimeService->getCurrent($root);
        $sha = trim((string)($current['git_commit'] ?? ''));
        if ($sha !== '') {
            return $sha;
        }

        return $this->gitService->getFullCommit($root);
    }

    private function resolveTargetSha(string $refType, string $ref, string $remote, ?string $root): string
    {
        $refType = strtolower(trim($refType));
        $ref = trim($ref);
        if ($ref === '') {
            return '';
        }

        if ($refType === 'commit' && preg_match('/^[0-9a-f]{7,40}$/i', $ref) === 1) {
            return strtolower($ref);
        }

        if ($refType === 'tag') {
            $tag = str_starts_with($ref, 'refs/tags/') ? substr($ref, 10) : $ref;
            return strtolower($this->gitService->getTagCommit($tag, $root));
        }

        if ($refType === 'branch') {
            $branch = $this->sanitizeBranch(str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : $ref);
            return strtolower($this->gitService->getRemoteBranchCommit($branch, $remote, $root));
        }

        return strtolower($this->gitService->getFullCommit($root));
    }

    private function isOlderThanCurrent(string $targetSha, string $currentSha, ?string $root): bool
    {
        if ($targetSha === '' || $currentSha === '' || $targetSha === $currentSha) {
            return false;
        }

        return $this->gitService->isAncestor($targetSha, $currentSha, $root);
    }

    private function sanitizeBranch(string $branch): string
    {
        $branch = trim($branch);
        if ($branch === '' || preg_match('/[\r\n`|;<>"\']/', $branch) === 1) {
            throw new \InvalidArgumentException((string)__('分支名称无效。'));
        }

        return $branch;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveDeployRoot(array $settings): string
    {
        $deployRoot = trim((string)($settings['deploy_root'] ?? ''));
        if ($deployRoot === '') {
            return rtrim(BP, "\\/");
        }

        return rtrim($deployRoot, "\\/");
    }
}
