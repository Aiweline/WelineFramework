<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\MediaManager\Helper\MimeTypes;

/**
 * 媒体根目录路径解析与写盘（供 AI 作图等能力复用 connector 安全契约）。
 */
class MediaStorageService
{
    public function getRootContext(): array
    {
        $rootPath = \rtrim(PUB, '/\\') . \DIRECTORY_SEPARATOR . 'media' . \DIRECTORY_SEPARATOR;
        if (!\is_dir($rootPath) && !@\mkdir($rootPath, 0755, true)) {
            throw new \RuntimeException(__('媒体根目录无效'));
        }
        $rootReal = \realpath($rootPath);
        if ($rootReal === false) {
            throw new \RuntimeException(__('媒体根目录无效'));
        }

        return [
            'root_path' => $this->normalizeRootPath($rootPath),
            'root_real' => $this->normalizeAbsolutePath($rootReal),
        ];
    }

    public function encodeHash(string $relativePath): string
    {
        $relativePath = \trim(\str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            $relativePath = '/';
        }
        $b64 = \rtrim(\strtr(\base64_encode($relativePath), '+/', '-_'), '=');

        return 'mm_' . $b64;
    }

    public function decodeHash(string $hash): ?string
    {
        if (!\str_starts_with($hash, 'mm_')) {
            return null;
        }
        $b64 = \substr($hash, 3);
        $b64 .= \str_repeat('=', (4 - \strlen($b64) % 4) % 4);
        $decoded = \base64_decode(\strtr($b64, '-_', '+/'));
        if ($decoded === false) {
            return null;
        }
        if ($decoded === '/') {
            return '';
        }

        return \trim(\str_replace('\\', '/', $decoded), '/');
    }

    /**
     * @return array{0:string,1:string,2:bool} relative, absolute, exists
     */
    public function resolveHash(string $hash): array
    {
        $ctx = $this->getRootContext();
        $relative = $hash === '' ? '' : ($this->decodeHash($hash) ?? '');
        $relative = $this->normalizeRelativePath($relative);
        $abs = $this->joinRootPath($ctx['root_path'], $relative);
        $real = \realpath($abs);
        if ($real === false) {
            $checked = $this->assertWriteTargetInRoot($abs, $ctx['root_real']);
            if (!$this->isPathInsideRoot($checked, $ctx['root_real'])) {
                throw new \RuntimeException(__('无效路径'));
            }

            return [$relative, $abs, false];
        }
        $checked = $this->normalizeAbsolutePath($real);
        if (!$this->isPathInsideRoot($checked, $ctx['root_real'])) {
            throw new \RuntimeException(__('无效路径'));
        }

        return [$relative, $checked, true];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildFileInfo(string $relative): array
    {
        $ctx = $this->getRootContext();
        $relative = \trim(\str_replace('\\', '/', $relative), '/');
        $abs = $this->joinRootPath($ctx['root_path'], $relative);
        $isDir = \is_dir($abs);
        $name = $relative === '' ? 'Media Files' : \basename($relative);
        $hash = $this->encodeHash($relative);
        if ($relative === '') {
            $phash = null;
        } else {
            $dirRel = \trim(\dirname(\str_replace('\\', '/', $relative)), '/.');
            $phash = $this->encodeHash($dirRel);
        }
        $mime = $isDir ? 'directory' : $this->detectMime($abs);
        $size = $isDir ? 0 : ((@\filesize($abs)) ?: 0);
        $ts = @\filemtime($abs) ?: \time();

        return [
            'hash' => $hash,
            'name' => $name,
            'phash' => $phash,
            'mime' => $mime,
            'size' => $size,
            'ts' => $ts,
            'path' => $relative,
        ];
    }

    public function readFileBytes(string $hash): array
    {
        [$relative, $abs, $exists] = $this->resolveHash($hash);
        if (!$exists || !\is_file($abs)) {
            throw new \RuntimeException(__('参考文件不存在'));
        }
        $bytes = @\file_get_contents($abs);
        if ($bytes === false) {
            throw new \RuntimeException(__('无法读取参考文件'));
        }
        $mime = $this->detectMime($abs);

        return [
            'relative' => $relative,
            'absolute' => $abs,
            'bytes' => $bytes,
            'mime' => $mime,
            'hash' => $hash,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function writeNewFile(string $directoryHash, string $filename, string $bytes): array
    {
        $filename = $this->sanitizeLeafName($filename);
        if ($filename === null) {
            throw new \RuntimeException(__('文件名无效'));
        }
        $ctx = $this->getRootContext();
        [$dirRelative, $dirAbs, $dirExists] = $this->resolveHash($directoryHash);
        if (!$dirExists || !\is_dir($dirAbs)) {
            throw new \RuntimeException(__('目标目录不存在'));
        }
        $rel = \trim(($dirRelative === '' ? '' : $dirRelative . '/') . $filename, '/');
        $dest = $this->joinRootPath($ctx['root_path'], $rel);
        $this->assertWriteTargetInRoot($dest, $ctx['root_real']);
        if (\file_exists($dest)) {
            throw new \RuntimeException(__('同名文件已存在，请更换文件名'));
        }
        if (!$this->ensureParentDir($dest, $ctx['root_real'])) {
            throw new \RuntimeException(__('无法写入目标目录'));
        }
        if (@\file_put_contents($dest, $bytes) === false) {
            throw new \RuntimeException(__('保存文件失败'));
        }

        return $this->buildFileInfo($rel);
    }

    /**
     * @return array<string,mixed>
     */
    public function overwriteFile(string $sourceFileHash, string $bytes, ?string $newFilename = null): array
    {
        [$relative, $abs, $exists] = $this->resolveHash($sourceFileHash);
        if (!$exists || !\is_file($abs)) {
            throw new \RuntimeException(__('源文件不存在'));
        }
        $mime = $this->detectMime($abs);
        if ($mime === 'image/svg+xml') {
            throw new \RuntimeException(__('不允许覆盖 SVG 矢量文件'));
        }
        $ctx = $this->getRootContext();
        $targetAbs = $abs;
        $targetRelative = $relative;
        if ($newFilename !== null && $newFilename !== '') {
            $clean = $this->sanitizeLeafName($newFilename);
            if ($clean === null) {
                throw new \RuntimeException(__('文件名无效'));
            }
            $dirRelative = \trim(\dirname(\str_replace('\\', '/', $relative)), '/.');
            if ($dirRelative === '.') {
                $dirRelative = '';
            }
            $targetRelative = \trim(($dirRelative === '' ? '' : $dirRelative . '/') . $clean, '/');
            $targetAbs = $this->joinRootPath($ctx['root_path'], $targetRelative);
            $this->assertWriteTargetInRoot($targetAbs, $ctx['root_real']);
        }
        if (@\file_put_contents($targetAbs, $bytes) === false) {
            throw new \RuntimeException(__('覆盖原图失败'));
        }
        if ($targetAbs !== $abs && \is_file($abs)) {
            @\unlink($abs);
        }

        return $this->buildFileInfo($targetRelative);
    }

    public function sanitizeLeafName(string $name): ?string
    {
        $name = \trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        if (\str_contains($name, '/') || \str_contains($name, '\\') || \preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return null;
        }
        if (\basename($name) !== $name) {
            return null;
        }

        return $name;
    }

    public function extensionForFormat(string $format): string
    {
        return match (\strtolower(\trim($format))) {
            'jpeg', 'jpg' => 'jpg',
            'webp' => 'webp',
            default => 'png',
        };
    }

    private function normalizeRootPath(string $path): string
    {
        return \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path), \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
    }

    private function normalizeRelativePath(string $relative): string
    {
        $relative = \trim(\str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return '';
        }
        if (\preg_match('/[\x00-\x1F\x7F]/', $relative)) {
            throw new \RuntimeException(__('无效路径'));
        }
        $segments = [];
        foreach (\explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException(__('无效路径'));
            }
            $segments[] = $segment;
        }

        return \implode('/', $segments);
    }

    private function joinRootPath(string $rootPath, string $relative): string
    {
        return \rtrim($rootPath, \DIRECTORY_SEPARATOR)
            . ($relative === '' ? '' : \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $relative));
    }

    private function ensureParentDir(string $filePath, string $rootReal): bool
    {
        try {
            $this->assertWriteTargetInRoot($filePath, $rootReal);
        } catch (\Throwable) {
            return false;
        }
        $dir = \dirname($filePath);

        return \is_dir($dir) || @\mkdir($dir, 0755, true);
    }

    private function assertWriteTargetInRoot(string $targetPath, string $rootReal): string
    {
        $real = \realpath($targetPath);
        if ($real !== false) {
            $real = $this->normalizeAbsolutePath($real);
            if (!$this->isPathInsideRoot($real, $rootReal)) {
                throw new \RuntimeException(__('无效路径'));
            }

            return $real;
        }
        $parent = \dirname($targetPath);
        while (!\file_exists($parent)) {
            $next = \dirname($parent);
            if ($next === $parent) {
                throw new \RuntimeException(__('无效路径'));
            }
            $parent = $next;
        }
        $parentReal = \realpath($parent);
        if ($parentReal === false) {
            throw new \RuntimeException(__('无效路径'));
        }
        $parentReal = $this->normalizeAbsolutePath($parentReal);
        if (!$this->isPathInsideRoot($parentReal, $rootReal)) {
            throw new \RuntimeException(__('无效路径'));
        }

        return $this->normalizeAbsolutePath($targetPath);
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
        while (\str_contains($path, \DIRECTORY_SEPARATOR . \DIRECTORY_SEPARATOR)) {
            $path = \str_replace(\DIRECTORY_SEPARATOR . \DIRECTORY_SEPARATOR, \DIRECTORY_SEPARATOR, $path);
        }

        return \rtrim($path, \DIRECTORY_SEPARATOR);
    }

    private function isPathInsideRoot(string $path, string $rootReal): bool
    {
        $path = $this->normalizeAbsolutePath($path);
        $root = $this->normalizeAbsolutePath($rootReal);
        if (\defined('IS_WIN') && IS_WIN) {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }

        return $path === $root || \str_starts_with($path, $root . \DIRECTORY_SEPARATOR);
    }

    private function detectMime(string $path): string
    {
        $ext = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));
        $mimes = $ext !== '' ? MimeTypes::getMimeTypes($ext) : [];
        if ($mimes) {
            return $mimes[0];
        }

        return 'application/octet-stream';
    }
}
