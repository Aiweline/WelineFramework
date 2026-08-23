<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

/**
 * Build a machine-readable deployment plan without mutating configuration or
 * invoking the release orchestrator.
 */
class DeployMachinePlanService
{
    private const SCHEMA_VERSION = 'deploy-machine-plan.v1';

    /** @var array<string,true> */
    private const SAFE_RELEASE_CONFIG_KEYS = [
        'DEPLOY_ROOT' => true,
        'GIT_BRANCH' => true,
        'GIT_REMOTE_NAME' => true,
        'RUN_COMPOSER_INSTALL' => true,
        'COMPOSER_COMMAND' => true,
        'POST_DEPLOY_COMMAND' => true,
        'GIT_UPDATE_MODE' => true,
        'BACKUP_BEFORE_DEPLOY' => true,
        'CLEAN_BEFORE_DEPLOY' => true,
    ];

    /** @var list<string> */
    private const OPERATIONS = ['config', 'preflight', 'release'];

    /** @var list<string> */
    private const TARGETS = ['local', 'staging', 'production'];

    public function __construct(
        private readonly DeployConfigService $configService,
        private readonly DeployReleaseControlService $releaseControlService,
        private readonly DeployProjectCommandPolicyService $commandPolicyService,
        private readonly DeployWebhookSetupService $webhookSetupService,
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function build(array $request): array
    {
        $operation = $this->normalizeOperation((string)($request['operation'] ?? 'preflight'));
        $target = $this->normalizeTarget((string)($request['target'] ?? 'local'));
        $base = $this->baseResult($operation, $target);

        if ($target === 'local') {
            return array_replace($base, [
                'status' => 'not_applicable',
                'deployment_blocked' => true,
                'message' => '本地开发环境不启用发布桥接。',
            ]);
        }

        $settings = $this->configService->getSettings();
        $baseUrl = rtrim(trim((string)($request['base_url'] ?? '')), '/');
        $configuration = $this->buildConfiguration($settings, $baseUrl);

        if ($operation === 'config') {
            return array_replace($base, [
                'status' => $configuration['questions'] === [] ? 'ready' : 'needs_configuration',
                'deployment_blocked' => $configuration['questions'] !== [],
                'configuration' => $configuration,
                'config_summary' => $this->safeConfigSummary($settings),
            ]);
        }

        $blockers = [];
        $releaseSelection = null;
        if ($operation === 'release') {
            $releaseSelection = $this->validateReleaseSelection($request, $blockers);
        }

        $checks = $this->buildPreflightChecks($settings, $baseUrl, $configuration, $blockers);
        if ($blockers !== []) {
            return array_replace($base, [
                'status' => 'blocked',
                'deployment_blocked' => true,
                'configuration' => $configuration,
                'checks' => $checks,
                'blockers' => $blockers,
                'config_summary' => $this->safeConfigSummary($settings),
            ]);
        }

        if ($configuration['questions'] !== []) {
            return array_replace($base, [
                'status' => 'needs_configuration',
                'deployment_blocked' => true,
                'configuration' => $configuration,
                'checks' => $checks,
                'config_summary' => $this->safeConfigSummary($settings),
            ]);
        }

        $result = array_replace($base, [
            'status' => 'ready',
            'deployment_blocked' => false,
            'configuration' => $configuration,
            'checks' => $checks,
            'config_summary' => $this->safeConfigSummary($settings),
        ]);

        if ($operation !== 'release' || $releaseSelection === null) {
            return $result;
        }

        $params = $this->releaseControlService->buildReleaseParams(
            $releaseSelection['ref_type'],
            $releaseSelection['ref'],
        );
        $config = is_array($params['config'] ?? null) ? $params['config'] : [];
        unset($params['config'], $params['printer']);

        $result['release'] = array_replace($params, [
            'ref_type' => $releaseSelection['ref_type'],
            'config_summary' => $this->redactConfig($config),
            'steps' => [
                'Run the read-only preflight again against the selected target.',
                'Request explicit authorization for the real release command.',
                'Execute Weline_Deploy through its public CLI and observe completion.',
            ],
        ]);

        return $result;
    }

    /** @return array<string,mixed> */
    private function baseResult(string $operation, string $target): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'operation' => $operation,
            'target' => $target,
            'status' => 'blocked',
            'development_blocked' => false,
            'deployment_blocked' => true,
            'repository_written' => false,
            'settings_written' => false,
            'requires_execution_authorization' => $operation === 'release',
            'execution_authorized' => false,
            'release_executed' => false,
            'orchestrator_called' => false,
            'checks' => [],
            'blockers' => [],
        ];
    }

    private function normalizeOperation(string $operation): string
    {
        $operation = strtolower(trim($operation));
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('部署操作仅支持 config、preflight 或 release。');
        }

        return $operation;
    }

    private function normalizeTarget(string $target): string
    {
        $target = strtolower(trim($target));
        $target = match ($target) {
            'dev', 'development' => 'local',
            'pre', 'stage', 'test' => 'staging',
            'prod' => 'production',
            default => $target,
        };
        if (!in_array($target, self::TARGETS, true)) {
            throw new \InvalidArgumentException('部署目标仅支持 local、staging 或 production。');
        }

        return $target;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{configured:array<string,bool>,questions:list<array{key:string,prompt:string,secret:bool}>}
     */
    private function buildConfiguration(array $settings, string $baseUrl): array
    {
        $requirements = [
            'project_repo_url' => ['请提供目标项目 Git 仓库地址。', false],
            'deploy_root' => ['请提供目标服务器上的绝对部署目录。', false],
            'webhook_path' => ['请配置部署 Webhook 路径。', false],
            'webhook_secret' => ['请配置部署 Webhook 密钥；请勿在 AI 会话中粘贴。', true],
            'base_url' => ['请提供目标站点 HTTPS 基础地址，用于只读健康检查。', false],
        ];
        $configured = [];
        $questions = [];
        foreach ($requirements as $key => [$prompt, $secret]) {
            $value = $key === 'base_url' ? $baseUrl : trim((string)($settings[$key] ?? ''));
            $configured[$key] = $value !== '';
            if ($value === '') {
                $questions[] = ['key' => $key, 'prompt' => $prompt, 'secret' => $secret];
            }
        }

        return ['configured' => $configured, 'questions' => $questions];
    }

    /**
     * @param array<string,mixed> $request
     * @param list<array{code:string,message:string}> $blockers
     * @return null|array{ref_type:string,ref:string}
     */
    private function validateReleaseSelection(array $request, array &$blockers): ?array
    {
        $refType = strtolower(trim((string)($request['ref_type'] ?? '')));
        $ref = trim((string)($request['ref'] ?? ''));
        if (!in_array($refType, ['commit', 'tag'], true) || $ref === '') {
            $blockers[] = [
                'code' => 'REF_SELECTION_REQUIRED',
                'message' => '真实发布前必须明确选择 commit 或 tag，并提供对应 ref。',
            ];
            return null;
        }

        try {
            if ($refType === 'commit') {
                if (preg_match('/^[0-9a-f]{7,40}$/i', $ref) !== 1) {
                    throw new \InvalidArgumentException('commit 必须是 7-40 位十六进制 SHA。');
                }
                $ref = strtolower($ref);
            } else {
                $ref = str_starts_with($ref, 'refs/tags/') ? substr($ref, 10) : $ref;
                $ref = $this->commandPolicyService->normalizeRollbackRef($ref);
                if ($ref === '' || $this->commandPolicyService->rollbackRefKind($ref) !== 'tag') {
                    throw new \InvalidArgumentException('tag 必须是明确的标签名称。');
                }
            }
        } catch (\InvalidArgumentException $exception) {
            $blockers[] = ['code' => 'INVALID_RELEASE_REF', 'message' => $exception->getMessage()];
            return null;
        }

        return ['ref_type' => $refType, 'ref' => $ref];
    }

    /**
     * @param array<string,mixed> $settings
     * @param array{configured:array<string,bool>,questions:list<array{key:string,prompt:string,secret:bool}>} $configuration
     * @param list<array{code:string,message:string}> $blockers
     * @return list<array<string,mixed>>
     */
    private function buildPreflightChecks(
        array $settings,
        string $baseUrl,
        array $configuration,
        array &$blockers,
    ): array {
        $checks = [];
        $checks[] = [
            'name' => 'configuration',
            'status' => $configuration['questions'] === [] ? 'pass' : 'needs_configuration',
            'missing' => array_column($configuration['questions'], 'key'),
        ];

        $deployRoot = trim((string)($settings['deploy_root'] ?? ''));
        $rootValid = $deployRoot !== '' && $this->isAbsolutePath($deployRoot) && is_dir($deployRoot);
        $checks[] = [
            'name' => 'deploy_root',
            'status' => $deployRoot === '' ? 'skipped' : ($rootValid ? 'pass' : 'fail'),
            'absolute_existing_directory' => $rootValid,
        ];
        if ($deployRoot !== '' && !$rootValid) {
            $blockers[] = [
                'code' => 'INVALID_DEPLOY_ROOT',
                'message' => '部署目录必须是明确、已存在的绝对路径。',
            ];
        }

        try {
            $composer = '';
            if ($this->isEnabled($settings['run_composer_install'] ?? '0')) {
                $composer = $this->commandPolicyService->normalizeComposerCommand(
                    (string)($settings['composer_command'] ?? ''),
                );
                if ($composer === '') {
                    throw new \InvalidArgumentException('启用 Composer 安装后必须配置白名单命令。');
                }
            }
            $postDeploy = $this->commandPolicyService->normalizePostDeployCommand(
                (string)($settings['post_deploy_command'] ?? ''),
            );
            $checks[] = [
                'name' => 'command_policy',
                'status' => 'pass',
                'composer_install_enabled' => $this->isEnabled($settings['run_composer_install'] ?? '0'),
                'composer_command' => $composer,
                'post_deploy_command' => $postDeploy,
            ];
        } catch (\InvalidArgumentException $exception) {
            $checks[] = ['name' => 'command_policy', 'status' => 'fail', 'message' => $exception->getMessage()];
            $blockers[] = ['code' => 'COMMAND_POLICY_FAILED', 'message' => $exception->getMessage()];
        }

        if ($configuration['questions'] !== []) {
            $checks[] = ['name' => 'webhook_health', 'status' => 'skipped', 'reason' => 'configuration_incomplete'];
            return $checks;
        }

        $versionUrl = $this->webhookSetupService->buildVersionUrl($settings, $baseUrl);
        if ($versionUrl === '') {
            $checks[] = ['name' => 'webhook_health', 'status' => 'fail', 'reason' => 'version_url_unavailable'];
            $blockers[] = ['code' => 'WEBHOOK_URL_UNAVAILABLE', 'message' => '无法生成 Webhook 版本健康检查地址。'];
            return $checks;
        }

        $health = $this->probeWebhookHealth($versionUrl);
        $checks[] = array_replace(['name' => 'webhook_health'], $health);
        if (($health['status'] ?? 'fail') !== 'pass') {
            $blockers[] = ['code' => 'WEBHOOK_HEALTH_FAILED', 'message' => 'Webhook 只读健康检查未通过。'];
        }

        return $checks;
    }

    /** @return array{status:string,http_status:int,url_hash:string,message?:string} */
    protected function probeWebhookHealth(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: Weline-Deploy-Plan/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})(?:\s|$)/', (string)$headers[0], $match) === 1) {
            $status = (int)$match[1];
        }
        $passed = $body !== false && $status >= 200 && $status < 300;

        return [
            'status' => $passed ? 'pass' : 'fail',
            'http_status' => $status,
            'url_hash' => hash('sha256', $url),
            ...($passed ? [] : ['message' => '未获得成功的 Webhook 版本响应。']),
        ];
    }

    /** @param array<string,mixed> $settings */
    private function safeConfigSummary(array $settings): array
    {
        return [
            'repository_configured' => trim((string)($settings['project_repo_url'] ?? '')) !== '',
            'deploy_root' => trim((string)($settings['deploy_root'] ?? '')),
            'project_remote' => trim((string)($settings['project_remote'] ?? 'origin')) ?: 'origin',
            'backup_before_deploy' => $this->isEnabled($settings['backup_before_deploy'] ?? '0'),
            'composer_install_enabled' => $this->isEnabled($settings['run_composer_install'] ?? '0'),
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function redactConfig(array $config): array
    {
        $safe = [];
        foreach ($config as $key => $value) {
            $key = (string)$key;
            if (!isset(self::SAFE_RELEASE_CONFIG_KEYS[$key])) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function isEnabled(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
