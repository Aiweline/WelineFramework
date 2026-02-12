<?php

declare(strict_types=1);

/**
 * Windows: 从官方 PHP 发布包补全 extend/server/php/ext 下缺失的扩展 DLL。
 * 仅在 Windows 且使用 extend/server/php 时执行；通过重新下载同版本 zip 并解压 ext 目录补全。
 */
final class WindowsExtInstaller
{
    private string $phpDir;
    private string $projectRoot;

    /** 官方包使用的 VS 版本，需与 install.bat 中一致 */
    private const VS = 'vs17';

    public function __construct(string $projectRoot, string $phpDir)
    {
        $this->projectRoot = $projectRoot;
        $this->phpDir = rtrim(str_replace('\\', '/', $phpDir), '/');
    }

    /**
     * 若为 Windows 且 ext 目录存在，则尝试补全缺失的 php_*.dll（从官方 zip 解压）。
     * 返回本次新复制的 dll 数量（0 表示未执行或无需补全）。
     */
    public function ensureExtensions(): int
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return 0;
        }
        $extDir = $this->phpDir . '/ext';
        if (!is_dir($extDir)) {
            return 0;
        }

        $version = $this->getPhpVersion();
        if ($version === null) {
            return 0;
        }

        $zipPath = $this->downloadZip($version);
        if ($zipPath === null) {
            return 0;
        }

        $copied = $this->copyMissingDllsFromZip($zipPath, $extDir);
        @unlink($zipPath);
        return $copied;
    }

    private function getPhpVersion(): ?string
    {
        $phpExe = $this->phpDir . '/php.exe';
        if (!is_file($phpExe)) {
            return null;
        }
        $cmd = sprintf(
            '%s -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION.\".\".PHP_RELEASE_VERSION;"',
            escapeshellarg($phpExe)
        );
        $line = @shell_exec($cmd);
        if ($line === null || $line === '') {
            return null;
        }
        $v = trim($line);
        return preg_match('/^\d+\.\d+\.\d+$/', $v) ? $v : null;
    }

    private function downloadZip(string $version): ?string
    {
        $url = 'https://windows.php.net/downloads/releases/php-' . $version . '-Win32-' . self::VS . '-x64.zip';
        $out = $this->projectRoot . '/var/tmp/php-' . $version . '-ext.zip';
        $dir = dirname($out);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            return null;
        }

        if (function_exists('curl_init')) {
            $fp = fopen($out, 'w');
            if ($fp === false) {
                return null;
            }
            $ch = curl_init($url);
            if ($ch === false) {
                fclose($fp);
                @unlink($out);
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FILE => $fp,
            ]);
            $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);
            fclose($fp);
            if (!$ok || !is_file($out) || filesize($out) < 1000) {
                @unlink($out);
                return null;
            }
            return $out;
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => 120],
            'ssl' => ['verify_peer' => true],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) < 1000) {
            return null;
        }
        if (file_put_contents($out, $data) === false) {
            return null;
        }
        return $out;
    }

    private function copyMissingDllsFromZip(string $zipPath, string $extDir): int
    {
        if (!class_exists('ZipArchive')) {
            return 0;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return 0;
        }

        $copied = 0;
        $prefix = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $name = str_replace('\\', '/', $name);
            if (!preg_match('#(?:^|/)(php_[a-z0-9_]+\.dll)$#i', $name, $m)) {
                continue;
            }
            $dllName = $m[1];
            $dest = $extDir . '/' . $dllName;
            if (is_file($dest)) {
                continue;
            }
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                continue;
            }
            if (file_put_contents($dest, $data) !== false) {
                $copied++;
            }
        }
        $zip->close();
        return $copied;
    }
}
