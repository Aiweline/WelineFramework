<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;

class DeployReleaseRuntimeService
{
    private string $filePath;

    public function __construct()
    {
        $this->filePath = BP . 'var' . DS . 'deploy' . DS . 'current.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCurrent(?string $root = null): ?array
    {
        $filePath = $this->filePathForRoot($root);
        if (!is_file($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    public function getDeployVersion(?string $root = null): string
    {
        $current = $this->getCurrent($root);
        return (string)($current['deploy_version'] ?? '');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveCurrent(array $data, ?string $root = null): void
    {
        $filePath = $this->filePathForRoot($root);
        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(__('无法创建部署版本目录：%{1}', [$dir]));
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($filePath, $json) === false) {
            throw new \RuntimeException(__('无法写入部署版本文件'));
        }
        @chmod($filePath, 0664);
    }

    public function generateReleaseId(string $versionSuffix): string
    {
        return date('Ymd-His') . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '', $versionSuffix);
    }

    public function generateWorkerBuildId(): string
    {
        return 'wls-' . date('Ymd-His');
    }

    public function syncEnv(string $deployVersion, string $workerBuildId): void
    {
        Env::getInstance()->setConfig('deploy_version', $deployVersion);
        Env::getInstance()->setConfig('worker_build_id', $workerBuildId);
        Env::getInstance()->setConfig('theme.static_version', $deployVersion);
    }

    private function filePathForRoot(?string $root): string
    {
        $root = trim((string)$root);
        if ($root === '') {
            return $this->filePath;
        }

        return rtrim($root, "\\/") . DS . 'var' . DS . 'deploy' . DS . 'current.json';
    }
}
