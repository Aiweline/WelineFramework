<?php

declare(strict_types=1);

namespace Weline\Framework\Env\Service;

/**
 * 把 extension= 或 zend_extension= 写入当前 PHP 进程真正会读取的 ini。
 *
 * 1. 已加载 php.ini，以及同目录兄弟 php.ini / php-cli.ini（宝塔双文件）
 * 2. 仅当 PHP 实际扫描附加目录时写 weline-{ext}.ini
 * 3. 不创建未被扫描的 conf.d，也不把「文件写上了」当成启用成功
 * 4. success 只在 $probe 为 true 时成立
 */
final class PhpExtensionIniEnabler
{
    /**
     * @param callable():bool $probe 新进程是否已加载该扩展
     * @param list<string> $extraLines 例如 ffi.enable=true
     * @param array{ini_files?:list<string>,scan_dir?:string|null,load?:string,force_directives?:bool} $context
     *        load=extension|zend_extension；force_directives 为 true 时覆盖已有同名指令（安装期优化）
     * @return array{success:bool,message:string,targets:list<string>}
     */
    public function enable(string $extension, callable $probe, array $extraLines = [], array $context = []): array
    {
        $extension = strtolower(trim($extension));
        if ($extension === '' || preg_match('/^[a-z0-9_]+$/', $extension) !== 1) {
            return [
                'success' => false,
                'message' => '非法扩展名: ' . $extension,
                'targets' => [],
            ];
        }

        $load = ($context['load'] ?? 'extension') === 'zend_extension' ? 'zend_extension' : 'extension';
        $force = ($context['force_directives'] ?? false) === true;
        if ($load === 'extension' && array_key_exists('builtin', $context)) {
            if ((bool)$context['builtin']) {
                $load = '';
            }
        } elseif ($load === 'extension' && \extension_loaded($extension)) {
            // 静态编进 PHP 的扩展（如 --with-ffi）没有 .so：再写 extension= 会让每个进程启动时
            // 报 "Unable to load dynamic library"。已加载且扩展目录里没有对应 .so 时按内置处理，
            // 只保留附加指令（如 ffi.enable=true），不再写加载行。
            $extDir = \rtrim((string)\ini_get('extension_dir'), '/\\');
            if ($extDir !== '' && !\is_file($extDir . DIRECTORY_SEPARATOR . $extension . '.so')) {
                $load = '';
            }
        }
        $targets = [];
        $iniFiles = $context['ini_files'] ?? $this->discoverIniFiles();
        foreach ($iniFiles as $ini) {
            if ($this->ensureExtensionLine($ini, $extension, $extraLines, $load, $force)) {
                $targets[] = $ini;
            }
        }

        $scanDir = array_key_exists('scan_dir', $context)
            ? $context['scan_dir']
            : $this->discoverActiveScanDir();
        if (is_string($scanDir) && $scanDir !== '') {
            $dropIn = $this->writeDropIn($scanDir, $extension, $extraLines, $load);
            if ($dropIn !== null) {
                $targets[] = $dropIn;
            }
        }

        if ($probe()) {
            return [
                'success' => true,
                'message' => $targets === []
                    ? $extension . ' 已加载'
                    : '已启用 ' . $extension . '（' . implode(', ', $targets) . '）',
                'targets' => $targets,
            ];
        }

        if ($targets === []) {
            return [
                'success' => false,
                'message' => '无法写入已加载 php.ini，且没有活动的附加 ini 扫描目录，未能启用 ' . $extension,
                'targets' => [],
            ];
        }

        return [
            'success' => false,
            'message' => '已改配置但新进程仍未加载 ' . $extension . '（' . implode(', ', $targets) . '）',
            'targets' => $targets,
        ];
    }

    /**
     * @return list<string>
     */
    public function discoverIniFiles(): array
    {
        $loaded = php_ini_loaded_file();
        if (!is_string($loaded) || $loaded === '' || !is_file($loaded) || !is_writable($loaded)) {
            return [];
        }

        $files = [$loaded];
        $loadedReal = realpath($loaded);
        $dir = dirname($loaded);
        foreach (['php.ini', 'php-cli.ini'] as $name) {
            $sibling = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($sibling) || !is_writable($sibling)) {
                continue;
            }
            $siblingReal = realpath($sibling);
            if ($siblingReal !== false && $siblingReal !== $loadedReal) {
                $files[] = $sibling;
            }
        }

        return $files;
    }

    public function discoverActiveScanDir(): ?string
    {
        $defined = defined('PHP_CONFIG_FILE_SCAN_DIR') ? (string) PHP_CONFIG_FILE_SCAN_DIR : '';
        if ($defined !== '' && is_dir($defined) && is_writable($defined)) {
            return $defined;
        }

        $scan = (string) php_ini_scanned_files();
        if ($scan === '') {
            return null;
        }
        $first = trim(explode(',', $scan)[0] ?? '');
        if ($first === '' || !is_file($first)) {
            return null;
        }
        $dir = dirname($first);

        return is_writable($dir) ? $dir : null;
    }

    /**
     * @param list<string> $extraLines
     */
    private function ensureExtensionLine(string $iniPath, string $extension, array $extraLines, string $load, bool $force): bool
    {
        if (!is_file($iniPath) || !is_writable($iniPath)) {
            return false;
        }
        $content = file_get_contents($iniPath);
        if ($content === false) {
            return false;
        }
        $original = $content;
        $content = $this->ensureLoadLine($content, $extension, $load);
        $content = $this->applyExtraLines($content, $extraLines, $force);
        if ($content !== $original && file_put_contents($iniPath, $content, LOCK_EX) === false) {
            return false;
        }

        return true;
    }

    private function ensureLoadLine(string $content, string $extension, string $load): string
    {
        if ($load === '') {
            return $content;
        }
        if ($load === 'zend_extension' && $extension === 'opcache') {
            $active = '/^\s*zend_extension\s*=\s*["\']?(?:.*[\/\\\\])?(?:php_)?opcache(?:\.(?:so|dll))?["\']?\s*$/mi';
            if (preg_match($active, $content) === 1) {
                return $content;
            }
            $comment = '/^;\s*zend_extension\s*=.*opcache.*$/mi';
            if (preg_match($comment, $content) === 1) {
                return (string) preg_replace($comment, 'zend_extension=opcache', $content, 1);
            }

            return rtrim($content) . "\nzend_extension=opcache\n";
        }

        $extPattern = '/^\s*' . preg_quote($load, '/') . '\s*=\s*' . preg_quote($extension, '/') . '(?:\.so)?\s*$/mi';
        if (preg_match($extPattern, $content) === 1) {
            return $content;
        }
        $comment = '/^;(\s*' . preg_quote($load, '/') . '\s*=\s*' . preg_quote($extension, '/') . '(?:\.so)?\s*)$/mi';
        if (preg_match($comment, $content) === 1) {
            return (string) preg_replace($comment, '$1', $content, 1);
        }

        return rtrim($content) . "\n" . $load . '=' . $extension . "\n";
    }

    /**
     * @param list<string> $extraLines
     */
    private function applyExtraLines(string $content, array $extraLines, bool $force): string
    {
        foreach ($extraLines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }
            $key = $match[1];
            $escaped = preg_quote($key, '/');
            if (preg_match('/^\s*;?\s*' . $escaped . '\s*=.*$/mi', $content) === 1) {
                if ($force) {
                    $content = (string) preg_replace('/^\s*;?\s*' . $escaped . '\s*=.*$/mi', $line, $content, 1);
                }
                continue;
            }
            $content = rtrim($content) . "\n" . $line . "\n";
        }

        return $content;
    }

    /**
     * @param list<string> $extraLines
     */
    private function writeDropIn(string $scanDir, string $extension, array $extraLines, string $load): ?string
    {
        if (!is_dir($scanDir) || !is_writable($scanDir)) {
            return null;
        }
        $loadLine = $load === 'zend_extension' && $extension === 'opcache'
            ? 'zend_extension=opcache'
            : $load . '=' . $extension;
        $lines = ['; generated by Weline PhpExtensionIniEnabler'];
        if ($load !== '') {
            $lines[] = $loadLine;
        }
        foreach ($extraLines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $target = rtrim($scanDir, "/\\") . DIRECTORY_SEPARATOR . 'weline-' . $extension . '.ini';
        if (count($lines) === 1) {
            // 内置扩展且无附加指令：不生成 ini，并清掉历史遗留的自动加载 ini。
            if (is_file($target)) {
                @\unlink($target);
            }

            return null;
        }
        $desired = implode(PHP_EOL, $lines) . PHP_EOL;
        if (is_file($target) && file_get_contents($target) === $desired) {
            return $target;
        }
        if (file_put_contents($target, $desired, LOCK_EX) === false) {
            return null;
        }

        return $target;
    }
}
