<?php

declare(strict_types=1);

/**
 * Configure php.ini: extension_dir, extensions from composer, remove allowed functions from disable_functions.
 * Single responsibility: php.ini file content only.
 */
final class ConfigurePhpIni
{
    private string $projectRoot;
    private string $phpDir;

    /** @var string[] */
    private array $defaultFunctions = [
        'exec', 'putenv', 'proc_open', 'proc_get_status', 'shell_exec',
        'passthru', 'system', 'popen', 'pcntl_fork', 'pcntl_signal',
    ];

    public function __construct(string $projectRoot, string $phpDir)
    {
        $this->projectRoot = $projectRoot;
        $this->phpDir = rtrim(str_replace('\\', '/', $phpDir), '/');
    }

    /**
     * 框架必需扩展（与 InstallData env.modules 一致）+ PostgreSQL 驱动 + WLS 必需（sockets 等），Step 1 即尝试启用。
     * @return string[]
     */
    public static function getFrameworkRequiredExtensions(): array
    {
        return ['PDO', 'exif', 'fileinfo', 'xsl', 'pdo_pgsql', 'pgsql', 'sockets', 'curl', 'mbstring'];
    }

    /**
     * Collect ext-* from composer.json and composer.lock.
     * @return string[] extension names (e.g. ['curl', 'openssl'])
     */
    public function getExtensionsFromComposer(): array
    {
        $extSet = [];
        $addFrom = function (object $req) use (&$extSet) {
            if (!$req) {
                return;
            }
            foreach ((array)$req as $key => $unused) {
                if (strpos($key, 'ext-') === 0) {
                    $extSet[substr($key, 4)] = true;
                }
            }
        };

        $jsonPath = $this->projectRoot . '/composer.json';
        if (is_file($jsonPath)) {
            $json = json_decode(file_get_contents($jsonPath));
            if ($json) {
                if (isset($json->require)) {
                    $addFrom($json->require);
                }
                if (isset($json->{'require-dev'})) {
                    $addFrom($json->{'require-dev'});
                }
            }
        }

        $lockPath = $this->projectRoot . '/composer.lock';
        if (is_file($lockPath)) {
            $lock = json_decode(file_get_contents($lockPath));
            if ($lock && isset($lock->packages)) {
                foreach ($lock->packages as $pkg) {
                    if (isset($pkg->require)) {
                        $addFrom($pkg->require);
                    }
                    if (isset($pkg->{'require-dev'})) {
                        $addFrom($pkg->{'require-dev'});
                    }
                }
            }
            if ($lock && isset($lock->{'packages-dev'})) {
                foreach ($lock->{'packages-dev'} as $pkg) {
                    if (isset($pkg->require)) {
                        $addFrom($pkg->require);
                    }
                    if (isset($pkg->{'require-dev'})) {
                        $addFrom($pkg->{'require-dev'});
                    }
                }
            }
        }

        $list = array_keys($extSet);
        sort($list);
        return $list;
    }

    /**
     * Get allowed functions list (from env or default).
     * @return string[]
     */
    public function getAllowedFunctions(array $envVars): array
    {
        $raw = $envVars['PHP_FUNCTIONS'] ?? '';
        if (trim($raw) !== '') {
            $parsed = array_map('trim', explode(',', $raw));
            $parsed = array_values(array_filter($parsed));
            if ($parsed !== []) {
                return $parsed;
            }
        }
        return $this->defaultFunctions;
    }

    /**
     * Apply configuration to php.ini and save.
     */
    public function apply(array $envVars): void
    {
        $iniPath = $this->phpDir . '/php.ini';
        $iniDev = $this->phpDir . '/php.ini-development';
        if (!is_file($iniPath) && is_file($iniDev)) {
            copy($iniDev, $iniPath);
        }
        if (!is_file($iniPath)) {
            return;
        }

        $extDir = $this->phpDir . '/ext';
        $content = file_get_contents($iniPath);

        // extension_dir
        $content = preg_replace('/;\s*extension_dir\s*=\s*"ext"/', 'extension_dir = "' . $extDir . '"', $content);
        $content = preg_replace('/;\s*extension_dir\s*=\s*"\.\/ext"/', 'extension_dir = "' . $extDir . '"', $content);
        if (!preg_match('/extension_dir\s*=/', $content)) {
            $content = preg_replace('/(\[PHP\])/', '$1' . "\nextension_dir = \"$extDir\"\n", $content, 1);
        }

        // extensions: 框架必需(PDO,exif,fileinfo,xsl) + composer ext-* + openssl；Windows 下仅当存在 php_*.dll 时启用
        $extList = array_merge(
            self::getFrameworkRequiredExtensions(),
            $this->getExtensionsFromComposer()
        );
        if (!in_array('openssl', $extList, true)) {
            array_unshift($extList, 'openssl');
        }
        $extList = array_values(array_unique($extList));

        $extDirReal = $this->phpDir . '/ext';
        $isWindows = (DIRECTORY_SEPARATOR === '\\');

        // Windows: 注释掉所有“extension=xxx”且 php_xxx.dll 不存在的行，避免 Unable to load 警告
        if ($isWindows && is_dir($extDirReal)) {
            $content = preg_replace_callback(
                '/^(\s*)(extension\s*=\s*([a-z0-9_]+)\s*)$/mi',
                function (array $m) use ($extDirReal) {
                    $ext = strtolower($m[3]);
                    $dll = $extDirReal . '/' . 'php_' . $ext . '.dll';
                    if (!is_file($dll)) {
                        return $m[1] . ';' . $m[2] . '  ; DLL not present, commented by installer';
                    }
                    return $m[0];
                },
                $content
            );
        }

        foreach ($extList as $ext) {
            if ($isWindows && is_dir($extDirReal)) {
                $dll = $extDirReal . '/php_' . $ext . '.dll';
                if (!is_file($dll)) {
                    continue;
                }
            }
            $content = preg_replace('/;\s*extension=' . preg_quote($ext, '/') . '\b/', 'extension=' . $ext, $content);
            if (!preg_match('/(?:^|\r?\n)\s*extension\s*=\s*' . preg_quote($ext, '/') . '\b/m', $content)) {
                $content = preg_replace('/(\[PHP\])/', '$1' . "\nextension=$ext\n", $content, 1);
            }
        }

        // disable_functions: remove allowed functions
        $allowed = $this->getAllowedFunctions($envVars);
        $content = preg_replace_callback(
            '/^\s*disable_functions\s*=\s*(.*)$/m',
            function (array $m) use ($allowed) {
                $val = trim($m[1]);
                $current = array_filter(array_map('trim', preg_split('/\s*,\s*/', $val)));
                $allowedLower = array_map('strtolower', $allowed);
                $newList = array_filter($current, function ($f) use ($allowedLower) {
                    return !in_array(strtolower($f), $allowedLower, true);
                });
                return 'disable_functions = ' . implode(', ', $newList);
            },
            $content
        );

        // 日志路径：统一指向 var/log/php/
        $content = $this->configureLogPaths($content);

        file_put_contents($iniPath, $content);

        // 真实检测：用 php -m 取实际已加载扩展，注释掉未加载的 extension= 行
        $loaded = $this->getLoadedExtensions();
        if ($loaded !== null) {
            $content = file_get_contents($iniPath);
            $content = preg_replace_callback(
                '/^(\s*)(extension\s*=\s*([a-z0-9_]+))\s*(.*)$/mi',
                function (array $m) use ($loaded) {
                    if (strpos(ltrim($m[0]), ';') === 0) {
                        return $m[0];
                    }
                    $ext = strtolower($m[3]);
                    if (isset($loaded[$ext])) {
                        return $m[0];
                    }
                    return $m[1] . ';' . $m[2] . ($m[4] !== '' ? ' ' . $m[4] : '') . "  ; not loaded by php -m, commented by installer";
                },
                $content
            );
            file_put_contents($iniPath, $content);
        }
    }

    /**
     * 运行 php -m 获取实际已加载的扩展列表（小写），失败返回 null。
     * @return array<string, true>|null
     */
    private function getLoadedExtensions(): ?array
    {
        $phpExe = (DIRECTORY_SEPARATOR === '\\')
            ? $this->phpDir . '/php.exe'
            : $this->phpDir . '/bin/php';
        if (!is_file($phpExe)) {
            return null;
        }
        $cmd = escapeshellarg($phpExe) . ' -m 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null');
        $out = @shell_exec($cmd);
        if ($out === null || $out === '') {
            return null;
        }
        $loaded = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '[' || strpos($line, ' ') !== false) {
                continue;
            }
            $loaded[strtolower($line)] = true;
        }
        return $loaded;
    }

    /**
     * 将 PHP 所有日志路径统一配置到 var/log/php/ 下，并确保目录存在。
     */
    private function configureLogPaths(string $content): string
    {
        $logDir = $this->projectRoot . '/var/log/php';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $directives = [
            'error_log' => $logDir . '/php_errors.log',
            'mail.log'  => $logDir . '/mail.log',
        ];

        foreach ($directives as $key => $path) {
            $escaped = preg_quote($key, '/');
            $pathValue = str_replace('\\', '/', $path);

            // 取消注释并替换值，或直接替换已有值
            $content = preg_replace(
                '/^\s*;?\s*' . $escaped . '\s*=.*$/m',
                $key . ' = "' . $pathValue . '"',
                $content,
                1,
                $count
            );
            if ($count === 0) {
                $content = preg_replace('/(\[PHP\])/', '$1' . "\n" . $key . ' = "' . $pathValue . '"' . "\n", $content, 1);
            }
        }

        // log_errors = On
        $content = preg_replace('/^\s*;?\s*log_errors\s*=.*$/m', 'log_errors = On', $content, 1, $count);
        if ($count === 0) {
            $content = preg_replace('/(\[PHP\])/', '$1' . "\nlog_errors = On\n", $content, 1);
        }

        return $content;
    }
}
