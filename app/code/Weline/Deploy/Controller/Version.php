<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller;

use Weline\Deploy\Service\DeployReleaseRuntimeService;
use Weline\Deploy\Service\DeployConfigService;
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
    ) {
    }

    /**
     * GET /deploy/version
     */
    public function index(): string
    {
        $current = $this->runtimeService->getCurrent();
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
}
