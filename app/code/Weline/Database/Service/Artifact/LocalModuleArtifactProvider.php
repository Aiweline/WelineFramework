<?php

declare(strict_types=1);

namespace Weline\Database\Service\Artifact;

use Weline\Database\Api\ModuleArtifactProviderInterface;
use Weline\Framework\App\Env;

final class LocalModuleArtifactProvider implements ModuleArtifactProviderInterface
{
    public function getName(): string
    {
        return 'local_snapshot';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function listVersions(string $moduleName): array
    {
        $moduleDir = $this->artifactRoot() . DS . $this->safeSegment($moduleName);
        if (!is_dir($moduleDir)) {
            return [];
        }

        $versions = [];
        foreach (glob($moduleDir . DS . '*', GLOB_ONLYDIR) ?: [] as $versionDir) {
            $version = basename($versionDir);
            foreach (glob($versionDir . DS . '*' . DS . 'manifest.json') ?: [] as $manifestFile) {
                $manifest = json_decode((string)file_get_contents($manifestFile), true);
                if (!is_array($manifest) || ($manifest['module_name'] ?? '') !== $moduleName) {
                    continue;
                }
                $versions[$version] = [
                    'version' => $version,
                    'source' => $this->getName(),
                    'checksum' => (string)($manifest['checksum'] ?? ''),
                ];
            }
        }

        uksort($versions, static fn(string $a, string $b): int => version_compare($b, $a));
        return array_values($versions);
    }

    public function stage(string $moduleName, string $version, string $operationId): array
    {
        $versionDir = $this->artifactRoot() . DS . $this->safeSegment($moduleName) . DS . $this->safeSegment($version);
        $candidates = glob($versionDir . DS . '*' . DS . 'manifest.json') ?: [];
        rsort($candidates, SORT_STRING);
        foreach ($candidates as $manifestFile) {
            $manifest = json_decode((string)file_get_contents($manifestFile), true);
            $modulePath = dirname($manifestFile) . DS . 'module';
            if (!is_array($manifest)
                || ($manifest['module_name'] ?? '') !== $moduleName
                || ($manifest['version'] ?? '') !== $version
                || !is_dir($modulePath)
            ) {
                continue;
            }
            $checksum = $this->directoryChecksum($modulePath);
            if (!hash_equals((string)($manifest['checksum'] ?? ''), $checksum)) {
                continue;
            }
            return [
                'success' => true,
                'module_name' => $moduleName,
                'version' => $version,
                'path' => $modulePath,
                'checksum' => $checksum,
                'source' => $this->getName(),
            ];
        }

        return [
            'success' => false,
            'module_name' => $moduleName,
            'version' => $version,
            'source' => $this->getName(),
            'error' => __('本地没有模块 %{1} 版本 %{2} 的不可变快照', [$moduleName, $version]),
        ];
    }

    public function snapshotCurrent(string $moduleName, string $version, string $operationId): array
    {
        try {
            $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
            $source = is_array($moduleInfo) ? (string)($moduleInfo['base_path'] ?? '') : '';
            if ($source === '' || !is_dir($source)) {
                throw new \RuntimeException(__('模块目录不存在: %{1}', $moduleName));
            }
            return $this->importDirectory($moduleName, $version, $operationId, $source, $this->getName());
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'module_name' => $moduleName,
                'version' => $version,
                'source' => $this->getName(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @return array<string, mixed> */
    public function importDirectory(
        string $moduleName,
        string $version,
        string $operationId,
        string $source,
        string $sourceName,
    ): array {
        try {
            $source = rtrim($source, '/\\');
            if (!is_dir($source)) {
                throw new \RuntimeException(__('模块制品目录不存在: %{1}', $source));
            }
            $this->assertModuleIdentity($source, $moduleName, $version);
            $checksum = $this->directoryChecksum($source);
            $targetRoot = $this->artifactRoot()
                . DS . $this->safeSegment($moduleName)
                . DS . $this->safeSegment($version)
                . DS . $checksum;
            $target = $targetRoot . DS . 'module';
            if (!is_dir($target)) {
                $temporary = $targetRoot . '.tmp-' . $this->safeSegment($operationId) . '-' . bin2hex(random_bytes(4));
                $this->recursiveCopy($source, $temporary . DS . 'module');
                $copiedChecksum = $this->directoryChecksum($temporary . DS . 'module');
                if (!hash_equals($checksum, $copiedChecksum)) {
                    $this->recursiveDelete($temporary);
                    throw new \RuntimeException(__('模块代码快照校验失败: %{1}', $moduleName));
                }
                if (!is_dir($targetRoot) && !@rename($temporary, $targetRoot)) {
                    $this->recursiveDelete($temporary);
                    throw new \RuntimeException(__('无法提交模块代码快照: %{1}', $moduleName));
                }
                $manifest = [
                    'module_name' => $moduleName,
                    'version' => $version,
                    'checksum' => $checksum,
                    'operation_id' => $operationId,
                    'source' => $sourceName,
                    'created_at' => date('c'),
                ];
                file_put_contents(
                    $targetRoot . DS . 'manifest.json',
                    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );
            }

            return [
                'success' => true,
                'module_name' => $moduleName,
                'version' => $version,
                'path' => $target,
                'checksum' => $checksum,
                'source' => $sourceName,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'module_name' => $moduleName,
                'version' => $version,
                'source' => $sourceName,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function assertModuleIdentity(string $modulePath, string $moduleName, string $version): void
    {
        $moduleFile = $modulePath . DS . 'etc' . DS . 'module.php';
        if (!is_file($moduleFile)) {
            throw new \RuntimeException(__('模块制品缺少 etc/module.php: %{1}', $moduleName));
        }
        $config = require $moduleFile;
        if (!is_array($config)
            || (string)($config['name'] ?? '') !== $moduleName
            || (string)($config['version'] ?? '') !== $version
        ) {
            throw new \RuntimeException(__('模块制品身份或版本不匹配: %{1} %{2}', [$moduleName, $version]));
        }
    }

    private function artifactRoot(): string
    {
        return BP . 'var' . DS . 'database' . DS . 'module-artifacts';
    }

    private function safeSegment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '-', trim($value));
        if (!is_string($safe) || $safe === '' || str_contains($safe, '..')) {
            throw new \InvalidArgumentException(__('非法制品路径片段'));
        }
        return $safe;
    }

    private function directoryChecksum(string $directory): string
    {
        $directory = rtrim($directory, '/\\');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException(__('模块制品不允许包含符号链接: %{1}', $file->getPathname()));
            }
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        return hash('sha256', (string)json_encode($files, JSON_UNESCAPED_SLASHES));
    }

    private function recursiveCopy(string $source, string $target): void
    {
        if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
            throw new \RuntimeException(__('无法创建制品目录: %{1}', $target));
        }
        $iterator = new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException(__('模块制品不允许包含符号链接: %{1}', $item->getPathname()));
            }
            $destination = $target . DS . $item->getBasename();
            if ($item->isDir()) {
                $this->recursiveCopy($item->getPathname(), $destination);
            } elseif (!copy($item->getPathname(), $destination)) {
                throw new \RuntimeException(__('复制模块制品失败: %{1}', $item->getPathname()));
            }
        }
    }

    private function recursiveDelete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
