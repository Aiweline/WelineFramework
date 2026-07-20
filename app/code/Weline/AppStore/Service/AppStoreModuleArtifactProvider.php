<?php

declare(strict_types=1);

namespace Weline\AppStore\Service;

use Weline\AppStore\Model\AppStoreInstalledModule;
use Weline\Database\Api\ModuleArtifactProviderInterface;
use Weline\Database\Api\ModuleArtifactStore;

final class AppStoreModuleArtifactProvider implements ModuleArtifactProviderInterface
{
    private const INSTALL_RECORD_DIR = BP . 'var' . DS . 'appstore' . DS . 'install-records';
    private const TEMP_DIR = BP . 'var' . DS . 'appstore' . DS . 'temp';

    public function __construct(
        private readonly AppStoreInstalledModule $installedModule,
        private readonly ModuleInstallerService $installer,
        private readonly ModuleArtifactStore $localArtifacts,
    ) {
    }

    public function getName(): string
    {
        return 'appstore';
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function listVersions(string $moduleName): array
    {
        $versions = [];
        $installed = $this->findInstalledModule($moduleName);
        if ($installed !== null) {
            $version = $installed->getVersion();
            $versions[$version] = ['version' => $version, 'source' => $this->getName()];
        }

        foreach ($this->readInstallRecords($moduleName) as $record) {
            foreach (['version', 'previous_version'] as $field) {
                $version = trim((string)($record[$field] ?? ''));
                if ($version !== '') {
                    $versions[$version] = ['version' => $version, 'source' => $this->getName()];
                }
            }
        }
        uksort($versions, static fn(string $a, string $b): int => version_compare($b, $a));
        return array_values($versions);
    }

    public function stage(string $moduleName, string $version, string $operationId): array
    {
        foreach (array_reverse($this->readInstallRecords($moduleName)) as $record) {
            if ((string)($record['previous_version'] ?? '') !== $version) {
                continue;
            }
            $backupDir = trim((string)($record['backup_dir'] ?? ''));
            if ($backupDir !== '' && is_dir($backupDir)) {
                $result = $this->localArtifacts->importDirectory(
                    $moduleName,
                    $version,
                    $operationId,
                    $backupDir,
                    'appstore_backup'
                );
                if (!empty($result['success'])) {
                    return $result;
                }
            }
        }

        $installed = $this->findInstalledModule($moduleName);
        if ($installed === null
            || trim((string)$installed->getLicenseKey()) === ''
            || $installed->getPlatformModuleId() <= 0
        ) {
            return $this->failure($moduleName, $version, __('缺少 AppStore 许可证或平台模块 ID'));
        }

        $extractDir = '';
        $zipPath = '';
        try {
            $domain = trim((string)$installed->getBoundDomain());
            $download = $domain !== ''
                ? $this->installer->downloadForDomain(
                    $domain,
                    (string)$installed->getLicenseKey(),
                    $installed->getPlatformModuleId(),
                    $version
                )
                : $this->installer->download(
                    (string)$installed->getLicenseKey(),
                    $installed->getPlatformModuleId(),
                    $version
                );
            $zipPath = (string)($download['file_path'] ?? '');
            if ($zipPath === '' || !is_file($zipPath) || (string)($download['version'] ?? '') !== $version) {
                throw new \RuntimeException(__('AppStore 未返回指定版本制品'));
            }

            $extractDir = $this->installer->extract($zipPath);
            $moduleInfo = $this->installer->validateStructure($extractDir);
            if ((string)($moduleInfo['name'] ?? '') !== $moduleName) {
                throw new \RuntimeException(__('AppStore 制品模块名不匹配'));
            }
            $moduleDir = trim((string)($moduleInfo['dir'] ?? ''));
            if ($moduleDir === '' || !is_dir($moduleDir)) {
                throw new \RuntimeException(__('AppStore 制品模块目录无效'));
            }

            return $this->localArtifacts->importDirectory(
                $moduleName,
                $version,
                $operationId,
                $moduleDir,
                $this->getName()
            );
        } catch (\Throwable $e) {
            return $this->failure($moduleName, $version, $e->getMessage());
        } finally {
            if ($extractDir !== '' && is_dir($extractDir)) {
                $this->recursiveDelete($extractDir);
            }
            if ($zipPath !== '' && is_file($zipPath)) {
                $this->deleteTemporaryFile($zipPath);
            }
        }
    }

    public function snapshotCurrent(string $moduleName, string $version, string $operationId): array
    {
        return $this->localArtifacts->snapshotCurrent($moduleName, $version, $operationId);
    }

    private function findInstalledModule(string $moduleName): ?AppStoreInstalledModule
    {
        $items = (clone $this->installedModule)->reset()
            ->where(AppStoreInstalledModule::schema_fields_module_name, $moduleName)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $item = $items[0] ?? null;
        return $item instanceof AppStoreInstalledModule ? $item : null;
    }

    /** @return list<array<string, mixed>> */
    private function readInstallRecords(string $moduleName): array
    {
        if (!is_dir(self::INSTALL_RECORD_DIR)) {
            return [];
        }
        $records = [];
        foreach (glob(self::INSTALL_RECORD_DIR . DS . '*.jsonl') ?: [] as $file) {
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                while (($line = fgets($handle)) !== false) {
                    $record = json_decode(trim($line), true);
                    if (is_array($record) && (string)($record['module_name'] ?? '') === $moduleName) {
                        $records[] = $record;
                    }
                }
            } finally {
                fclose($handle);
            }
        }
        usort($records, static fn(array $a, array $b): int => strcmp((string)($a['recorded_at'] ?? ''), (string)($b['recorded_at'] ?? '')));
        return $records;
    }

    private function failure(string $moduleName, string $version, string $error): array
    {
        return [
            'success' => false,
            'module_name' => $moduleName,
            'version' => $version,
            'source' => $this->getName(),
            'error' => $error,
        ];
    }

    private function recursiveDelete(string $path): void
    {
        $root = realpath(self::TEMP_DIR);
        $resolved = realpath($path);
        if ($root === false
            || $resolved === false
            || !is_dir($resolved)
            || !str_starts_with($resolved, rtrim($root, '/\\') . DS)
            || preg_match('/^extract_[A-Za-z0-9_.-]+$/', basename($resolved)) !== 1
        ) {
            throw new \RuntimeException(__('拒绝删除非受管 AppStore 解压目录: %{1}', $path));
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
                continue;
            }
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- the parent is a validated AppStore extract directory and links are not followed.
            @unlink($item->getPathname());
        }
        @rmdir($resolved);
    }

    private function deleteTemporaryFile(string $path): void
    {
        $root = realpath(self::TEMP_DIR);
        $resolved = realpath($path);
        if ($root === false
            || $resolved === false
            || !is_file($resolved)
            || !str_starts_with($resolved, rtrim($root, '/\\') . DS)
            || strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'zip'
        ) {
            throw new \RuntimeException(__('拒绝删除非受管 AppStore 下载文件: %{1}', $path));
        }
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath and extension are restricted to the AppStore temp root.
        if (!@unlink($resolved) && is_file($resolved)) {
            throw new \RuntimeException(__('无法清理 AppStore 下载文件: %{1}', $resolved));
        }
    }
}
