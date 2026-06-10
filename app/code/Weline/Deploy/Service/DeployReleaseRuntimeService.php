<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;

/**
 * 读写 var/deploy/current.json —— 运行时版本戳。
 *
 * 写时机：Git + 后置命令全部成功之后、server:reload 之前。
 * 读时机：探测端点、themeConfigPayload 注入、CLI。
 */
class DeployReleaseRuntimeService
{
    private string $filePath;

    public function __construct()
    {
        $this->filePath = BP . 'var' . DS . 'deploy' . DS . 'current.json';
    }

    /**
     * 读取当前运行时版本信息。
     *
     * @return array{release_id:string, deploy_version:string, worker_build_id:string, git_commit:string, git_ref_type:string, git_ref:string, git_tag:string|null, git_branch:string|null, deployed_at:int, deploy_mode:string}|null
     */
    public function getCurrent(): ?array
    {
        if (!is_file($this->filePath)) {
            return null;
        }
        $content = file_get_contents($this->filePath);
        if ($content === false || trim($content) === '') {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 获取当前 deploy_version（快速读取）。
     */
    public function getDeployVersion(): string
    {
        $current = $this->getCurrent();
        return (string)($current['deploy_version'] ?? '');
    }

    /**
     * 写入当前发布信息。目录不存在时自动创建。
     *
     * @param array{
     *     release_id:string,
     *     deploy_version:string,
     *     worker_build_id:string,
     *     git_commit:string,
     *     git_ref_type:string,
     *     git_ref:string,
     *     git_tag:string|null,
     *     git_branch:string|null,
     *     deployed_at:int,
     *     deploy_mode:string
     * } $data
     */
    public function saveCurrent(array $data): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(__('无法创建部署版本目录：%{1}', [$dir]));
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->filePath, $json) === false) {
            throw new \RuntimeException(__('无法写入部署版本文件'));
        }
        @chmod($this->filePath, 0664);
    }

    /**
     * 生成 release_id：时间戳 + 版本后缀。
     */
    public function generateReleaseId(string $versionSuffix): string
    {
        return date('Ymd-His') . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '', $versionSuffix);
    }

    /**
     * 生成 worker_build_id。
     */
    public function generateWorkerBuildId(): string
    {
        return 'wls-' . date('Ymd-His');
    }

    /**
     * 同步写入 Env 层，让静态资源版本号等读取到最新值。
     */
    public function syncEnv(string $deployVersion, string $workerBuildId): void
    {
        Env::getInstance()->setConfig('deploy_version', $deployVersion);
        Env::getInstance()->setConfig('worker_build_id', $workerBuildId);
        Env::getInstance()->setConfig('theme.static_version', $deployVersion);
    }
}
