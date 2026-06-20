<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Deploy\Model\DeployProjectProfile;
use Weline\Framework\Manager\ObjectManager;

class DeployProjectProfileService
{
    public function __construct(
        private readonly DeployProjectCommandPolicyService $commandPolicyService
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $globalSettings
     * @return array<string, mixed>
     */
    public function getFormData(array $context, array $globalSettings): array
    {
        $profile = $this->loadForContext($context);
        $data = $profile instanceof DeployProjectProfile ? $profile->getData() : [];
        $releaseContext = $this->getReleaseContext($context);
        $projectId = $releaseContext['project_id'];
        $domain = $releaseContext['domain'];
        $profileKey = $releaseContext['profile_key'] !== '' ? $releaseContext['profile_key'] : 'local';

        return [
            'has_profile' => $profile instanceof DeployProjectProfile && (int)$profile->getData(DeployProjectProfile::schema_fields_ID) > 0,
            'profile_id' => (int)($data[DeployProjectProfile::schema_fields_ID] ?? 0),
            'profile_key' => (string)($data[DeployProjectProfile::schema_fields_PROFILE_KEY] ?? $profileKey),
            'project_id' => (string)($data[DeployProjectProfile::schema_fields_PROJECT_ID] ?? $projectId),
            'domain' => (string)($data[DeployProjectProfile::schema_fields_DOMAIN] ?? $domain),
            'project_type' => (string)($data[DeployProjectProfile::schema_fields_PROJECT_TYPE] ?? $releaseContext['project_type']),
            'enabled' => (string)($data[DeployProjectProfile::schema_fields_ENABLED] ?? '0') === '1',
            'project_repo_url' => (string)($data[DeployProjectProfile::schema_fields_REPO_URL] ?? ($globalSettings['project_repo_url'] ?? '')),
            'project_branch' => (string)($data[DeployProjectProfile::schema_fields_BRANCH] ?? ($globalSettings['project_branch'] ?? '')),
            'project_remote' => (string)($data[DeployProjectProfile::schema_fields_REMOTE] ?? ($globalSettings['project_remote'] ?? 'origin')),
            'deploy_root' => (string)($data[DeployProjectProfile::schema_fields_DEPLOY_ROOT] ?? ($globalSettings['deploy_root'] ?? '')),
            'deploy_trigger_mode' => (string)($data[DeployProjectProfile::schema_fields_TRIGGER_MODE] ?? ($globalSettings['deploy_trigger_mode'] ?? DeployConfigService::TRIGGER_MODE_TAG)),
            'webhook_branch' => (string)($data[DeployProjectProfile::schema_fields_WEBHOOK_BRANCH] ?? ($globalSettings['webhook_branch'] ?? '')),
            'webhook_tag_prefix' => (string)($data[DeployProjectProfile::schema_fields_WEBHOOK_TAG_PREFIX] ?? ($globalSettings['webhook_tag_prefix'] ?? '')),
            'webhook_secret_configured' => \trim((string)($data[DeployProjectProfile::schema_fields_WEBHOOK_SECRET] ?? '')) !== '',
            'git_update_mode' => (string)($data[DeployProjectProfile::schema_fields_GIT_UPDATE_MODE] ?? ($globalSettings['git_update_mode'] ?? 'reset')),
            'backup_before_deploy' => (int)($data[DeployProjectProfile::schema_fields_BACKUP_BEFORE_DEPLOY] ?? 1) === 1,
            'run_composer_install' => (int)($data[DeployProjectProfile::schema_fields_RUN_COMPOSER_INSTALL] ?? ($globalSettings['run_composer_install'] ?? 0)) === 1,
            'composer_command' => (string)($data[DeployProjectProfile::schema_fields_COMPOSER_COMMAND] ?? ($globalSettings['composer_command'] ?? '')),
            'post_deploy_command' => (string)($data[DeployProjectProfile::schema_fields_POST_DEPLOY_COMMAND] ?? ($globalSettings['post_deploy_command'] ?? '')),
            'rollback_ref' => (string)($data[DeployProjectProfile::schema_fields_ROLLBACK_REF] ?? ''),
            'description' => (string)($data[DeployProjectProfile::schema_fields_DESCRIPTION] ?? ''),
            'source_label' => $profile instanceof DeployProjectProfile ? (string)__('项目 Profile') : (string)__('继承全局配置'),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool,message:string,profile_id:int,profile:array<string,mixed>}
     */
    public function saveFromPanel(array $input): array
    {
        try {
            $context = [
                'project_id' => (string)($input['project_id'] ?? ''),
                'domain' => (string)($input['domain'] ?? ''),
                'project_type' => (string)($input['project_type'] ?? ''),
            ];
            $existing = $this->loadForContext($context);
            /** @var DeployProjectProfile $profile */
            $profile = $existing instanceof DeployProjectProfile
                ? $existing
                : ObjectManager::getInstance(DeployProjectProfile::class);
            $composerCommand = $this->commandPolicyService->normalizeComposerCommand((string)($input['composer_command'] ?? ''));
            $postDeployCommand = $this->commandPolicyService->normalizePostDeployCommand((string)($input['post_deploy_command'] ?? ''));
            $rollbackRef = $this->commandPolicyService->normalizeRollbackRef((string)($input['rollback_ref'] ?? ''));
            $incomingWebhookSecret = $this->normalizeSecretInput((string)($input['webhook_secret'] ?? ''));
            $currentWebhookSecret = $existing instanceof DeployProjectProfile
                ? (string)$existing->getData(DeployProjectProfile::schema_fields_WEBHOOK_SECRET)
                : '';
            $storedWebhookSecret = $currentWebhookSecret !== '' ? $currentWebhookSecret : null;
            if ((string)($input['clear_webhook_secret'] ?? '0') === '1') {
                $storedWebhookSecret = null;
            } elseif ($incomingWebhookSecret !== '') {
                $storedWebhookSecret = $incomingWebhookSecret;
            }

            $profile->setData([
                DeployProjectProfile::schema_fields_PROFILE_KEY => (string)($input['profile_key'] ?? ''),
                DeployProjectProfile::schema_fields_PROJECT_ID => (string)($input['project_id'] ?? ''),
                DeployProjectProfile::schema_fields_DOMAIN => (string)($input['domain'] ?? ''),
                DeployProjectProfile::schema_fields_PROJECT_TYPE => (string)($input['project_type'] ?? ''),
                DeployProjectProfile::schema_fields_ENABLED => (string)($input['enabled'] ?? '0') === '1' ? 1 : 0,
                DeployProjectProfile::schema_fields_REPO_URL => (string)($input['project_repo_url'] ?? ''),
                DeployProjectProfile::schema_fields_BRANCH => (string)($input['project_branch'] ?? ''),
                DeployProjectProfile::schema_fields_REMOTE => (string)($input['project_remote'] ?? 'origin'),
                DeployProjectProfile::schema_fields_DEPLOY_ROOT => (string)($input['deploy_root'] ?? ''),
                DeployProjectProfile::schema_fields_TRIGGER_MODE => (string)($input['deploy_trigger_mode'] ?? DeployConfigService::TRIGGER_MODE_TAG),
                DeployProjectProfile::schema_fields_WEBHOOK_BRANCH => (string)($input['webhook_branch'] ?? ''),
                DeployProjectProfile::schema_fields_WEBHOOK_TAG_PREFIX => (string)($input['webhook_tag_prefix'] ?? ''),
                DeployProjectProfile::schema_fields_WEBHOOK_SECRET => $storedWebhookSecret,
                DeployProjectProfile::schema_fields_GIT_UPDATE_MODE => (string)($input['git_update_mode'] ?? 'reset'),
                DeployProjectProfile::schema_fields_BACKUP_BEFORE_DEPLOY => (string)($input['backup_before_deploy'] ?? '0') === '1' ? 1 : 0,
                DeployProjectProfile::schema_fields_RUN_COMPOSER_INSTALL => (string)($input['run_composer_install'] ?? '0') === '1' ? 1 : 0,
                DeployProjectProfile::schema_fields_COMPOSER_COMMAND => $composerCommand,
                DeployProjectProfile::schema_fields_POST_DEPLOY_COMMAND => $postDeployCommand,
                DeployProjectProfile::schema_fields_ROLLBACK_REF => $rollbackRef,
                DeployProjectProfile::schema_fields_DESCRIPTION => (string)($input['description'] ?? ''),
            ]);
            $profile->save();

            return [
                'success' => true,
                'message' => (string)__('项目发布 Profile 已保存。'),
                'profile_id' => (int)$profile->getData(DeployProjectProfile::schema_fields_ID),
                'profile' => $profile->getData(),
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'profile_id' => 0,
                'profile' => [],
            ];
        }
    }

    /**
     * Build a safe release preflight summary for the WLS Panel.
     *
     * This method only validates stored configuration shape and command policy.
     * It must not run Git, write files, or trigger a real release.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $settings
     * @return array{status:string,label:string,checks:array<int,array{key:string,title:string,state:string,label:string,detail:string}>}
     */
    public function buildPanelPreflight(array $profile, array $settings): array
    {
        $checks = [
            $this->profilePreflightCheck($profile),
            $this->repositoryPreflightCheck($settings),
            $this->deployRootPreflightCheck($settings),
            $this->triggerModePreflightCheck($settings),
            $this->webhookPreflightCheck($settings),
            $this->commandPolicyPreflightCheck($settings),
            $this->rollbackPolicyPreflightCheck($profile),
        ];

        $status = 'ok';
        foreach ($checks as $check) {
            if ($check['state'] === 'danger') {
                $status = 'danger';
                break;
            }
            if ($check['state'] === 'warning') {
                $status = 'warning';
            }
        }

        return [
            'status' => $status,
            'label' => $this->preflightStatusLabel($status),
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{profile_key:string,project_id:string,domain:string,project_type:string}
     */
    public function getReleaseContext(array $context): array
    {
        $normalized = $this->normalizeContext($context);
        if ($normalized['profile_key'] === '') {
            $normalized['profile_key'] = DeployProjectProfile::buildProfileKey(
                $normalized['project_id'],
                $normalized['domain']
            );
        }

        return $normalized;
    }

    /**
     * Build one-shot release config for a WLS project context.
     *
     * It overlays enabled project Profile fields, including the project-scoped
     * webhook secret when one is configured.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $baseConfig
     * @return array<string, mixed>
     */
    public function buildReleaseConfigForContext(array $context, array $baseConfig): array
    {
        $releaseContext = $this->getReleaseContext($context);
        $config = $this->applyReleaseContextToConfig($baseConfig, $releaseContext);
        $profile = $this->loadForContext($releaseContext);

        if (!$profile instanceof DeployProjectProfile) {
            return $config;
        }

        if ((int)$profile->getData(DeployProjectProfile::schema_fields_ENABLED) !== 1) {
            return $config;
        }

        $data = $profile->getData();
        $map = [
            DeployProjectProfile::schema_fields_REPO_URL => ['GIT_REPO_URL', 'GIT_REMOTE_URL'],
            DeployProjectProfile::schema_fields_BRANCH => ['GIT_BRANCH'],
            DeployProjectProfile::schema_fields_REMOTE => ['GIT_REMOTE', 'GIT_REMOTE_NAME'],
            DeployProjectProfile::schema_fields_DEPLOY_ROOT => ['DEPLOY_ROOT'],
            DeployProjectProfile::schema_fields_TRIGGER_MODE => ['DEPLOY_TRIGGER_MODE'],
            DeployProjectProfile::schema_fields_WEBHOOK_BRANCH => ['WEBHOOK_BRANCH'],
            DeployProjectProfile::schema_fields_WEBHOOK_TAG_PREFIX => ['WEBHOOK_TAG_PREFIX'],
            DeployProjectProfile::schema_fields_WEBHOOK_SECRET => ['WEBHOOK_SECRET'],
            DeployProjectProfile::schema_fields_GIT_UPDATE_MODE => ['GIT_UPDATE_MODE'],
            DeployProjectProfile::schema_fields_COMPOSER_COMMAND => ['COMPOSER_COMMAND'],
            DeployProjectProfile::schema_fields_POST_DEPLOY_COMMAND => ['POST_DEPLOY_COMMAND'],
        ];

        foreach ($map as $field => $targets) {
            $value = \trim((string)($data[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            foreach ($targets as $target) {
                $config[$target] = $value;
            }
        }

        $config['BACKUP_BEFORE_DEPLOY'] = (int)($data[DeployProjectProfile::schema_fields_BACKUP_BEFORE_DEPLOY] ?? 1) === 1
            ? 'true'
            : 'false';
        $config['RUN_COMPOSER_INSTALL'] = (int)($data[DeployProjectProfile::schema_fields_RUN_COMPOSER_INSTALL] ?? 0) === 1
            ? '1'
            : '0';

        return $this->applyReleaseContextToConfig($config, [
            'profile_key' => (string)($data[DeployProjectProfile::schema_fields_PROFILE_KEY] ?? $releaseContext['profile_key']),
            'project_id' => (string)($data[DeployProjectProfile::schema_fields_PROJECT_ID] ?? $releaseContext['project_id']),
            'domain' => (string)($data[DeployProjectProfile::schema_fields_DOMAIN] ?? $releaseContext['domain']),
            'project_type' => (string)($data[DeployProjectProfile::schema_fields_PROJECT_TYPE] ?? $releaseContext['project_type']),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function loadForContext(array $context): ?DeployProjectProfile
    {
        $releaseContext = $this->getReleaseContext($context);
        $profileKey = $releaseContext['profile_key'] !== '' ? $releaseContext['profile_key'] : 'local';

        /** @var DeployProjectProfile $model */
        $model = ObjectManager::getInstance(DeployProjectProfile::class);
        $collection = $model->reset()
            ->where(DeployProjectProfile::schema_fields_PROFILE_KEY, $profileKey)
            ->select()
            ->pagination(1, 1)
            ->fetch();
        $items = $collection->getItems();
        $result = $items[0] ?? null;

        return $result instanceof DeployProjectProfile ? $result : null;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{key:string,title:string,state:string,label:string,detail:string}
     */
    private function profilePreflightCheck(array $profile): array
    {
        $hasProfile = !empty($profile['has_profile']);
        $enabled = !empty($profile['enabled']);

        if ($hasProfile && $enabled) {
            return $this->preflightCheck(
                'profile',
                (string)__('Profile 来源'),
                'ok',
                (string)__('已启用'),
                (string)__('当前发布会优先读取该项目的独立 Profile。')
            );
        }

        if ($hasProfile) {
            return $this->preflightCheck(
                'profile',
                (string)__('Profile 来源'),
                'warning',
                (string)__('已保存未启用'),
                (string)__('该项目已有 Profile，但未启用；发布仍会继承全局 Deploy 配置。')
            );
        }

        return $this->preflightCheck(
            'profile',
            (string)__('Profile 来源'),
            'warning',
            (string)__('继承全局'),
            (string)__('当前项目尚未保存独立 Profile，发布会使用全局 Deploy 配置。')
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{key:string,title:string,state:string,label:string,detail:string}
     */
    private function repositoryPreflightCheck(array $settings): array
    {
        $repoUrl = \trim((string)($settings['project_repo_url'] ?? ''));
        if ($repoUrl === '') {
            return $this->preflightCheck(
                'repo',
                (string)__('仓库配置'),
                'danger',
                (string)__('缺少仓库'),
                (string)__('必须配置项目仓库地址后才能执行发布。')
            );
        }

        if (!$this->isSupportedRepositoryUrl($repoUrl)) {
            return $this->preflightCheck(
                'repo',
                (string)__('仓库配置'),
                'danger',
                (string)__('地址不合法'),
                (string)__('项目仓库地址只支持 http(s)、ssh:// 或 git@host:path.git 形式。')
            );
        }

        return $this->preflightCheck(
            'repo',
            (string)__('仓库配置'),
            'ok',
            (string)__('已配置'),
            (string)__('仓库地址格式可被发布服务识别。')
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{key:string,title:string,state:string,label:string,detail:string}
     */
    private function deployRootPreflightCheck(array $settings): array
    {
        $deployRoot = \trim((string)($settings['deploy_root'] ?? ''));
        if ($deployRoot === '') {
            return $this->preflightCheck(
                'deploy-root',
                (string)__('部署目录'),
                'warning',
                (string)__('未配置'),
                (string)__('未设置部署根目录；后续发布会回退到当前运行项目目录。')
            );
        }

        if (\preg_match('/[\r\n`|;<>"\']/', $deployRoot) === 1) {
            return $this->preflightCheck(
                'deploy-root',
                (string)__('部署目录'),
                'danger',
                (string)__('路径需检查'),
                (string)__('部署根目录包含不适合发布配置的控制字符。')
            );
        }

        return $this->preflightCheck(
            'deploy-root',
            (string)__('部署目录'),
            'ok',
            (string)__('已配置'),
            (string)__('部署根目录已填写；预检不访问文件系统，也不会创建目录。')
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{key:string,title:string,state:string,label:string,detail:string}
     */
    private function triggerModePreflightCheck(array $settings): array
    {
        $triggerMode = \trim((string)($settings['deploy_trigger_mode'] ?? DeployConfigService::TRIGGER_MODE_TAG));
        if (!\in_array($triggerMode, DeployConfigService::TRIGGER_MODES, true)) {
            return $this->preflightCheck(
                'trigger',
                (string)__('触发模式'),
                'danger',
                (string)__('不支持'),
                (string)__('部署触发模式不在允许列表内。')
            );
        }

        if ($triggerMode === DeployConfigService::TRIGGER_MODE_TAG) {
            return $this->preflightCheck(
                'trigger',
                (string)__('触发模式'),
                'ok',
                (string)__('仅 Tag Push'),
                (string)__('默认保守策略已生效，分支 Push 不会触发发布。')
            );
        }

        return $this->preflightCheck(
            'trigger',
            (string)__('触发模式'),
            'warning',
            $triggerMode === DeployConfigService::TRIGGER_MODE_BOTH
                ? (string)__('分支 + Tag 都生效')
                : (string)__('仅分支 Push'),
            (string)__('当前项目显式允许分支发布，请确认 Git 平台事件和分支过滤符合预期。')
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{key:string,title:string,state:string,label:string,detail:string}
     */
    private function webhookPreflightCheck(array $settings): array
    {
        $webhookPath = \trim((string)($settings['webhook_path'] ?? ''));
        $hasSecret = \trim((string)($settings['webhook_secret'] ?? '')) !== '';
        $secretSource = \trim((string)($settings['webhook_secret_source'] ?? 'global'));

        if ($secretSource === 'project' && $webhookPath !== '' && \str_starts_with($webhookPath, '~wh~') && $hasSecret) {
            return $this->preflightCheck(
                'webhook',
                (string)__('Webhook 入口'),
                'ok',
                (string)__('项目密钥'),
                (string)__('随机路径已生成，当前项目会使用独立 Webhook 密钥验签。')
            );
        }

        if ($webhookPath === '') {
            return $this->preflightCheck(
                'webhook',
                (string)__('Webhook 入口'),
                'warning',
                (string)__('待生成'),
                (string)__('尚未生成随机 Webhook 路径；请先执行 deploy:webhook:setup。')
            );
        }

        if (!\str_starts_with($webhookPath, '~wh~')) {
            return $this->preflightCheck(
                'webhook',
                (string)__('Webhook 入口'),
                'warning',
                (string)__('路径需轮换'),
                (string)__('Webhook 路径不是 ~wh~ 随机路径，建议使用 deploy:webhook:setup --rotate-path 轮换。')
            );
        }

        if (!$hasSecret) {
            return $this->preflightCheck(
                'webhook',
                (string)__('Webhook 入口'),
                'warning',
                (string)__('密钥未配置'),
                (string)__('Webhook 路径已生成，但访问密钥为空；请生成或保存 webhook_secret。')
            );
        }

        return $this->preflightCheck(
            'webhook',
            (string)__('Webhook 入口'),
            'ok',
            (string)__('已就绪'),
            (string)__('随机路径和访问密钥均已配置。')
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{key:string,title:string,state:string,label:string,detail:string}
     */
    private function commandPolicyPreflightCheck(array $settings): array
    {
        $runComposer = (string)($settings['run_composer_install'] ?? '0') === '1' || ($settings['run_composer_install'] ?? null) === true;
        $composerCommand = \trim((string)($settings['composer_command'] ?? ''));
        $postDeployCommand = \trim((string)($settings['post_deploy_command'] ?? ''));

        try {
            if ($composerCommand !== '') {
                $this->commandPolicyService->normalizeComposerCommand($composerCommand);
            }
            if ($postDeployCommand !== '') {
                $this->commandPolicyService->normalizePostDeployCommand($postDeployCommand);
            }
        } catch (\Throwable $throwable) {
            return $this->preflightCheck(
                'commands',
                (string)__('命令白名单'),
                'danger',
                (string)__('需修正'),
                \mb_substr($throwable->getMessage(), 0, 180)
            );
        }

        if ($runComposer && $composerCommand === '') {
            return $this->preflightCheck(
                'commands',
                (string)__('命令白名单'),
                'warning',
                (string)__('Composer 命令为空'),
                (string)__('已开启 Composer，但未配置 composer install 命令。')
            );
        }

        return $this->preflightCheck(
            'commands',
            (string)__('命令白名单'),
            'ok',
            (string)__('通过'),
            (string)__('Composer 与部署后命令均符合 WLS 发布命令白名单。')
        );
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{key:string,title:string,state:string,label:string,detail:string}
     */
    private function rollbackPolicyPreflightCheck(array $profile): array
    {
        $rollbackRef = \trim((string)($profile['rollback_ref'] ?? ''));
        if ($rollbackRef === '') {
            return $this->preflightCheck(
                'rollback',
                (string)__('回滚策略'),
                'warning',
                (string)__('未配置'),
                (string)__('回滚参考为空；后续不会展示可执行回滚动作。')
            );
        }

        try {
            $normalizedRef = $this->commandPolicyService->normalizeRollbackRef($rollbackRef);
            $kind = $this->commandPolicyService->rollbackRefKind($normalizedRef);
        } catch (\Throwable $throwable) {
            return $this->preflightCheck(
                'rollback',
                (string)__('回滚策略'),
                'danger',
                (string)__('需要修正'),
                \mb_substr($throwable->getMessage(), 0, 180)
            );
        }

        if ($kind === 'tag') {
            return $this->preflightCheck(
                'rollback',
                (string)__('回滚策略'),
                'ok',
                (string)__('Tag 回滚'),
                (string)__('回滚引用已通过 WLS 白名单，将只在当前项目 Profile 下作为后续显式回滚动作的目标。')
            );
        }

        $label = $kind === 'commit' ? (string)__('提交回滚') : (string)__('分支回滚');
        return $this->preflightCheck(
            'rollback',
            (string)__('回滚策略'),
            'warning',
            $label,
            (string)__('回滚参考已通过白名单，但 commit/branch 回滚需要后续显式动作再次确认。')
        );
    }

    /**
     * @return array{key:string,title:string,state:string,label:string,detail:string}
     */
    private function preflightCheck(string $key, string $title, string $state, string $label, string $detail): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'state' => $state,
            'label' => $label,
            'detail' => $detail,
        ];
    }

    private function preflightStatusLabel(string $status): string
    {
        return match ($status) {
            'danger' => (string)__('存在阻断项'),
            'warning' => (string)__('需要确认'),
            default => (string)__('可进入发布流程'),
        };
    }

    private function isSupportedRepositoryUrl(string $repoUrl): bool
    {
        if (\preg_match('/^git@[^:\s]+:.+$/', $repoUrl) === 1) {
            return true;
        }

        $parts = \parse_url($repoUrl);
        if (!\is_array($parts)) {
            return false;
        }

        $scheme = \strtolower((string)($parts['scheme'] ?? ''));
        if (!\in_array($scheme, ['http', 'https', 'ssh'], true)) {
            return false;
        }

        return \trim((string)($parts['host'] ?? '')) !== '';
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        $domain = \preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = \explode('/', $domain, 2)[0] ?? $domain;
        return \trim($domain);
    }

    /**
     * @param array<string, mixed> $config
     * @param array{profile_key:string,project_id:string,domain:string,project_type:string} $context
     * @return array<string, mixed>
     */
    private function applyReleaseContextToConfig(array $config, array $context): array
    {
        $map = [
            'profile_key' => 'PROFILE_KEY',
            'project_id' => 'PROJECT_ID',
            'domain' => 'DOMAIN',
            'project_type' => 'PROJECT_TYPE',
        ];

        foreach ($map as $lowerKey => $upperKey) {
            $value = \trim((string)($context[$lowerKey] ?? ''));
            if ($value !== '') {
                $config[$upperKey] = $value;
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{profile_key:string,project_id:string,domain:string,project_type:string}
     */
    private function normalizeContext(array $context): array
    {
        return [
            'profile_key' => $this->normalizeToken($this->contextValue($context, 'profile_key', 'PROFILE_KEY'), 190),
            'project_id' => $this->normalizeToken($this->contextValue($context, 'project_id', 'PROJECT_ID'), 80),
            'domain' => $this->normalizeDomain($this->contextValue($context, 'domain', 'DOMAIN')),
            'project_type' => $this->normalizeToken($this->contextValue($context, 'project_type', 'PROJECT_TYPE'), 80),
        ];
    }

    private function normalizeToken(string $value, int $maxLength): string
    {
        $value = \trim($value);
        $value = \preg_replace('/[^a-zA-Z0-9:_\-.]/', '', $value) ?? '';
        return \substr($value, 0, $maxLength);
    }

    private function normalizeSecretInput(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if (\preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException((string)__('Webhook 密钥包含不允许的控制字符。'));
        }

        return \mb_substr($value, 0, 255);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function contextValue(array $context, string $lowerKey, string $upperKey): string
    {
        $value = $context[$lowerKey] ?? $context[$upperKey] ?? '';
        return \is_scalar($value) ? \trim((string)$value) : '';
    }
}
