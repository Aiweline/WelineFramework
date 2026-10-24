<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;

use function __;

/**
 * 浏览器扩展下载包：**不进 Git**，按请求现场打包。
 *
 * - `zip()`：把 `extension/` 打成 zip（含 wasm/vendor），带 ETag 与 mtime 失效缓存到 var/
 * - `installer()`：macOS 一键安装包 = `extension-installer-mac/` 里的 .app + 现打的 extension.zip
 *
 * 只读固定目录，不接受任何外部路径参数。
 */
class ExtensionPackage extends FrontendController
{
    private const CACHE_DIR = 'var/ai_acquisition_extension';

    /**
     * GET /ai_acquisition/frontend/extension-package/zip
     */
    public function getZip(): void
    {
        $this->sendArchive($this->buildExtensionZip(), 'ai-acquisition-extension.zip');
    }

    /**
     * GET /ai_acquisition/frontend/extension-package/installer
     */
    public function getInstaller(): void
    {
        $this->sendArchive($this->buildInstallerZip(), 'weline-extension-installer-mac.zip');
    }

    private function moduleDir(): string
    {
        return \rtrim(\dirname(__DIR__, 2), '/\\');
    }

    private function cacheDir(): string
    {
        $dir = \rtrim(\defined('BP') ? BP : $this->moduleDir(), '/\\') . '/' . self::CACHE_DIR;
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * 源目录最新修改时间：用于缓存失效。
     */
    private function latestMtime(string $dir): int
    {
        $latest = 0;
        if (!\is_dir($dir)) {
            return $latest;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if ($name === '.DS_Store' || $name === 'extension.zip') {
                continue;
            }
            $latest = \max($latest, (int)$file->getMTime());
        }

        return $latest;
    }

    private function buildExtensionZip(): string
    {
        $source = $this->moduleDir() . '/extension';
        if (!\is_dir($source)) {
            throw new \RuntimeException((string)__('扩展目录不存在'), 404);
        }

        $target = $this->cacheDir() . '/ai-acquisition-extension.zip';
        $stamp = $this->latestMtime($source);
        if (\is_file($target) && (int)\filemtime($target) >= $stamp) {
            return $target;
        }

        $tmp = $target . '.tmp';
        @\unlink($tmp);
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException((string)__('无法创建扩展压缩包'), 500);
        }
        $this->addDirectory($zip, $source, 'extension');
        $zip->close();

        @\chmod($tmp, 0644);
        if (!@\rename($tmp, $target)) {
            @\unlink($tmp);
            throw new \RuntimeException((string)__('扩展压缩包落盘失败'), 500);
        }

        return $target;
    }

    private function buildInstallerZip(): string
    {
        $appSource = $this->moduleDir() . '/extension-installer-mac/WelineExtensionInstaller.app';
        if (!\is_dir($appSource)) {
            throw new \RuntimeException((string)__('macOS 安装器目录不存在'), 404);
        }

        $extensionZip = $this->buildExtensionZip();
        $target = $this->cacheDir() . '/weline-extension-installer-mac.zip';
        $stamp = \max(
            $this->latestMtime($appSource),
            (int)\filemtime($extensionZip)
        );
        if (\is_file($target) && (int)\filemtime($target) >= $stamp) {
            return $target;
        }

        $tmp = $target . '.tmp';
        @\unlink($tmp);
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException((string)__('无法创建安装器压缩包'), 500);
        }
        $this->addDirectory($zip, $appSource, 'WelineExtensionInstaller.app');
        $zip->addFile($extensionZip, 'WelineExtensionInstaller.app/Contents/Resources/extension.zip');
        $zip->close();

        @\chmod($tmp, 0644);
        if (!@\rename($tmp, $target)) {
            @\unlink($tmp);
            throw new \RuntimeException((string)__('安装器压缩包落盘失败'), 500);
        }

        return $target;
    }

    private function addDirectory(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $base = \rtrim($dir, '/\\');
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $path = $file->getPathname();
            $name = $file->getFilename();
            if ($name === '.DS_Store') {
                continue;
            }
            $relative = \str_replace('\\', '/', \substr($path, \strlen($base) + 1));
            $local = $prefix . '/' . $relative;
            if ($file->isDir()) {
                $zip->addEmptyDir($local);
                continue;
            }
            $zip->addFile($path, $local);
            if (\is_executable($path)) {
                $zip->setExternalAttributesName(
                    $local,
                    \ZipArchive::OPSYS_UNIX,
                    (0o100755 << 16)
                );
            }
        }
    }

    private function sendArchive(string $path, string $downloadName): void
    {
        if (!\is_file($path)) {
            throw new \RuntimeException((string)__('压缩包不存在'), 404);
        }
        $size = (int)\filesize($path);
        $etag = '"' . \md5($path . '|' . $size . '|' . (int)\filemtime($path)) . '"';

        \header('Content-Type: application/zip');
        \header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        \header('Content-Length: ' . $size);
        \header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        \header('ETag: ' . $etag);
        \header('X-Content-Type-Options: nosniff');

        \readfile($path);
        exit;
    }
}
