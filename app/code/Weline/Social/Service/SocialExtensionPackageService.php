<?php

declare(strict_types=1);

namespace Weline\Social\Service;

/**
 * 社媒扩展包（ZIP）按需打包。
 *
 * 源：模块内 `extension/`（随代码部署）。
 * 产物：`view/statics/icons/social-extension.zip`（发布位，供 @static 下载；不进 Git）。
 * 策略：文件不存在或源比产物新时重新打包，避免提交二进制产物。
 */
class SocialExtensionPackageService
{
    public const FILE_NAME = 'social-extension.zip';

    public function sourceDir(): string
    {
        return \dirname(__DIR__) . '/extension';
    }

    public function targetFile(): string
    {
        return \dirname(__DIR__) . '/view/statics/icons/' . self::FILE_NAME;
    }

    /**
     * 确保 ZIP 存在且比源新；返回产物绝对路径（失败返回空串）。
     */
    public function ensurePackage(): string
    {
        $source = $this->sourceDir();
        $target = $this->targetFile();
        if (!\is_dir($source)) {
            return '';
        }
        if (\is_file($target) && \filemtime($target) >= $this->sourceMtime($source)) {
            return $target;
        }
        $dir = \dirname($target);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return '';
        }
        if (!\class_exists(\ZipArchive::class)) {
            return \is_file($target) ? $target : '';
        }
        $tmp = $target . '.tmp-' . \bin2hex(\random_bytes(4));
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return \is_file($target) ? $target : '';
        }
        $this->addDir($zip, $source, '');
        $zip->close();
        if (!@\rename($tmp, $target)) {
            @\unlink($tmp);
            return \is_file($target) ? $target : '';
        }
        @\chmod($target, 0644);
        \clearstatcache(true, $target);

        return $target;
    }

    private function sourceMtime(string $dir): int
    {
        $max = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $max = \max($max, (int)$file->getMTime());
            }
        }

        return $max;
    }

    private function addDir(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $items = \scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            $local = $prefix === '' ? $item : $prefix . '/' . $item;
            if (\is_dir($path)) {
                $zip->addEmptyDir($local);
                $this->addDir($zip, $path, $local);
                continue;
            }
            if (\is_file($path)) {
                $zip->addFile($path, $local);
            }
        }
    }
}
