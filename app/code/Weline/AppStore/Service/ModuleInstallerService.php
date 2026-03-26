<?php
declare(strict_types=1);

namespace Weline\AppStore\Service;

use GuzzleHttp\Client;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Compress;
use Weline\AppStore\Model\AppStoreDownloadLog;
use Weline\AppStore\Model\AppStoreInstalledModule;

/**
 * 模块安装服务
 *
 * 负责模块的下载、解压、验证、安装和 WLS 热重载
 */
class ModuleInstallerService
{
    /**
     * 平台 API 默认基础 URL（当配置存在但值为 null/空时兜底）
     */
    private const DEFAULT_PLATFORM_URL = 'https://app.aiweline.com';

    /**
     * 临时目录
     */
    private const TEMP_DIR = BP . 'var' . DS . 'appstore' . DS . 'temp';

    /**
     * 模块目标目录
     */
    private const MODULE_DIR = APP_CODE_PATH;

    /**
     * 平台 API 基础 URL
     */
    private string $platformApiUrl;

    /**
     * HTTP 客户端
     */
    private Client $httpClient;

    /**
     * 是否启用 WLS 热重载
     */
    private bool $enableWlsReload = true;

    public function __construct()
    {
        $platformUrl = Env::get('appstore.platform_url', self::DEFAULT_PLATFORM_URL);
        // Env::get 如果配置项存在但显式为 null，会直接返回 null（不会走 default），因此这里做非空兜底。
        $this->platformApiUrl = (is_string($platformUrl) && $platformUrl !== '') ? $platformUrl : self::DEFAULT_PLATFORM_URL;
        $this->httpClient = new Client([
            'timeout' => 300,
            'verify' => true,
        ]);
    }

    /**
     * 从平台下载模块
     *
     * @param string $licenseKey 许可证密钥
     * @param string|null $version 指定版本（null 表示最新版本）
     * @param string|null $downloadIp 下载 IP
     * @return array 下载结果
     * @throws Exception
     */
    public function download(string $licenseKey, ?string $version = null, ?string $downloadIp = null): array
    {
        // 创建下载日志
        /** @var AppStoreDownloadLog $log */
        $log = ObjectManager::getInstance(AppStoreDownloadLog::class);
        $log->setLicenseKey($licenseKey);
        $log->setVersion($version ?? 'latest');
        $log->setDownloadIp($downloadIp ?? $this->getClientIp());
        $log->setDownloadAt(date('Y-m-d H:i:s'));
        $log->save();

        try {
            // 调用平台 API 获取下载链接
            $response = $this->httpClient->post($this->platformApiUrl . '/api/v1/module/download', [
                'json' => [
                    'license_key' => $licenseKey,
                    'version' => $version,
                    'domain' => $this->getCurrentDomain(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!$data['success']) {
                throw new Exception($data['message'] ?? __('下载失败'));
            }

            // 下载模块文件
            $tempDir = $this->getTempDir();
            $tempFile = $tempDir . DS . $data['module_name'] . '-' . $data['version'] . '.zip';

            $this->downloadFile($data['download_url'], $tempFile);

            // 验证文件哈希
            $fileHash = hash_file('sha256', $tempFile);
            if ($data['file_hash'] && $fileHash !== $data['file_hash']) {
                unlink($tempFile);
                throw new Exception(__('文件校验失败'));
            }

            // 更新日志
            $log->setModuleName($data['module_name']);
            $log->setVersion($data['version']);
            $log->setFilePath($tempFile);
            $log->setFileSize(filesize($tempFile));
            $log->setFileHash($fileHash);
            $log->markAsSuccess($tempFile, filesize($tempFile), $fileHash);
            $log->save();

            return [
                'success' => true,
                'log_id' => $log->getLogId(),
                'module_name' => $data['module_name'],
                'version' => $data['version'],
                'file_path' => $tempFile,
                'file_size' => filesize($tempFile),
                'file_hash' => $fileHash,
                'module_info' => $data['module_info'] ?? [],
            ];
        } catch (\Exception $e) {
            $log->markAsFailed($e->getMessage());
            $log->save();
            throw new Exception(__('模块下载失败：') . $e->getMessage());
        }
    }

    /**
     * 安装模块
     *
     * @param string $zipPath 模块压缩包路径
     * @param array $options 安装选项
     * @return array 安装结果
     * @throws Exception
     */
    public function install(string $zipPath, array $options = []): array
    {
        if (!file_exists($zipPath)) {
            throw new Exception(__('模块文件不存在'));
        }

        // 解压到临时目录
        $tempDir = $this->extract($zipPath);

        try {
            // 验证模块结构
            $moduleInfo = $this->validateStructure($tempDir);

            // 检查依赖
            $dependencyCheck = $this->checkDependencies($moduleInfo['dependencies'] ?? []);
            if (!$dependencyCheck['satisfied']) {
                throw new Exception(__('模块依赖不满足：') . implode(', ', $dependencyCheck['missing']));
            }

            // 检查是否已安装
            $moduleName = $moduleInfo['name'];
            $targetDir = $this->getModuleTargetDir($moduleName);

            // 备份旧版本（如果存在）
            $backupDir = null;
            if (is_dir($targetDir)) {
                $backupDir = $this->backupModule($targetDir);
            }

            // 移动模块到目标目录
            $this->moveModule($tempDir, $targetDir);

            // 执行安装命令
            $installResult = $this->executeSetupUpgrade($moduleName);

            if (!$installResult['success']) {
                // 安装失败，恢复备份
                if ($backupDir) {
                    $this->restoreBackup($backupDir, $targetDir);
                }
                throw new Exception(__('模块安装失败：') . $installResult['message']);
            }

            // 更新已安装模块记录
            $this->updateInstalledModule($moduleName, $moduleInfo, $options);

            // 触发 WLS 热重载
            if ($this->enableWlsReload && $this->isWlsRunning()) {
                $this->triggerWlsReload();
            }

            // 清理临时文件
            $this->cleanup($tempDir);
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }

            return [
                'success' => true,
                'module_name' => $moduleName,
                'version' => $moduleInfo['version'],
                'target_dir' => $targetDir,
                'install_output' => $installResult['output'] ?? '',
            ];
        } catch (\Exception $e) {
            // 清理临时文件
            if (isset($tempDir) && is_dir($tempDir)) {
                $this->cleanup($tempDir);
            }
            throw new Exception(__('模块安装失败：') . $e->getMessage());
        }
    }

    /**
     * 解压模块压缩包
     *
     * @param string $zipPath 压缩包路径
     * @return string 解压后的临时目录
     * @throws Exception
     */
    public function extract(string $zipPath): string
    {
        $tempDir = $this->getTempDir() . DS . 'extract_' . uniqid();

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        /** @var Compress $compress */
        $compress = ObjectManager::getInstance(Compress::class);
        $compress->deCompression($zipPath, $tempDir);

        return $tempDir;
    }

    /**
     * 验证模块结构
     *
     * @param string $moduleDir 模块目录
     * @return array 模块信息
     * @throws Exception
     */
    public function validateStructure(string $moduleDir): array
    {
        // 查找 register.php
        $registerFile = $this->findRegisterFile($moduleDir);
        if (!$registerFile) {
            throw new Exception(__('模块结构无效：找不到 register.php'));
        }

        // 解析 register.php 获取模块信息
        $moduleInfo = $this->parseRegisterFile($registerFile);
        if (!$moduleInfo) {
            throw new Exception(__('模块结构无效：无法解析 register.php'));
        }

        // 验证必要字段
        if (empty($moduleInfo['name'])) {
            throw new Exception(__('模块结构无效：缺少模块名'));
        }

        // 安全验证：模块名必须是 Vendor_Module 格式
        if (!$this->isValidModuleName($moduleInfo['name'])) {
            throw new Exception(__('模块名格式无效，必须是 Vendor_Module 格式'));
        }

        return $moduleInfo;
    }

    /**
     * 验证模块名格式是否合法
     *
     * @param string $moduleName 模块名
     * @return bool
     */
    private function isValidModuleName(string $moduleName): bool
    {
        // 必须是 Vendor_Module 格式
        // Vendor 和 Module 只能包含字母、数字
        // 防止路径遍历攻击
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*$/', $moduleName)) {
            return false;
        }

        // 额外检查：防止特殊字符和路径遍历
        if (strpos($moduleName, '..') !== false ||
            strpos($moduleName, '/') !== false ||
            strpos($moduleName, '\\') !== false ||
            strpos($moduleName, "\0") !== false) {
            return false;
        }

        return true;
    }

    /**
     * 查找 register.php 文件
     *
     * @param string $dir 目录
     * @return string|null register.php 文件路径
     */
    private function findRegisterFile(string $dir): ?string
    {
        // 检查根目录
        $registerFile = $dir . DS . 'register.php';
        if (file_exists($registerFile)) {
            return $registerFile;
        }

        // 检查一级子目录
        $dirs = glob($dir . DS . '*', GLOB_ONLYDIR);
        foreach ($dirs as $subDir) {
            $registerFile = $subDir . DS . 'register.php';
            if (file_exists($registerFile)) {
                return $registerFile;
            }
        }

        return null;
    }

    /**
     * 解析 register.php 文件
     *
     * @param string $registerFile register.php 文件路径
     * @return array|null 模块信息
     */
    private function parseRegisterFile(string $registerFile): ?array
    {
        $content = file_get_contents($registerFile);

        // 提取模块名
        if (preg_match("/Register::register\s*\(\s*Register::MODULE\s*,\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
            $name = $matches[1];
        } else {
            return null;
        }

        // 提取版本
        $version = '1.0.0';
        if (preg_match("/Register::register\s*\([^)]*['\"]([\d.]+)['\"][^)]*\)/s", $content, $matches)) {
            $version = $matches[1];
        }

        // 提取依赖
        $dependencies = [];
        if (preg_match("/dependencies\s*:\s*\[([^\]]+)\]/s", $content, $matches)) {
            preg_match_all("/['\"]([^'\"]+)['\"]/", $matches[1], $depMatches);
            $dependencies = $depMatches[1] ?? [];
        }

        // 从目录结构推断 Vendor/Module
        $dir = dirname($registerFile);
        $relativePath = str_replace($this->getTempDir(), '', $dir);
        $parts = array_filter(explode(DS, trim($relativePath, DS)));

        return [
            'name' => $name,
            'version' => $version,
            'dependencies' => $dependencies,
            'path' => $registerFile,
            'dir' => dirname($registerFile),
        ];
    }

    /**
     * 检查模块依赖
     *
     * @param array $dependencies 依赖列表
     * @return array 检查结果
     */
    public function checkDependencies(array $dependencies): array
    {
        $missing = [];
        $installed = $this->getInstalledModules();

        foreach ($dependencies as $dependency) {
            if (!isset($installed[$dependency])) {
                $missing[] = $dependency;
            }
        }

        return [
            'satisfied' => empty($missing),
            'missing' => $missing,
            'installed' => $installed,
        ];
    }

    /**
     * 获取已安装模块列表
     *
     * @return array
     */
    private function getInstalledModules(): array
    {
        $modules = [];
        $registers = glob(APP_CODE_PATH . '*' . DS . '*' . DS . 'register.php');
        foreach ($registers as $register) {
            $content = file_get_contents($register);
            if (preg_match("/Register::register\s*\(\s*Register::MODULE\s*,\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                $modules[$matches[1]] = true;
            }
        }
        return $modules;
    }

    /**
     * 获取模块目标目录
     *
     * @param string $moduleName 模块名 (Vendor_Module)
     * @return string
     */
    private function getModuleTargetDir(string $moduleName): string
    {
        return self::MODULE_DIR . str_replace('_', DS, $moduleName);
    }

    /**
     * 备份模块
     *
     * @param string $moduleDir 模块目录
     * @return string 备份目录
     */
    private function backupModule(string $moduleDir): string
    {
        $backupDir = BP . 'var' . DS . 'appstore' . DS . 'backup' . DS . basename($moduleDir) . '_' . date('YmdHis');
        $this->recursiveCopy($moduleDir, $backupDir);
        return $backupDir;
    }

    /**
     * 恢复备份
     *
     * @param string $backupDir 备份目录
     * @param string $targetDir 目标目录
     */
    private function restoreBackup(string $backupDir, string $targetDir): void
    {
        if (is_dir($targetDir)) {
            $this->recursiveDelete($targetDir);
        }
        $this->recursiveCopy($backupDir, $targetDir);
    }

    /**
     * 移动模块到目标目录
     *
     * @param string $sourceDir 源目录
     * @param string $targetDir 目标目录
     */
    private function moveModule(string $sourceDir, string $targetDir): void
    {
        // 找到模块实际目录
        $registerFile = $this->findRegisterFile($sourceDir);
        $moduleSourceDir = dirname($registerFile);

        if (is_dir($targetDir)) {
            $this->recursiveDelete($targetDir);
        }

        // 确保目标目录的父目录存在
        $parentDir = dirname($targetDir);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $this->recursiveCopy($moduleSourceDir, $targetDir);
    }

    /**
     * 执行 setup:upgrade 命令
     *
     * @param string $moduleName 模块名
     * @return array 执行结果
     */
    private function executeSetupUpgrade(string $moduleName): array
    {
        $command = PHP_BINARY . ' ' . BP . 'bin' . DS . 'w setup:upgrade --module=' . escapeshellarg($moduleName);

        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);

        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'return_code' => $returnCode,
            'message' => $returnCode === 0 ? '' : implode("\n", $output),
        ];
    }

    /**
     * 更新已安装模块记录
     *
     * @param string $moduleName 模块名
     * @param array $moduleInfo 模块信息
     * @param array $options 选项
     */
    private function updateInstalledModule(string $moduleName, array $moduleInfo, array $options): void
    {
        /** @var AppStoreInstalledModule $installedModule */
        $installedModule = ObjectManager::getInstance(AppStoreInstalledModule::class);
        $installedModule->load($moduleName, AppStoreInstalledModule::schema_fields_module_name);

        $installedModule->setModuleName($moduleName);
        $installedModule->setVersion($moduleInfo['version']);
        $installedModule->setDisplayName($moduleInfo['display_name'] ?? $moduleName);
        $installedModule->setDescription($moduleInfo['description'] ?? null);
        $installedModule->setLicenseKey($options['license_key'] ?? null);
        $installedModule->setPlatformModuleId($options['platform_module_id'] ?? 0);
        $installedModule->setInstalledAt(date('Y-m-d H:i:s'));
        $installedModule->save();
    }

    /**
     * 检查 WLS 是否运行
     *
     * @return bool
     */
    private function isWlsRunning(): bool
    {
        $pidFile = BP . 'var' . DS . 'wls' . DS . 'master.pid';
        if (file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            return $pid > 0 && file_exists("/proc/{$pid}");
        }
        return false;
    }

    /**
     * 触发 WLS 热重载
     */
    private function triggerWlsReload(): void
    {
        $command = PHP_BINARY . ' ' . BP . 'bin' . DS . 'w server:reload -n 2>&1 &';
        exec($command);
    }

    /**
     * 下载文件
     *
     * @param string $url 下载 URL
     * @param string $targetPath 目标路径
     * @throws Exception
     */
    private function downloadFile(string $url, string $targetPath): void
    {
        $response = $this->httpClient->get($url, ['sink' => $targetPath]);

        if ($response->getStatusCode() !== 200) {
            throw new Exception(__('下载文件失败，HTTP 状态码：') . $response->getStatusCode());
        }
    }

    /**
     * 获取临时目录
     *
     * @return string
     */
    private function getTempDir(): string
    {
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0755, true);
        }
        return self::TEMP_DIR;
    }

    /**
     * 清理临时目录
     *
     * @param string $dir 目录
     */
    private function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            $this->recursiveDelete($dir);
        }
    }

    /**
     * 递归复制目录
     *
     * @param string $source 源目录
     * @param string $target 目标目录
     */
    private function recursiveCopy(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $items = scandir($source);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $sourcePath = $source . DS . $item;
            $targetPath = $target . DS . $item;

            if (is_dir($sourcePath)) {
                $this->recursiveCopy($sourcePath, $targetPath);
            } else {
                copy($sourcePath, $targetPath);
            }
        }
    }

    /**
     * 递归删除目录
     *
     * @param string $dir 目录
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DS . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    /**
     * 获取当前域名
     *
     * @return string
     */
    private function getCurrentDomain(): string
    {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    /**
     * 获取客户端 IP
     *
     * @return string
     */
    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
            }
        }

        return '0.0.0.0';
    }

    /**
     * 设置是否启用 WLS 热重载
     *
     * @param bool $enable
     */
    public function setEnableWlsReload(bool $enable): void
    {
        $this->enableWlsReload = $enable;
    }
}
