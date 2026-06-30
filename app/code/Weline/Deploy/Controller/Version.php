<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller;

use Weline\Deploy\Service\DeployReleaseRuntimeService;
use Weline\Deploy\Service\DeployConfigService;
use Weline\Deploy\Service\DeployProjectProfileService;
use Weline\Framework\App\Controller\FrontendController;

/**
 * GET /deploy/version —— 运行时版本探测端点。
 *
 * 无 token 时返回最小信息；有 token 或 deploy=dev 时返回详情。
 */
class Version extends FrontendController
{
    public function __construct(
        private readonly DeployReleaseRuntimeService $runtimeService,
        private readonly DeployConfigService         $configService,
        private readonly DeployProjectProfileService $profileService,
    ) {
    }

    /**
     * GET /deploy/version
     */
    public function index(): string
    {
        $requestContext = $this->requestContext();
        $releaseContext = $this->emptyReleaseContext();
        $effectiveConfig = $this->loadVersionConfig();
        if ($this->hasProjectContext($requestContext)) {
            $releaseContext = $this->profileService->getReleaseContext($requestContext);
            $effectiveConfig = $this->profileService->buildReleaseConfigForContext($releaseContext, $effectiveConfig);
        }
        $current = $this->runtimeService->getCurrent($this->runtimeRootFromConfig($effectiveConfig));
        if (!$current) {
            return $this->fetchJson(['ok' => false, 'error' => 'no release recorded'], 404);
        }

        $result = [
            'ok'             => true,
            'deploy_version' => (string)($current['deploy_version'] ?? ''),
            'release_id'     => (string)($current['release_id'] ?? ''),
            'git_ref_type'   => (string)($current['git_ref_type'] ?? ''),
            'deployed_at'    => (int)($current['deployed_at'] ?? 0),
        ];
        foreach (['profile_key', 'project_id', 'domain', 'project_type'] as $key) {
            $value = (string)($current[$key] ?? $releaseContext[$key] ?? '');
            if ($value !== '') {
                $result[$key] = $value;
            }
        }

        // 详细模式：dev 环境或有效 token
        $probeToken = (string)($this->configService->getSettings()['deploy_probe_token'] ?? '');
        $requestToken = (string)$this->request->getGet('token', '');
        $isDev = \Weline\Framework\App\Env::system('deploy', 'prod') === 'dev';

        if ($isDev || ($probeToken !== '' && hash_equals($probeToken, $requestToken))) {
            $result['worker_build_id'] = (string)($current['worker_build_id'] ?? '');
            $result['git_commit']      = (string)($current['git_commit'] ?? '');
            $result['git_ref']         = (string)($current['git_ref'] ?? '');
            $result['git_tag']         = $current['git_tag'] ?? null;
            $result['git_branch']      = $current['git_branch'] ?? null;
            $result['deploy_mode']     = (string)($current['deploy_mode'] ?? '');
        }

        return $this->fetchJson($result);
    }

    /**
     * @return array<string, string>
     */
    private function requestContext(): array
    {
        return [
            'profile_key' => trim((string)$this->request->getGet('profile_key', '')),
            'project_id' => trim((string)$this->request->getGet('project_id', '')),
            'domain' => trim((string)$this->request->getGet('domain', '')),
            'project_type' => trim((string)$this->request->getGet('project_type', '')),
        ];
    }

    private function runtimeRootFromConfig(array $config): ?string
    {
        $deployRoot = trim((string)($config['DEPLOY_ROOT'] ?? ''));
        return $deployRoot !== '' ? $deployRoot : null;
    }

    /**
     * @return array{profile_key:string,project_id:string,domain:string,project_type:string}
     */
    private function emptyReleaseContext(): array
    {
        return [
            'profile_key' => '',
            'project_id' => '',
            'domain' => '',
            'project_type' => '',
        ];
    }

    private function hasProjectContext(array $context): bool
    {
        foreach (['profile_key', 'project_id', 'domain', 'project_type'] as $key) {
            $value = $context[$key] ?? '';
            if (is_scalar($value) && trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadVersionConfig(): array
    {
        $config = $this->configService->getWebhookShellConfig();
        if ($config !== []) {
            return $config;
        }

        return $this->loadFileConfig(BP . 'dev/deploy/.config');
    }

    /**
     * @return array<string, string>
     */
    private function loadFileConfig(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $config = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $value = trim($value, "'\"");
            }
            if ($key !== '') {
                $config[$key] = $value;
            }
        }

        return $config;
    }
}
