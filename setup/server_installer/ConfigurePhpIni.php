<?php

declare(strict_types=1);

/**
 * Configure php.ini: extension_dir, required extensions, log paths,
 * and disable_functions adjustments for the installer/runtime.
 */
final class ConfigurePhpIni
{
    private const MANAGED_EXTENSIONS_BEGIN = '; >>> installer managed extensions >>>';
    private const MANAGED_EXTENSIONS_END = '; <<< installer managed extensions <<<';

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
     * Required extensions for the framework installer/runtime.
     *
     * @return string[]
     */
    public static function getFrameworkRequiredExtensions(): array
    {
        return ['PDO', 'openssl', 'curl', 'mbstring', 'exif', 'fileinfo', 'xsl', 'pdo_pgsql', 'pgsql', 'sockets'];
    }

    /**
     * Collect ext-* from composer.json and composer.lock.
     *
     * @return string[]
     */
    public function getExtensionsFromComposer(): array
    {
        $extSet = [];
        $addFrom = function (object $req) use (&$extSet): void {
            foreach ((array) $req as $key => $unused) {
                if (strpos((string) $key, 'ext-') === 0) {
                    $extSet[strtolower(substr((string) $key, 4))] = true;
                }
            }
        };

        $jsonPath = $this->projectRoot . '/composer.json';
        if (is_file($jsonPath)) {
            $json = json_decode((string) file_get_contents($jsonPath));
            if ($json) {
                if (isset($json->require) && is_object($json->require)) {
                    $addFrom($json->require);
                }
                if (isset($json->{'require-dev'}) && is_object($json->{'require-dev'})) {
                    $addFrom($json->{'require-dev'});
                }
            }
        }

        $lockPath = $this->projectRoot . '/composer.lock';
        if (is_file($lockPath)) {
            $lock = json_decode((string) file_get_contents($lockPath));
            if ($lock && isset($lock->packages) && is_array($lock->packages)) {
                foreach ($lock->packages as $pkg) {
                    if (isset($pkg->require) && is_object($pkg->require)) {
                        $addFrom($pkg->require);
                    }
                    if (isset($pkg->{'require-dev'}) && is_object($pkg->{'require-dev'})) {
                        $addFrom($pkg->{'require-dev'});
                    }
                }
            }
            if ($lock && isset($lock->{'packages-dev'}) && is_array($lock->{'packages-dev'})) {
                foreach ($lock->{'packages-dev'} as $pkg) {
                    if (isset($pkg->require) && is_object($pkg->require)) {
                        $addFrom($pkg->require);
                    }
                    if (isset($pkg->{'require-dev'}) && is_object($pkg->{'require-dev'})) {
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
     * @return string[]
     */
    public function getAllowedFunctions(array $envVars): array
    {
        $raw = $envVars['PHP_FUNCTIONS'] ?? '';
        if (trim((string) $raw) !== '') {
            $parsed = array_map('trim', explode(',', (string) $raw));
            $parsed = array_values(array_filter($parsed, static fn(string $item): bool => $item !== ''));
            if ($parsed !== []) {
                return $parsed;
            }
        }
        return $this->defaultFunctions;
    }

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
        $extDirReal = str_replace('\\', '/', $extDir);
        $isWindows = (DIRECTORY_SEPARATOR === '\\');
        $extensionDirValue = $isWindows ? 'ext' : $extDirReal;
        $content = (string) file_get_contents($iniPath);

        $content = preg_replace('/^\s*;?\s*extension_dir\s*=.*$/mi', 'extension_dir = "' . $extensionDirValue . '"', $content, -1, $count);
        if (($count ?? 0) === 0) {
            $content = preg_replace('/(\[PHP\])/', '$1' . "\nextension_dir = \"" . $extensionDirValue . "\"\n", $content, 1) ?? $content;
        }

        $extList = array_merge(self::getFrameworkRequiredExtensions(), $this->getExtensionsFromComposer());
        $extList = array_values(array_unique(array_map(static fn(string $ext): string => strtolower(trim($ext)), $extList)));
        $content = $this->upsertManagedExtensionBlock($content, $extList, $extDirReal, $isWindows);

        $allowed = $this->getAllowedFunctions($envVars);
        $content = preg_replace_callback(
            '/^\s*disable_functions\s*=\s*(.*)$/mi',
            static function (array $matches) use ($allowed): string {
                $current = array_filter(array_map('trim', preg_split('/\s*,\s*/', trim((string) $matches[1])) ?: []));
                $allowedLower = array_map('strtolower', $allowed);
                $newList = array_filter($current, static fn(string $f): bool => !in_array(strtolower($f), $allowedLower, true));
                return 'disable_functions = ' . implode(', ', $newList);
            },
            $content
        ) ?? $content;

        $content = preg_replace('/^\s*;?\s*memory_limit\s*=.*$/mi', 'memory_limit = 512M', $content, 1, $memCount);
        if (($memCount ?? 0) === 0) {
            $content = preg_replace('/(\[PHP\])/', '$1' . "\nmemory_limit = 512M\n", $content, 1) ?? $content;
        }

        $content = $this->configureLogPaths($content);
        file_put_contents($iniPath, $content);
        file_put_contents($this->phpDir . '/php.installer.ini', $this->buildInstallerIni($extList, $extDirReal, $isWindows));
    }

    private function upsertManagedExtensionBlock(string $content, array $extList, string $extDirReal, bool $isWindows): string
    {
        $managedLines = [self::MANAGED_EXTENSIONS_BEGIN];

        foreach ($extList as $ext) {
            if ($ext === '' || $ext === 'pdo') {
                continue;
            }
            if ($isWindows) {
                $dll = $extDirReal . '/php_' . $ext . '.dll';
                if (!is_file($dll)) {
                    continue;
                }
            }
            $managedLines[] = 'extension=' . $ext;
        }

        if ($isWindows && is_file($extDirReal . '/php_opcache.dll')) {
            $managedLines[] = 'zend_extension=opcache';
        }

        $managedLines[] = self::MANAGED_EXTENSIONS_END;
        $managedBlock = implode("\n", $managedLines) . "\n";

        $pattern = '/' . preg_quote(self::MANAGED_EXTENSIONS_BEGIN, '/') . '.*?' . preg_quote(self::MANAGED_EXTENSIONS_END, '/') . '\R?/s';
        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $managedBlock, $content, 1) ?? $content;
        }

        if (preg_match('/^\s*extension_dir\s*=.*$/mi', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $fullLine = $matches[0][0];
            $offset = $matches[0][1] + strlen($fullLine);
            return substr($content, 0, $offset) . "\n" . $managedBlock . substr($content, $offset);
        }

        return preg_replace('/(\[PHP\])/', '$1' . "\n" . $managedBlock, $content, 1) ?? $content;
    }

    private function configureLogPaths(string $content): string
    {
        $logDir = str_replace('\\', '/', $this->projectRoot . '/var/log/php');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $directives = [
            'error_log' => $logDir . '/php_errors.log',
            'mail.log' => $logDir . '/mail.log',
        ];

        foreach ($directives as $key => $path) {
            $escaped = preg_quote($key, '/');
            $content = preg_replace('/^\s*;?\s*' . $escaped . '\s*=.*$/mi', $key . ' = "' . $path . '"', $content, 1, $count) ?? $content;
            if (($count ?? 0) === 0) {
                $content = preg_replace('/(\[PHP\])/', '$1' . "\n" . $key . ' = "' . $path . '"' . "\n", $content, 1) ?? $content;
            }
        }

        $content = preg_replace('/^\s*;?\s*log_errors\s*=.*$/mi', 'log_errors = On', $content, 1, $count) ?? $content;
        if (($count ?? 0) === 0) {
            $content = preg_replace('/(\[PHP\])/', '$1' . "\nlog_errors = On\n", $content, 1) ?? $content;
        }

        return $content;
    }

    private function buildInstallerIni(array $extList, string $extDirReal, bool $isWindows): string
    {
        $extensionDirValue = $isWindows ? 'ext' : $extDirReal;
        $lines = [
            'extension_dir = "' . $extensionDirValue . '"',
            'memory_limit = 512M',
            'log_errors = On',
        ];

        foreach ($extList as $ext) {
            if ($ext === '' || $ext === 'pdo') {
                continue;
            }
            if ($isWindows) {
                $dll = $extDirReal . '/php_' . $ext . '.dll';
                if (!is_file($dll)) {
                    continue;
                }
            }
            $lines[] = 'extension=' . $ext;
        }

        if ($isWindows && is_file($extDirReal . '/php_opcache.dll')) {
            $lines[] = 'zend_extension=opcache';
        }

        return implode("\n", $lines) . "\n";
    }
}
