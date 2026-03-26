<?php

declare(strict_types=1);

final class OpenSearchEnvInstaller
{
    private const DEFAULT_VERSION = '3.5.0';
    private const DEFAULT_ENGINE = 'opensearch';
    private const DEFAULT_SCOPE = 'default';
    private const DEFAULT_INSTALL_DIR = 'extend/server/opensearch';
    private const DEFAULT_HOST = 'http://127.0.0.1';
    private const DEFAULT_PORT = 9200;
    private const DEFAULT_INDEX = 'products';
    private const DEFAULT_TIMEOUT = 5;
    private const DEFAULT_CLUSTER_NAME = 'weline-search';
    private const DEFAULT_NODE_NAME = 'weline-search-node';
    private const DEFAULT_BIND_HOST = '127.0.0.1';
    private const MIN_FREE_DOWNLOAD_BYTES = 1610612736;
    private const MIN_FREE_EXTRACT_BYTES = 2147483648;
    private const MIN_FREE_INSTALL_BYTES = 2147483648;
    private const VERSION_MARKER = '.opensearch-version';

    private string $scriptDir;
    private string $projectRoot;
    /**
     * @var array<string, string>
     */
    private array $env = [];

    public function __construct(string $scriptDir)
    {
        $this->scriptDir = $scriptDir;
        $this->projectRoot = $this->detectProjectRoot($scriptDir);
        $this->env = $this->loadWelineEnv();
    }

    public function run(string $action): int
    {
        try {
            return match ($action) {
                'check' => $this->check(),
                'install' => $this->install(),
                default => $this->fail('不支持的动作：' . $action),
            };
        } catch (Throwable $throwable) {
            return $this->fail($throwable->getMessage());
        }
    }

    private function check(): int
    {
        $settings = $this->getSettings();
        $installDir = $settings['install_dir_abs'];
        $configFile = $settings['config_file_abs'];
        $issues = [];

        if (!is_dir($installDir)) {
            $issues[] = '未找到 OpenSearch 安装目录：' . $installDir;
        }

        if (!$this->isInstallTreeValid($installDir)) {
            $issues[] = '安装目录中缺少 OpenSearch 可执行文件。';
        }

        if (!is_file($configFile)) {
            $issues[] = '未找到 OpenSearch 配置文件：' . $configFile;
        }

        $marker = $installDir . DIRECTORY_SEPARATOR . self::VERSION_MARKER;
        $installedVersion = is_file($marker) ? trim((string) file_get_contents($marker)) : '';
        if ($installedVersion !== $settings['version']) {
            $issues[] = '当前安装版本与期望版本不一致，期望：' . $settings['version'] . '，实际：' . ($installedVersion ?: '未记录');
        }

        $appEnv = $this->readAppEnv();
        $searchConfig = $appEnv['search'] ?? [];
        $engineConfig = $searchConfig['engines']['opensearch'] ?? [];

        if (($searchConfig['default_engine'] ?? '') !== $settings['default_engine']) {
            $issues[] = 'app/etc/env.php 中 search.default_engine 未配置为 ' . $settings['default_engine'] . '。';
        }

        if (($searchConfig['default_scope'] ?? '') !== $settings['default_scope']) {
            $issues[] = 'app/etc/env.php 中 search.default_scope 未配置为 ' . $settings['default_scope'] . '。';
        }

        $expectedValues = [
            'host' => $settings['host'],
            'port' => $settings['port'],
            'index' => $settings['index'],
            'timeout' => $settings['timeout'],
            'version' => $settings['version'],
            'install_dir' => $settings['install_dir_rel'],
            'config_file' => $settings['config_file_rel'],
            'data_dir' => $settings['data_dir_rel'],
            'log_dir' => $settings['log_dir_rel'],
        ];

        foreach ($expectedValues as $key => $value) {
            if (($engineConfig[$key] ?? null) !== $value) {
                $issues[] = 'app/etc/env.php 中 search.engines.opensearch.' . $key . ' 未同步。';
            }
        }

        if ($issues !== []) {
            foreach ($issues as $issue) {
                $this->writeln($issue, STDERR);
            }
            return 1;
        }

        $this->writeln('OpenSearch 已安装并完成 Search 模块配置。');
        return 0;
    }

    private function install(): int
    {
        $settings = $this->getSettings();
        $artifact = $this->resolveArtifact($settings['version']);
        $installDir = $settings['install_dir_abs'];
        $configFile = $settings['config_file_abs'];
        $currentVersion = $this->readInstalledVersion($installDir);

        if (!$this->isInstallTreeValid($installDir) || $currentVersion !== $settings['version']) {
            $this->writeln('开始安装 OpenSearch ' . $settings['version'] . ' ...');
            $this->prepareInstallDirectories($settings);
            $archivePath = $this->downloadArtifact($artifact);
            $archiveSize = max(0, (int) filesize($archivePath));
            $this->assertEnoughSpace(
                $settings['extract_dir_abs'],
                max((int) ceil($archiveSize * 2), self::MIN_FREE_EXTRACT_BYTES),
                'OpenSearch 解压临时目录'
            );
            $this->assertEnoughSpace(
                $installDir,
                max((int) ceil($archiveSize * 1.5), self::MIN_FREE_INSTALL_BYTES),
                'OpenSearch 安装目录'
            );
            $extractRoot = $this->extractArtifact($archivePath, $artifact);

            if (is_dir($installDir)) {
                $backupDir = $this->backupExistingInstall($installDir, $currentVersion ?: 'unknown');
                $this->writeln('检测到旧安装，已备份到：' . $backupDir);
            }

            $this->copyRecursive($extractRoot, $installDir);
            $this->cleanupDirectory((string) $settings['extract_dir_abs']);
        } else {
            $this->writeln('检测到同版本 OpenSearch，跳过下载与解压。');
        }

        $this->ensureDirectory($installDir);
        $this->ensureDirectory(dirname($configFile));
        $this->ensureDirectory((string) $settings['data_dir_abs']);
        $this->ensureDirectory((string) $settings['log_dir_abs']);
        $this->assertEnoughSpace(
            (string) $settings['data_dir_abs'],
            self::MIN_FREE_INSTALL_BYTES,
            'OpenSearch 数据目录'
        );

        $this->writeOpenSearchConfig($settings);
        file_put_contents($installDir . DIRECTORY_SEPARATOR . self::VERSION_MARKER, $settings['version']);
        $this->writeAppEnvConfig($settings);

        $this->writeln('OpenSearch 安装完成，Search 模块默认引擎已切换为 OpenSearch。');
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        $appEnv = $this->readAppEnv();
        $appSearch = is_array($appEnv['search'] ?? null) ? $appEnv['search'] : [];
        $appOpenSearch = is_array($appSearch['engines']['opensearch'] ?? null) ? $appSearch['engines']['opensearch'] : [];

        $defaultScope = $this->normalizeString(
            $this->env['SEARCH_DEFAULT_SCOPE'] ?? ($appSearch['default_scope'] ?? self::DEFAULT_SCOPE),
            self::DEFAULT_SCOPE
        );
        $defaultEngine = $this->normalizeString(
            $this->env['SEARCH_DEFAULT_ENGINE'] ?? ($appSearch['default_engine'] ?? self::DEFAULT_ENGINE),
            self::DEFAULT_ENGINE
        );
        $version = $this->normalizeString(
            $this->env['INSTALL_OPENSEARCH_VERSION'] ?? ($appOpenSearch['version'] ?? self::DEFAULT_VERSION),
            self::DEFAULT_VERSION
        );
        $installDirRel = $this->normalizePathString(
            $this->env['SEARCH_OPENSEARCH_INSTALL_DIR'] ?? ($appOpenSearch['install_dir'] ?? self::DEFAULT_INSTALL_DIR),
            self::DEFAULT_INSTALL_DIR
        );
        $configFileRel = $this->normalizePathString(
            $this->env['SEARCH_OPENSEARCH_CONFIG_FILE'] ?? ($appOpenSearch['config_file'] ?? ($installDirRel . '/config/opensearch.yml')),
            $installDirRel . '/config/opensearch.yml'
        );
        $dataDirRel = $this->normalizePathString(
            $this->env['SEARCH_OPENSEARCH_DATA_DIR'] ?? ($appOpenSearch['data_dir'] ?? ($installDirRel . '/data')),
            $installDirRel . '/data'
        );
        $logDirRel = $this->normalizePathString(
            $this->env['SEARCH_OPENSEARCH_LOG_DIR'] ?? ($appOpenSearch['log_dir'] ?? ($installDirRel . '/logs')),
            $installDirRel . '/logs'
        );
        $tmpDirRel = $this->normalizePathString(
            $this->env['SEARCH_OPENSEARCH_TMP_DIR'] ?? $this->getDefaultTempRoot(),
            $this->getDefaultTempRoot()
        );
        $downloadDirRel = $this->normalizePathString(
            $this->env['SEARCH_OPENSEARCH_DOWNLOAD_DIR'] ?? ($tmpDirRel . '/downloads'),
            $tmpDirRel . '/downloads'
        );
        $extractDirRel = $this->normalizePathString(
            $this->env['SEARCH_OPENSEARCH_EXTRACT_DIR'] ?? ($tmpDirRel . '/extract'),
            $tmpDirRel . '/extract'
        );

        return [
            'default_scope' => $defaultScope,
            'default_engine' => strtolower($defaultEngine) !== '' ? strtolower($defaultEngine) : 'opensearch',
            'version' => $version,
            'host' => rtrim($this->normalizeString($this->env['SEARCH_OPENSEARCH_HOST'] ?? ($appOpenSearch['host'] ?? self::DEFAULT_HOST), self::DEFAULT_HOST), '/'),
            'port' => $this->normalizeInt(
                $this->env['SEARCH_OPENSEARCH_PORT'] ?? (isset($appOpenSearch['port']) ? (string) $appOpenSearch['port'] : null),
                self::DEFAULT_PORT
            ),
            'index' => $this->normalizeString($this->env['SEARCH_OPENSEARCH_INDEX'] ?? ($appOpenSearch['index'] ?? self::DEFAULT_INDEX), self::DEFAULT_INDEX),
            'timeout' => $this->normalizeInt(
                $this->env['SEARCH_OPENSEARCH_TIMEOUT'] ?? (isset($appOpenSearch['timeout']) ? (string) $appOpenSearch['timeout'] : null),
                self::DEFAULT_TIMEOUT
            ),
            'username' => $this->normalizeString($this->env['SEARCH_OPENSEARCH_USERNAME'] ?? ($appOpenSearch['username'] ?? ''), ''),
            'password' => $this->env['SEARCH_OPENSEARCH_PASSWORD'] ?? ($appOpenSearch['password'] ?? ''),
            'cluster_name' => $this->normalizeString($this->env['SEARCH_OPENSEARCH_CLUSTER_NAME'] ?? self::DEFAULT_CLUSTER_NAME, self::DEFAULT_CLUSTER_NAME),
            'node_name' => $this->normalizeString($this->env['SEARCH_OPENSEARCH_NODE_NAME'] ?? self::DEFAULT_NODE_NAME, self::DEFAULT_NODE_NAME),
            'bind_host' => $this->normalizeString($this->env['SEARCH_OPENSEARCH_BIND_HOST'] ?? self::DEFAULT_BIND_HOST, self::DEFAULT_BIND_HOST),
            'install_dir_rel' => $installDirRel,
            'install_dir_abs' => $this->resolveProjectPath($installDirRel),
            'config_file_rel' => $configFileRel,
            'config_file_abs' => $this->resolveProjectPath($configFileRel),
            'data_dir_rel' => $dataDirRel,
            'data_dir_abs' => $this->resolveProjectPath($dataDirRel),
            'log_dir_rel' => $logDirRel,
            'log_dir_abs' => $this->resolveProjectPath($logDirRel),
            'tmp_dir_rel' => $tmpDirRel,
            'tmp_dir_abs' => $this->resolveProjectPath($tmpDirRel),
            'download_dir_rel' => $downloadDirRel,
            'download_dir_abs' => $this->resolveProjectPath($downloadDirRel),
            'extract_dir_rel' => $extractDirRel,
            'extract_dir_abs' => $this->resolveProjectPath($extractDirRel),
        ];
    }

    /**
     * @return array{url:string,filename:string,archive_type:string}
     */
    private function resolveArtifact(string $version): array
    {
        $customUrl = trim((string) ($this->env['SEARCH_OPENSEARCH_DOWNLOAD_URL'] ?? ''));
        if ($customUrl !== '') {
            $path = parse_url($customUrl, PHP_URL_PATH);
            $filename = $path ? basename((string) $path) : ('opensearch-' . $version . '.archive');

            return [
                'url' => $customUrl,
                'filename' => $filename,
                'archive_type' => $this->detectArchiveTypeFromFilename($filename),
            ];
        }

        $platform = PHP_OS_FAMILY;
        $arch = strtolower(trim((string) php_uname('m')));
        $normalizedArch = match ($arch) {
            'x86_64', 'amd64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => '',
        };

        if ($normalizedArch === '') {
            throw new RuntimeException('当前架构暂未支持自动下载 OpenSearch：' . $arch);
        }

        $filename = match ($platform) {
            'Windows' => $normalizedArch === 'x64'
                ? sprintf('opensearch-%s-windows-x64.zip', $version)
                : throw new RuntimeException('Windows 当前仅支持官方 x64 OpenSearch 发行包。'),
            'Linux' => sprintf('opensearch-%s-linux-%s.tar.gz', $version, $normalizedArch),
            'Darwin' => throw new RuntimeException(
                'OpenSearch 官方稳定发行包当前未提供 macOS 默认下载，请改为手动安装，或在 weline.env 中配置 SEARCH_OPENSEARCH_DOWNLOAD_URL。'
            ),
            default => throw new RuntimeException('当前系统暂未支持自动下载 OpenSearch：' . $platform),
        };

        return [
            'url' => sprintf(
                'https://artifacts.opensearch.org/releases/bundle/opensearch/%s/%s',
                rawurlencode($version),
                rawurlencode($filename)
            ),
            'filename' => $filename,
            'archive_type' => $this->detectArchiveTypeFromFilename($filename),
        ];
    }

    /**
     * @param array{url:string,filename:string,archive_type:string} $artifact
     */
    private function downloadArtifact(array $artifact): string
    {
        $settings = $this->getSettings();
        $downloadDir = (string) $settings['download_dir_abs'];
        $this->ensureDirectory($downloadDir);
        $this->assertEnoughSpace($downloadDir, self::MIN_FREE_DOWNLOAD_BYTES, 'OpenSearch 下载目录');

        $targetPath = $downloadDir . DIRECTORY_SEPARATOR . $artifact['filename'];
        $this->writeln('下载 OpenSearch 包：' . $artifact['url']);

        if (is_file($targetPath) && filesize($targetPath) > 1024) {
            $this->writeln('复用已下载的 OpenSearch 包：' . $targetPath);
            return $targetPath;
        }

        if (PHP_OS_FAMILY === 'Windows' && $this->downloadWithWindowsCurl($artifact['url'], $targetPath)) {
            return $targetPath;
        }

        if (function_exists('curl_init')) {
            $fp = fopen($targetPath, 'wb');
            if ($fp === false) {
                throw new RuntimeException('无法创建下载文件：' . $targetPath);
            }

            $curl = curl_init($artifact['url']);
            if ($curl === false) {
                fclose($fp);
                throw new RuntimeException('无法初始化 curl。');
            }

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 1800,
                CURLOPT_FILE => $fp,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Weline Search OpenSearch Installer/1.0',
                    'Accept: application/octet-stream, */*',
                ],
            ]);

            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            fclose($fp);

            if (!$result || $status < 200 || $status >= 300) {
                @unlink($targetPath);
                throw new RuntimeException('下载 OpenSearch 失败：HTTP ' . $status . ($error !== '' ? '，' . $error : ''));
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 1800,
                    'header' => "User-Agent: Weline Search OpenSearch Installer/1.0\r\nAccept: application/octet-stream, */*\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                ],
            ]);

            $content = @file_get_contents($artifact['url'], false, $context);
            if ($content === false || file_put_contents($targetPath, $content) === false) {
                throw new RuntimeException('下载 OpenSearch 失败，请检查网络或 VPN 配置。');
            }
        }

        if (!is_file($targetPath) || filesize($targetPath) < 1024) {
            @unlink($targetPath);
            throw new RuntimeException('下载的 OpenSearch 包无效，请检查网络或版本配置。');
        }

        return $targetPath;
    }

    private function downloadWithWindowsCurl(string $url, string $targetPath): bool
    {
        $curlBinary = $this->findWindowsCurlBinary();
        if ($curlBinary === null) {
            return false;
        }

        $command = sprintf(
            '"%s" -L --fail --retry 3 --retry-delay 2 -o "%s" "%s"',
            $curlBinary,
            str_replace('"', '\"', $targetPath),
            str_replace('"', '\"', $url)
        );

        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || !is_file($targetPath) || filesize($targetPath) <= 1024) {
            @unlink($targetPath);
            return false;
        }

        return true;
    }

    private function findWindowsCurlBinary(): ?string
    {
        $candidates = [
            getenv('SystemRoot') !== false ? rtrim((string) getenv('SystemRoot'), '\\/') . DIRECTORY_SEPARATOR . 'System32' . DIRECTORY_SEPARATOR . 'curl.exe' : '',
            'curl.exe',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (str_contains($candidate, DIRECTORY_SEPARATOR) && is_file($candidate)) {
                return $candidate;
            }

            $output = [];
            $exitCode = 0;
            exec('where ' . escapeshellarg($candidate) . ' 2>nul', $output, $exitCode);
            if ($exitCode === 0 && !empty($output[0])) {
                return trim((string) $output[0]);
            }
        }

        return null;
    }

    /**
     * @param array{url:string,filename:string,archive_type:string} $artifact
     */
    private function extractArtifact(string $archivePath, array $artifact): string
    {
        $settings = $this->getSettings();
        $extractBase = (string) $settings['extract_dir_abs'];
        if (is_dir($extractBase)) {
            $this->cleanupDirectory($extractBase);
        }
        $this->ensureDirectory($extractBase);

        $archiveType = $artifact['archive_type'];
        if ($archiveType === 'zip') {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('当前 PHP 缺少 ZipArchive，无法解压 OpenSearch zip 包。');
            }

            $zip = new ZipArchive();
            if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('无法打开 OpenSearch zip 包：' . $archivePath);
            }

            if (!$zip->extractTo($extractBase)) {
                $zip->close();
                throw new RuntimeException('解压 OpenSearch zip 包失败。');
            }

            $zip->close();
            return $this->findExtractedRoot($extractBase);
        }

        if ($archiveType === 'tar.gz') {
            if (!class_exists('PharData')) {
                throw new RuntimeException('当前 PHP 缺少 PharData，无法解压 OpenSearch tar.gz 包。');
            }

            $tarPath = preg_replace('/\.gz$/i', '', $archivePath);
            if (!is_string($tarPath) || $tarPath === '') {
                throw new RuntimeException('无法识别 tar.gz 文件路径。');
            }

            if (!is_file($tarPath)) {
                $phar = new PharData($archivePath);
                $phar->decompress();
            }

            $tar = new PharData($tarPath);
            $tar->extractTo($extractBase, null, true);
            @unlink($tarPath);

            return $this->findExtractedRoot($extractBase);
        }

        throw new RuntimeException('暂不支持的 OpenSearch 压缩格式：' . $artifact['archive_type']);
    }

    private function backupExistingInstall(string $installDir, string $currentVersion): string
    {
        $backupDir = $installDir . '_backup_' . $currentVersion . '_' . date('YmdHis');
        if (!@rename($installDir, $backupDir)) {
            throw new RuntimeException('无法备份现有 OpenSearch 安装目录：' . $installDir);
        }

        return $backupDir;
    }

    private function writeOpenSearchConfig(array $settings): void
    {
        $configContent = implode("\n", [
            'cluster.name: ' . $this->yamlQuote((string) $settings['cluster_name']),
            'node.name: ' . $this->yamlQuote((string) $settings['node_name']),
            'path.data: ' . $this->yamlQuote($this->normalizeFilePath((string) $settings['data_dir_abs'])),
            'path.logs: ' . $this->yamlQuote($this->normalizeFilePath((string) $settings['log_dir_abs'])),
            'network.host: ' . $this->yamlQuote((string) $settings['bind_host']),
            'http.port: ' . (int) $settings['port'],
            'discovery.type: single-node',
            'plugins.security.disabled: true',
            '',
        ]);

        if (file_put_contents((string) $settings['config_file_abs'], $configContent) === false) {
            throw new RuntimeException('写入 OpenSearch 配置文件失败：' . $settings['config_file_abs']);
        }
    }

    private function writeAppEnvConfig(array $settings): void
    {
        $envConfig = $this->readAppEnv();
        $existingSearch = is_array($envConfig['search'] ?? null) ? $envConfig['search'] : [];
        $existingEngines = is_array($existingSearch['engines'] ?? null) ? $existingSearch['engines'] : [];
        $existingOpenSearch = is_array($existingEngines['opensearch'] ?? null) ? $existingEngines['opensearch'] : [];

        $existingEngines['opensearch'] = array_replace($existingOpenSearch, [
            'host' => $settings['host'],
            'port' => $settings['port'],
            'index' => $settings['index'],
            'username' => $settings['username'],
            'password' => $settings['password'],
            'timeout' => $settings['timeout'],
            'version' => $settings['version'],
            'install_dir' => $settings['install_dir_rel'],
            'config_file' => $settings['config_file_rel'],
            'data_dir' => $settings['data_dir_rel'],
            'log_dir' => $settings['log_dir_rel'],
        ]);

        $envConfig['search'] = array_replace($existingSearch, [
            'default_scope' => $settings['default_scope'],
            'default_engine' => $settings['default_engine'],
            'engines' => $existingEngines,
        ]);

        $this->saveAppEnv($envConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function readAppEnv(): array
    {
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        if (!is_file($path)) {
            return [];
        }

        $data = include $path;
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function saveAppEnv(array $config): void
    {
        $envDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc';
        $this->ensureDirectory($envDir);
        $path = $envDir . DIRECTORY_SEPARATOR . 'env.php';

        $content = "<?php return " . var_export($config, true) . ';';
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('写入 app/etc/env.php 失败：' . $path);
        }
    }

    /**
     * @return array<string, string>
     */
    private function loadWelineEnv(): array
    {
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . 'weline.env';
        $vars = [];
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }

                    if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches)) {
                        continue;
                    }

                    $key = $matches[1];
                    $value = trim($matches[2]);
                    $vars[$key] = $value;
                }
            }
        }

        foreach ($this->loadProcessEnvOverrides() as $key => $value) {
            $vars[$key] = $value;
        }

        return $vars;
    }

    /**
     * @return array<string, string>
     */
    private function loadProcessEnvOverrides(): array
    {
        $overrides = [];
        $allowedKeys = [
            'INSTALL_OPENSEARCH_VERSION',
            'SEARCH_DEFAULT_ENGINE',
            'SEARCH_DEFAULT_SCOPE',
            'SEARCH_OPENSEARCH_INSTALL_DIR',
            'SEARCH_OPENSEARCH_CONFIG_FILE',
            'SEARCH_OPENSEARCH_DATA_DIR',
            'SEARCH_OPENSEARCH_LOG_DIR',
            'SEARCH_OPENSEARCH_TMP_DIR',
            'SEARCH_OPENSEARCH_DOWNLOAD_DIR',
            'SEARCH_OPENSEARCH_EXTRACT_DIR',
            'SEARCH_OPENSEARCH_HOST',
            'SEARCH_OPENSEARCH_PORT',
            'SEARCH_OPENSEARCH_INDEX',
            'SEARCH_OPENSEARCH_TIMEOUT',
            'SEARCH_OPENSEARCH_USERNAME',
            'SEARCH_OPENSEARCH_PASSWORD',
            'SEARCH_OPENSEARCH_CLUSTER_NAME',
            'SEARCH_OPENSEARCH_NODE_NAME',
            'SEARCH_OPENSEARCH_BIND_HOST',
            'SEARCH_OPENSEARCH_DOWNLOAD_URL',
        ];

        foreach ($allowedKeys as $key) {
            $value = getenv($key);
            if ($value === false) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $overrides[$key] = $value;
        }

        return $overrides;
    }

    private function detectProjectRoot(string $directory): string
    {
        $current = $directory;
        for ($i = 0; $i < 10; $i++) {
            if (
                is_dir($current . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc')
                && is_file($current . DIRECTORY_SEPARATOR . 'composer.json')
            ) {
                return $current;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        throw new RuntimeException('无法定位项目根目录。');
    }

    private function resolveProjectPath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        if ($normalized === '') {
            return $this->projectRoot;
        }

        if ($this->isAbsolutePath($normalized)) {
            return $normalized;
        }

        return $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return preg_match('/^[A-Za-z]:(?:\\\\|\\/)/', $path) === 1 || str_starts_with($path, '\\\\');
        }

        return str_starts_with($path, '/');
    }

    private function readInstalledVersion(string $installDir): string
    {
        $marker = $installDir . DIRECTORY_SEPARATOR . self::VERSION_MARKER;
        if (!is_file($marker)) {
            return '';
        }

        return trim((string) file_get_contents($marker));
    }

    private function isInstallTreeValid(string $installDir): bool
    {
        $executables = [
            $installDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'opensearch',
            $installDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'opensearch.bat',
            $installDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'opensearch-tar-install.sh',
        ];

        foreach ($executables as $file) {
            if (is_file($file)) {
                return true;
            }
        }

        return false;
    }

    private function findExtractedRoot(string $extractBase): string
    {
        $entries = array_values(array_filter(
            scandir($extractBase) ?: [],
            static fn (string $item): bool => $item !== '.' && $item !== '..'
        ));

        if (count($entries) === 1) {
            $candidate = $extractBase . DIRECTORY_SEPARATOR . $entries[0];
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return $extractBase;
    }

    private function cleanupDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function copyRecursive(string $source, string $target): void
    {
        if (is_file($source)) {
            $this->ensureDirectory(dirname($target));
            if (!@copy($source, $target)) {
                throw new RuntimeException('复制文件失败：' . $source);
            }
            return;
        }

        $this->ensureDirectory($target);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                $this->ensureDirectory($targetPath);
                continue;
            }

            $this->ensureDirectory(dirname($targetPath));
            if (!@copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException('复制文件失败：' . $item->getPathname());
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('创建目录失败：' . $directory);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function prepareInstallDirectories(array $settings): void
    {
        $this->ensureDirectory((string) $settings['tmp_dir_abs']);
        $this->ensureDirectory((string) $settings['download_dir_abs']);
        $this->ensureDirectory((string) $settings['extract_dir_abs']);
        $this->ensureDirectory((string) $settings['data_dir_abs']);
        $this->ensureDirectory((string) $settings['log_dir_abs']);

        $this->writeln('OpenSearch 临时目录：' . $settings['tmp_dir_abs']);
        $this->writeln('OpenSearch 下载目录：' . $settings['download_dir_abs']);
        $this->writeln('OpenSearch 解压目录：' . $settings['extract_dir_abs']);
        $this->writeln('OpenSearch 安装目录：' . $settings['install_dir_abs']);
    }

    private function assertEnoughSpace(string $path, int $requiredBytes, string $label): void
    {
        $freeBytes = $this->getFreeSpaceBytes($path);
        if ($freeBytes === null) {
            return;
        }

        if ($freeBytes >= $requiredBytes) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s 可用空间不足。需要至少 %s，当前约 %s。可通过 SEARCH_OPENSEARCH_INSTALL_DIR / SEARCH_OPENSEARCH_DATA_DIR / SEARCH_OPENSEARCH_LOG_DIR / SEARCH_OPENSEARCH_TMP_DIR 指向其他磁盘。',
            $label,
            $this->formatBytes($requiredBytes),
            $this->formatBytes($freeBytes)
        ));
    }

    private function getFreeSpaceBytes(string $path): ?int
    {
        $directory = $path;
        while ($directory !== '' && !is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        if ($directory === '' || !is_dir($directory)) {
            return null;
        }

        $free = @disk_free_space($directory);
        if ($free === false) {
            return null;
        }

        return (int) $free;
    }

    private function getDefaultTempRoot(): string
    {
        $tmp = rtrim((string) sys_get_temp_dir(), '\\/');
        if ($tmp === '') {
            return 'var/tmp/opensearch-runtime';
        }

        return $tmp . DIRECTORY_SEPARATOR . 'weline-opensearch';
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = max(0, $bytes);
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf('%.2f %s', $size, $units[$unit]);
    }

    private function detectArchiveTypeFromFilename(string $filename): string
    {
        $lower = strtolower($filename);
        if (str_ends_with($lower, '.tar.gz')) {
            return 'tar.gz';
        }
        if (str_ends_with($lower, '.zip')) {
            return 'zip';
        }

        return 'unknown';
    }

    private function normalizeString(?string $value, string $default): string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : $default;
    }

    private function normalizeInt(?string $value, int $default): int
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }

    private function normalizePathString(?string $value, string $default): string
    {
        $value = trim((string) $value);
        $value = $value !== '' ? $value : $default;

        return str_replace(['\\', '/'], '/', $value);
    }

    private function normalizeFilePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function yamlQuote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
    }

    private function writeln(string $message, $stream = STDOUT): void
    {
        fwrite($stream, $message . PHP_EOL);
    }

    private function fail(string $message): int
    {
        $this->writeln('OpenSearch 环境安装失败：' . $message, STDERR);
        return 1;
    }
}

$installer = new OpenSearchEnvInstaller(__DIR__);
exit($installer->run($argv[1] ?? 'check'));
