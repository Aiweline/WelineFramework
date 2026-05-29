<?php
declare(strict_types=1);

namespace Weline\AppStore\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Weline\Framework\App\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Compress;
use Weline\AppStore\Model\AppStoreDownloadLog;
use Weline\AppStore\Model\AppStoreAccount;
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

    private const INSTALL_RECORD_DIR = BP . 'var' . DS . 'appstore' . DS . 'install-records';

    private const MARKETPLACE_README = '商城应用.md';

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
        $platformUrl = self::normalizePlatformApiBaseUrl($this->resolvePlatformUrl());
        // Env::get 如果配置项存在但显式为 null，会直接返回 null（不会走 default），因此这里做非空兜底。
        $this->platformApiUrl = $platformUrl;
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
    public function download(string $licenseKey, ?int $moduleId = null, ?string $version = null, ?string $downloadIp = null): array
    {
        return $this->downloadWithDomain('', $licenseKey, $moduleId, $version, $downloadIp);
    }

    public function downloadForDomain(string $domain, string $licenseKey, ?int $moduleId = null, ?string $version = null, ?string $downloadIp = null): array
    {
        return $this->downloadWithDomain($domain, $licenseKey, $moduleId, $version, $downloadIp);
    }

    private function downloadWithDomain(string $domain, string $licenseKey, ?int $moduleId = null, ?string $version = null, ?string $downloadIp = null): array
    {
        // 创建下载日志
        /** @var AppStoreDownloadLog $log */
        $log = ObjectManager::getInstance(AppStoreDownloadLog::class);
        $fallbackModuleName = $moduleId ? 'module-' . $moduleId : 'unknown';
        $log->setLicenseKey($licenseKey);
        $log->setVersion($version ?? 'latest');
        $log->setModuleName($fallbackModuleName);
        $log->setDownloadIp($downloadIp ?? $this->getClientIp());
        $log->setDownloadAt(date('Y-m-d H:i:s'));

        try {
            $downloadDomain = $this->resolveDownloadDomain($domain);
            $downloadMetadata = $this->loadDownloadMetadata($licenseKey, $moduleId, $version, $downloadDomain);
            $payload = $downloadMetadata['payload'];
            $downloadDomain = $downloadMetadata['domain'];

            // 下载模块文件
            $tempDir = $this->getTempDir();
            $moduleName = (string)($payload['module_name'] ?? '');
            $moduleVersion = (string)($payload['version'] ?? '');
            $downloadUrl = $this->resolveDownloadUrl((string)($payload['download_url'] ?? ''));
            if ($moduleName === '' || $moduleVersion === '' || $downloadUrl === '') {
                throw new Exception(__('下载响应数据不完整'));
            }
            $tempFile = $tempDir . DS . $moduleName . '-' . $moduleVersion . '.zip';

            $log->setModuleName($moduleName);
            $log->setVersion($moduleVersion);

            $this->downloadFile($downloadUrl, $tempFile);

            // 验证文件哈希
            $fileHash = hash_file('sha256', $tempFile);
            if (!empty($payload['file_hash']) && $fileHash !== $payload['file_hash']) {
                unlink($tempFile);
                throw new Exception(__('文件校验失败'));
            }

            // 更新日志
            $log->setModuleName($moduleName);
            $log->setVersion($moduleVersion);
            $log->setFilePath($tempFile);
            $log->setFileSize(filesize($tempFile));
            $log->setFileHash($fileHash);
            $log->markAsSuccess($tempFile, filesize($tempFile), $fileHash);
            $log->save();

            return [
                'success' => true,
                'log_id' => $log->getLogId(),
                'module_name' => $moduleName,
                'version' => $moduleVersion,
                'file_path' => $tempFile,
                'file_size' => filesize($tempFile),
                'file_hash' => $fileHash,
                'download_domain' => $downloadDomain,
                'module_info' => is_array($payload['module_info'] ?? null) ? $payload['module_info'] : [],
            ];
        } catch (\Throwable $e) {
            $this->saveFailedDownloadLog($log, $e->getMessage());
            throw new Exception(__('模块下载失败：') . $e->getMessage());
        }
    }

    /**
     * @return array{payload: array<string, mixed>, domain: string}
     */
    private function loadDownloadMetadata(string $licenseKey, ?int $moduleId, ?string $version, string $downloadDomain): array
    {
        try {
            return [
                'payload' => $this->requestDownloadMetadata($licenseKey, $moduleId, $version, $downloadDomain),
                'domain' => $downloadDomain,
            ];
        } catch (\Throwable $e) {
            $licenseDomain = $this->extractBoundLicenseDomain($e);
            if ($licenseDomain === '' || $licenseDomain === $downloadDomain) {
                throw $e;
            }

            return [
                'payload' => $this->requestDownloadMetadata($licenseKey, $moduleId, $version, $licenseDomain),
                'domain' => $licenseDomain,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestDownloadMetadata(string $licenseKey, ?int $moduleId, ?string $version, string $domain): array
    {
        $response = $this->httpClient->post($this->platformApiUrl . '/api/v1/platform/module/download', [
            'form_params' => [
                'license_key' => $licenseKey,
                'module_id' => $moduleId,
                'version' => $version,
                'domain' => $domain,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        if (!is_array($data)) {
            throw new Exception(__('下载响应数据不完整'));
        }

        if (!($data['success'] ?? false)) {
            throw new Exception((string)($data['message'] ?? __('下载失败')));
        }

        return $this->normalizeApiPayload($data);
    }

    private function extractBoundLicenseDomain(\Throwable $exception): string
    {
        $messages = [$exception->getMessage()];
        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $body = (string)$exception->getResponse()->getBody();
            $data = json_decode($body, true);
            if (is_array($data)) {
                foreach (['message', 'error'] as $key) {
                    if (!empty($data[$key]) && is_string($data[$key])) {
                        $messages[] = $data[$key];
                    }
                }
            } elseif ($body !== '') {
                $messages[] = $body;
            }
        }

        foreach ($messages as $message) {
            if (preg_match('/bound license domain:\s*([A-Za-z0-9.\-:\[\]]+)/i', $message, $matches)) {
                return $this->normalizeDomain($matches[1]);
            }
        }

        return '';
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
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!file_exists($zipPath)) {
            throw new Exception(__('模块文件不存在'));
        }

        // 解压到临时目录
        $tempDir = $this->extract($zipPath);

        try {
            // 验证模块结构
            $this->normalizePhpFiles($tempDir);
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
            $wasMaintenanceMode = (bool)Env::get('system.maintenance', false);
            $installResult = $this->executeSetupUpgrade($moduleName);
            if (!$wasMaintenanceMode) {
                $this->restoreMaintenanceMode(false);
            }

            if (!$installResult['success']) {
                // 安装失败，恢复备份
                if ($backupDir) {
                    $this->restoreBackup($backupDir, $targetDir);
                } elseif (is_dir($targetDir)) {
                    // 新装模块无备份时，需要删除已落盘目录，避免半安装状态。
                    $this->recursiveDelete($targetDir);
                }
                throw new Exception(__('模块安装失败：') . $installResult['message']);
            }

            // 更新已安装模块记录
            $commandUpgradeResult = $this->executeCommandUpgrade();
            if (!$commandUpgradeResult['success']) {
                if ($backupDir) {
                    $this->restoreBackup($backupDir, $targetDir);
                } elseif (is_dir($targetDir)) {
                    $this->recursiveDelete($targetDir);
                }
                throw new Exception(__('模块安装失败：') . $commandUpgradeResult['message']);
            }

            $action = (string)($options['action'] ?? 'install');
            if (!in_array($action, ['install', 'upgrade'], true)) {
                $action = 'install';
            }

            $this->updateInstalledModule($moduleName, $moduleInfo, $options);
            $readmePath = $this->writeMarketplaceReadme($targetDir, $moduleName, $moduleInfo, $options);
            $installRecordPath = $this->appendInstallRecord([
                'action' => $action,
                'module_name' => $moduleName,
                'version' => $moduleInfo['version'],
                'previous_version' => (string)($options['previous_version'] ?? ''),
                'display_name' => (string)($options['display_name'] ?? ($moduleInfo['display_name'] ?? $moduleName)),
                'platform_module_id' => (int)($options['platform_module_id'] ?? 0),
                'license_key_masked' => $this->maskSecret((string)($options['license_key'] ?? '')),
                'target_dir' => $targetDir,
                'backup_dir' => $backupDir ?? '',
                'frontend_path' => $this->getInstalledFrontendPath($targetDir),
                'readme_path' => $readmePath,
                'download_log_id' => (int)($options['download_log_id'] ?? 0),
                'download_file_hash' => (string)($options['download_file_hash'] ?? ''),
                'download_file_size' => (int)($options['download_file_size'] ?? 0),
                'bound_domain' => (string)($options['bound_domain'] ?? ''),
                'platform_url' => $this->platformApiUrl,
            ]);

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
                'previous_version' => (string)($options['previous_version'] ?? ''),
                'target_dir' => $targetDir,
                'backup_dir' => $backupDir ?? '',
                'frontend_path' => $this->getInstalledFrontendPath($targetDir),
                'marketplace_readme' => $readmePath,
                'install_record_path' => $installRecordPath,
                'install_output' => trim(($installResult['output'] ?? '') . "\n" . ($commandUpgradeResult['output'] ?? '')),
            ];
        } catch (\Throwable $e) {
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
        $this->normalizeRegisterFile($registerFile);
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
    private function normalizeRegisterFile(string $registerFile): void
    {
        $content = file_get_contents($registerFile);
        if (!is_string($content) || $content === '') {
            return;
        }

        $normalized = $content;
        if (str_starts_with($normalized, "\xEF\xBB\xBF")) {
            $normalized = substr($normalized, 3);
        }

        $openTagPosition = strpos($normalized, '<?php');
        if ($openTagPosition !== false && $openTagPosition > 0 && trim(substr($normalized, 0, $openTagPosition)) === '') {
            $normalized = substr($normalized, $openTagPosition);
        }

        if ($normalized !== $content) {
            file_put_contents($registerFile, $normalized);
        }
    }

    private function normalizePhpFiles(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $this->normalizeRegisterFile($file->getPathname());
        }
    }

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
     * 执行模块注册和路由更新
     *
     * @param string $moduleName 模块名
     * @return array 执行结果
     */
    private function executeSetupUpgrade(string $moduleName): array
    {
        $targetDir = $this->getModuleTargetDir($moduleName);
        $registerFile = $targetDir . DS . 'register.php';
        if (!is_file($registerFile)) {
            return [
                'success' => false,
                'output' => '',
                'return_code' => 1,
                'message' => __('模块 register.php 不存在'),
            ];
        }

        $this->normalizeRegisterFile($registerFile);

        $bootstrapFile = BP . 'app' . DS . 'bootstrap.php';
        $code = 'require ' . var_export($bootstrapFile, true) . ';'
            . 'require ' . var_export($registerFile, true) . ';'
            . '$moduleName = ' . var_export($moduleName, true) . ';'
            . '$env = \Weline\Framework\App\Env::getInstance();'
            . '$modules = $env->getModuleList(true);'
            . 'if (isset($modules[$moduleName]) && is_array($modules[$moduleName])) {'
            . 'unset($modules[$moduleName]["installing"], $modules[$moduleName]["upgrading"]);'
            . '$previousDeferRegistryUpdate = \Weline\Framework\Module\Handle::isDeferRegistryUpdate();'
            . '\Weline\Framework\Module\Handle::setDeferRegistryUpdate(true);'
            . 'try {'
            . '\Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Module\Helper\Data::class)->updateModules($modules);'
            . '} finally {'
            . '\Weline\Framework\Module\Handle::setDeferRegistryUpdate($previousDeferRegistryUpdate);'
            . '}'
            . '$env->getModuleList(true);'
            . '}'
            . '\Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Registry\Service\RegistryUpdateService::class)->updateAllRegistries(true, false, true);'
            . '$routeUpdateService = \Weline\Framework\Manager\ObjectManager::make(\Weline\Framework\Router\Service\RouteUpdateService::class, ['
            . '"moduleHandle" => \Weline\Framework\Manager\ObjectManager::make(\Weline\Framework\Module\Handle::class),'
            . ']);'
            . '$routeUpdateService->updateRoutes([$moduleName]);'
            . 'exit(0);';

        $scriptPath = $this->getTempDir() . DS . 'install_' . preg_replace('/[^A-Za-z0-9_]/', '_', $moduleName) . '_' . uniqid() . '.php';
        if (file_put_contents($scriptPath, "<?php\n" . $code) === false) {
            return [
                'success' => false,
                'output' => '',
                'return_code' => 1,
                'message' => __('无法创建模块安装脚本'),
            ];
        }

        $command = PHP_BINARY . ' ' . escapeshellarg($scriptPath);
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        if (is_file($scriptPath)) {
            unlink($scriptPath);
        }

        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'return_code' => $returnCode,
            'message' => $returnCode === 0 ? '' : implode("\n", $output),
        ];
    }

    private function executeCommandUpgrade(): array
    {
        $output = [];
        $returnCode = 0;
        exec($this->buildCommandUpgradeCommand() . ' 2>&1', $output, $returnCode);

        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'return_code' => $returnCode,
            'message' => $returnCode === 0 ? '' : implode("\n", $output),
        ];
    }

    protected function buildCommandUpgradeCommand(): string
    {
        return implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(BP . 'bin' . DS . 'w'),
            'command:upgrade',
        ]);
    }

    private function restoreMaintenanceMode(bool $enabled): void
    {
        try {
            Env::getInstance()->setConfig('system.maintenance', $enabled);
        } catch (\Throwable) {
        }
    }

    private function getInstalledFrontendPath(string $targetDir): string
    {
        $envFile = $targetDir . DS . 'etc' . DS . 'env.php';
        if (!is_file($envFile)) {
            return '';
        }

        $env = require $envFile;
        if (!is_array($env)) {
            return '';
        }

        $router = trim((string)($env['router'] ?? ''), '/');
        return $router !== '' ? '/' . $router : '';
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
        $installedModule = $installedModule->clear()
            ->where(AppStoreInstalledModule::schema_fields_module_name, $moduleName)
            ->find()
            ->fetch();

        $installedModule->setModuleName($moduleName);
        $installedModule->setVersion($moduleInfo['version']);
        $installedModule->setDisplayName($options['display_name'] ?? ($moduleInfo['display_name'] ?? $moduleName));
        $installedModule->setDescription($options['description'] ?? ($moduleInfo['description'] ?? null));
        $installedModule->setLicenseKey($options['license_key'] ?? null);
        if (!empty($options['bound_domain'])) {
            $installedModule->setBoundDomain((string)$options['bound_domain']);
        }
        $installedModule->setPlatformModuleId($options['platform_module_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        if (!$installedModule->getInstalledAt()) {
            $installedModule->setInstalledAt($now);
        }
        $installedModule->setData(AppStoreInstalledModule::schema_fields_updated_at, $now);
        $installedModule->save();
    }

    private function writeMarketplaceReadme(string $targetDir, string $moduleName, array $moduleInfo, array $options): string
    {
        $readmePath = $targetDir . DS . self::MARKETPLACE_README;
        $displayName = (string)($options['display_name'] ?? ($moduleInfo['display_name'] ?? $moduleName));
        $frontendPath = $this->getInstalledFrontendPath($targetDir);
        $lines = [
            '# 商城应用',
            '',
            '- 模块名称：' . $moduleName,
            '- 显示名称：' . $displayName,
            '- 版本：' . (string)($moduleInfo['version'] ?? ''),
            '- 安装来源：WelineFramework 官方应用商城',
            '- 平台模块 ID：' . (string)($options['platform_module_id'] ?? 0),
            '- 安装时间：' . date('Y-m-d H:i:s'),
            '- 许可证：' . $this->maskSecret((string)($options['license_key'] ?? '')),
            '- 终端域名：' . $this->getCurrentDomain(),
            '- 功能入口：' . ($frontendPath !== '' ? $frontendPath : '未声明'),
            '',
            '## 卸载说明',
            '',
            '请使用系统模块卸载流程，例如：',
            '',
            '```bash',
            'php bin/w module:remove ' . $moduleName,
            '```',
            '',
            '系统卸载会触发框架的 UninstallService 与 ModuleManager 数据备份事件。App 商城只记录安装来源，不负责导出或下载 SQL。',
            '',
        ];

        if (file_put_contents($readmePath, implode(PHP_EOL, $lines)) === false) {
            throw new Exception(__('无法写入商城应用说明文件：') . $readmePath);
        }

        return $readmePath;
    }

    private function appendInstallRecord(array $record): string
    {
        $recordDir = $this->getInstallRecordDir();
        if (!is_dir($recordDir)) {
            mkdir($recordDir, 0755, true);
        }

        $path = $recordDir . DS . date('Y-m') . '.jsonl';
        $payload = array_merge([
            'action' => 'install',
            'recorded_at' => date('c'),
        ], $record);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_string($json) || file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new Exception(__('无法写入 AppStore 安装记录：') . $path);
        }

        return $path;
    }

    protected function getInstallRecordDir(): string
    {
        return self::INSTALL_RECORD_DIR;
    }

    private function resolveDownloadUrl(string $downloadUrl): string
    {
        $downloadUrl = trim($downloadUrl);
        if ($downloadUrl === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $downloadUrl)) {
            return $downloadUrl;
        }

        $platform = parse_url($this->platformApiUrl);
        if (!is_array($platform) || empty($platform['scheme']) || empty($platform['host'])) {
            return $downloadUrl;
        }

        $origin = $platform['scheme'] . '://' . $platform['host'] . (isset($platform['port']) ? ':' . $platform['port'] : '');
        if (str_starts_with($downloadUrl, '/')) {
            $platformPath = rtrim((string)($platform['path'] ?? ''), '/');
            if ($platformPath !== '' && !str_starts_with($downloadUrl, $platformPath . '/')) {
                return $origin . $platformPath . $downloadUrl;
            }
            return $origin . $downloadUrl;
        }

        return rtrim($this->platformApiUrl, '/') . '/' . ltrim($downloadUrl, '/');
    }

    private function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4) . str_repeat('*', min(12, max(4, $length - 8))) . substr($value, -4);
    }

    /**
     * 检查 WLS 是否运行
     *
     * @return bool
     */
    private function isWlsRunning(): bool
    {
        $instanceName = $this->getCurrentWlsInstanceName();
        if ($instanceName !== '') {
            $pid = $this->readWlsInstanceMasterPid($instanceName);
            return $pid > 0 && $this->isProcessRunning($pid);
        }

        $pidFile = BP . 'var' . DS . 'wls' . DS . 'master.pid';
        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = (int)file_get_contents($pidFile);
        return $pid > 0 && $this->isProcessRunning($pid);
    }

    private function readWlsInstanceMasterPid(string $instanceName): int
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $instanceName)) {
            return 0;
        }

        $path = BP . 'var' . DS . 'server' . DS . 'instances' . DS . $instanceName . '.json';
        if (!is_file($path)) {
            return 0;
        }

        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return 0;
        }

        return (int)($data['master_pid'] ?? $data['pid'] ?? 0);
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $output = [];
            $returnCode = 1;
            exec('tasklist /FI "PID eq ' . $pid . '" 2>NUL', $output, $returnCode);
            if ($returnCode === 0) {
                return str_contains(implode("\n", $output), (string)$pid);
            }
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return file_exists("/proc/{$pid}");
    }

    /**
     * 标准化平台 API 响应负载。
     *
     * @param array $response
     * @return array
     */
    private function normalizeApiPayload(array $response): array
    {
        $payload = $response['data'] ?? $response;
        if (!is_array($payload)) {
            return [];
        }
        return $payload;
    }

    /**
     * 触发 WLS 热重载
     */
    private function triggerWlsReload(): void
    {
        exec($this->buildWlsReloadCommand() . ' 2>&1');
    }

    protected function buildWlsReloadCommand(): string
    {
        $parts = [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(BP . 'bin' . DS . 'w'),
            'server:reload',
        ];

        $instanceName = $this->getCurrentWlsInstanceName();
        if ($instanceName !== '') {
            $parts[] = escapeshellarg($instanceName);
        }

        $parts[] = '-n';

        return implode(' ', $parts);
    }

    private function getCurrentWlsInstanceName(): string
    {
        $candidates = [
            getenv('WLS_INSTANCE') ?: '',
            $_SERVER['WLS_INSTANCE'] ?? '',
            $_ENV['WLS_INSTANCE'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
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
        $requestDomain = $this->getRequestDomain();
        if ($requestDomain !== '') {
            return $requestDomain;
        }

        $boundDomain = $this->getBoundAccountDomain();
        if ($boundDomain !== '') {
            return $boundDomain;
        }

        $serverDomain = $this->normalizeDomain((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($serverDomain !== '') {
            return $serverDomain;
        }

        $envDomain = $this->normalizeDomain((string)\w_env('server.http_host', ''));
        return $envDomain !== '' ? $envDomain : 'localhost';
    }

    private function resolveDownloadDomain(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);
        return $domain !== '' ? $domain : $this->getCurrentDomain();
    }

    private function getRequestDomain(): string
    {
        try {
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            if (!\is_object($request) || !\method_exists($request, 'getServer')) {
                return '';
            }

            return $this->normalizeDomain((string)$request->getServer('HTTP_HOST'));
        } catch (\Throwable) {
            return '';
        }
    }

    private function getBoundAccountDomain(): string
    {
        try {
            /** @var AppStoreAccount $account */
            $account = ObjectManager::getInstance(AppStoreAccount::class);
            $account = $account->reset()
                ->where(AppStoreAccount::schema_fields_status, AppStoreAccount::STATUS_ACTIVE)
                ->limit(1)
                ->find()
                ->fetch();

            return $this->normalizeDomain((string)($account->getBoundDomain() ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            $parts = parse_url($domain);
            if (!\is_array($parts) || empty($parts['host'])) {
                return '';
            }

            return (string)$parts['host'] . (!empty($parts['port']) ? ':' . (string)$parts['port'] : '');
        }

        if (str_contains($domain, '/')) {
            $domain = (string)strstr($domain, '/', true);
        }

        return trim($domain);
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
            $ip = (string)\w_env('server.' . strtolower($header), '');
            if ($ip !== '') {
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

    private function saveFailedDownloadLog(AppStoreDownloadLog $log, string $reason): void
    {
        try {
            $log->markAsFailed($reason);
            $log->save();
        } catch (\Throwable $logException) {
            throw new Exception(
                __('Module download failed: ') . $reason . '; ' . __('failed to save failure log: ') . $logException->getMessage()
            );
        }
    }

    private function resolvePlatformUrl(): string
    {
        $envPlatformUrl = getenv('WELINE_APPSTORE_PLATFORM_URL');
        if (is_string($envPlatformUrl) && trim($envPlatformUrl) !== '') {
            return rtrim(trim($envPlatformUrl), '/');
        }

        $platformUrl = Env::get('appstore.platform_url');
        return (is_string($platformUrl) && $platformUrl !== '') ? rtrim($platformUrl, '/') : self::DEFAULT_PLATFORM_URL;
    }

    private static function normalizePlatformApiBaseUrl(string $platformUrl): string
    {
        $platformUrl = rtrim(trim($platformUrl), '/');
        if ($platformUrl === '') {
            return self::DEFAULT_PLATFORM_URL;
        }

        return preg_replace('#/([A-Za-z]{3})/([a-z]{2}(?:_[A-Za-z0-9]+){1,2})$#', '', $platformUrl) ?: $platformUrl;
    }
}
