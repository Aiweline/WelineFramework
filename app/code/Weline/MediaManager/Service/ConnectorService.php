<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Framework\Http\Request;
use Weline\MediaManager\Helper\MimeTypes;

class ConnectorService
{
    /**
     * 文件管理引擎：解析请求并执行 open/mkdir/rename/rm/upload/file 等命令
     */
    public function execute(Request $request, array $opts): array
    {
        $rootPath = $this->normalizeRootPath($opts);
        $rootReal = \realpath($rootPath) ?: $rootPath;

        $src = $this->parseSource($request);
        $cmd = $src['cmd'] ?? 'open';

        return match ($cmd) {
            'open'   => $this->handleOpen($src, $rootPath, $rootReal),
            'mkdir'  => $this->handleMkdir($src, $rootPath, $rootReal),
            'rename' => $this->handleRename($src, $rootPath, $rootReal),
            'rm'     => $this->handleRemove($src, $rootPath, $rootReal),
            'upload' => $this->handleUpload($src, $rootPath, $rootReal),
            'file'   => $this->handleFile($src, $rootPath, $rootReal),
            default  => ['error' => 'Unknown command: ' . $cmd],
        };
    }

    private function normalizeRootPath(array $opts): string
    {
        // 兼容 OptionsBuilder 的 roots[0].path，也支持简单的 rootPath 配置
        $path = $opts['rootPath']
            ?? ($opts['roots'][0]['path'] ?? (PUB . 'media' . \DIRECTORY_SEPARATOR));

        return \rtrim($path, "/\\") . \DIRECTORY_SEPARATOR;
    }

    private function encodeHash(string $relativePath): string
    {
        // 统一使用正斜杠，根目录用空字符串表示
        $relativePath = \trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            $relativePath = '/';
        }
        $b64 = \rtrim(\strtr(\base64_encode($relativePath), '+/', '-_'), '=');
        return 'mm_' . $b64;
    }

    private function decodeHash(string $hash): ?string
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
        return \trim(str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $decoded), \DIRECTORY_SEPARATOR);
    }

    /**
     * 解析 target hash 为安全路径
     */
    private function resolvePath(string $hash, string $rootPath, string $rootReal): array
    {
        $relative = $hash === '' ? '' : ($this->decodeHash($hash) ?? '');
        $relative = \trim($relative, "/\\");
        $abs = $rootPath . ($relative === '' ? '' : $relative . \DIRECTORY_SEPARATOR);
        $real = \realpath($abs) ?: $abs;

        if (!\str_starts_with($real, $rootReal)) {
            throw new \RuntimeException('Invalid path');
        }

        return [$relative, $real];
    }

    private function buildFileInfo(string $relative, string $rootPath, string $rootReal): array
    {
        $relative = \trim(str_replace('\\', '/', $relative), '/');
        $abs = $rootPath . ($relative === '' ? '' : $relative);
        $isDir = \is_dir($abs);

        $name = $relative === '' ? 'Media Files' : \basename($relative);
        $hash = $this->encodeHash($relative);

        $dirRel = $relative === '' ? '' : \trim(\dirname($relative), '/.');
        $phash = $dirRel === '' ? null : $this->encodeHash($dirRel);

        $mime = $isDir ? 'directory' : $this->detectMime($abs);
        $size = $isDir ? 0 : ((@\filesize($abs)) ?: 0);
        $ts   = @\filemtime($abs) ?: \time();

        return [
            'hash'  => $hash,
            'name'  => $name,
            'phash' => $phash,
            'mime'  => $mime,
            'size'  => $size,
            'ts'    => $ts,
        ];
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

    private function handleOpen(array $src, string $rootPath, string $rootReal): array
    {
        $targetHash = $src['target'] ?? '';
        $pathParam = $src['path'] ?? '';
        
        // 如果指定了 path 参数（初始路径），优先使用它
        if ($pathParam !== '' && $targetHash === '') {
            $pathParam = \trim(str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $pathParam), \DIRECTORY_SEPARATOR);
            $abs = $rootPath . $pathParam;
            
            // 如果目录不存在，尝试创建
            if (!\is_dir($abs)) {
                @\mkdir($abs, 0755, true);
            }
            
            if (\is_dir($abs)) {
                $relative = $pathParam;
            } else {
                $relative = '';
                $abs = $rootPath;
            }
        } else {
            [$relative, $abs] = $this->resolvePath((string) $targetHash, $rootPath, $rootReal);

            if (!\is_dir($abs)) {
                $abs = $rootPath;
                $relative = '';
            }
        }

        $cwd = $this->buildFileInfo($relative, $rootPath, $rootReal);

        // 列出当前目录下的文件与子目录
        $files = [];
        $entries = @\scandir($abs) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $childRel = \trim(($relative === '' ? '' : $relative . '/') . $entry, '/');
            $childAbs = $rootPath . $childRel;
            $files[] = $this->buildFileInfo($childRel, $rootPath, $rootReal);
        }

        // 简单的树结构：当前目录 + 根目录，以及路径上的所有父目录
        $tree = [];
        $tree[] = $this->buildFileInfo('', $rootPath, $rootReal);
        
        // 添加从根到当前目录的路径
        if ($relative !== '') {
            $parts = \explode(\DIRECTORY_SEPARATOR, $relative);
            $currentPath = '';
            foreach ($parts as $part) {
                $currentPath = $currentPath === '' ? $part : $currentPath . \DIRECTORY_SEPARATOR . $part;
                $tree[] = $this->buildFileInfo($currentPath, $rootPath, $rootReal);
            }
        }
        
        // 返回根目录的 hash，用于前端判断锁定范围
        $rootHash = $this->encodeHash('');

        return [
            'cwd'   => $cwd,
            'files' => $files,
            'tree'  => $tree,
            'root'  => $rootHash,
        ];
    }

    private function handleMkdir(array $src, string $rootPath, string $rootReal): array
    {
        $target = (string) ($src['target'] ?? '');
        $name   = \trim((string) ($src['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'Folder name is required'];
        }

        [$relative, $abs] = $this->resolvePath($target, $rootPath, $rootReal);
        $dirRel = \trim(($relative === '' ? '' : $relative . '/') . $name, '/');
        $dirAbs = $rootPath . $dirRel;

        if (\is_dir($dirAbs)) {
            return ['error' => 'Folder already exists'];
        }

        if (!@\mkdir($dirAbs, 0755, true)) {
            return ['error' => 'Failed to create folder'];
        }

        return ['added' => [$this->buildFileInfo($dirRel, $rootPath, $rootReal)]];
    }

    private function handleRename(array $src, string $rootPath, string $rootReal): array
    {
        $target = (string) ($src['target'] ?? '');
        $name   = \trim((string) ($src['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'New name is required'];
        }

        [$relative, $abs] = $this->resolvePath($target, $rootPath, $rootReal);
        if ($relative === '') {
            return ['error' => 'Cannot rename root'];
        }

        $dirRel = \trim(\dirname($relative), '/.');
        $newRel = \trim(($dirRel === '' ? '' : $dirRel . '/') . $name, '/');
        $newAbs = $rootPath . $newRel;

        if (!@\rename($abs, $newAbs)) {
            return ['error' => 'Failed to rename'];
        }

        return ['added' => [$this->buildFileInfo($newRel, $rootPath, $rootReal)]];
    }

    private function handleRemove(array $src, string $rootPath, string $rootReal): array
    {
        $targets = $src['targets'] ?? [];
        if (!\is_array($targets) || !$targets) {
            return ['error' => 'No targets'];
        }

        foreach ($targets as $hash) {
            [$relative, $abs] = $this->resolvePath((string) $hash, $rootPath, $rootReal);
            if ($relative === '') {
                // 不允许删除根目录
                continue;
            }
            $this->deleteRecursive($abs);
        }

        return ['removed' => (array) $targets];
    }

    private function deleteRecursive(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);
            return;
        }
        $items = @\scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteRecursive($path . \DIRECTORY_SEPARATOR . $item);
        }
        @\rmdir($path);
    }

    private function handleUpload(array $src, string $rootPath, string $rootReal): array
    {
        $target = (string) ($src['target'] ?? '');
        [$relative, $abs] = $this->resolvePath($target, $rootPath, $rootReal);
        if (!\is_dir($abs)) {
            return ['error' => 'Target directory not found'];
        }

        $files = $_FILES['upload'] ?? null;
        if ($files === null) {
            return ['error' => 'No files uploaded'];
        }

        $added = [];
        if (\is_array($files['name'])) {
            foreach ($files['name'] as $idx => $name) {
                $tmp = $files['tmp_name'][$idx] ?? '';
                if ($tmp === '' || !\is_uploaded_file($tmp)) {
                    continue;
                }
                $cleanName = \basename((string) $name);
                $rel = \trim(($relative === '' ? '' : $relative . '/') . $cleanName, '/');
                $dest = $rootPath . $rel;
                if (@\move_uploaded_file($tmp, $dest)) {
                    $added[] = $this->buildFileInfo($rel, $rootPath, $rootReal);
                }
            }
        } else {
            $tmp = $files['tmp_name'] ?? '';
            $name = $files['name'] ?? '';
            if ($tmp !== '' && \is_uploaded_file($tmp)) {
                $cleanName = \basename((string) $name);
                $rel = \trim(($relative === '' ? '' : $relative . '/') . $cleanName, '/');
                $dest = $rootPath . $rel;
                if (@\move_uploaded_file($tmp, $dest)) {
                    $added[] = $this->buildFileInfo($rel, $rootPath, $rootReal);
                }
            }
        }

        if (!$added) {
            return ['error' => 'Upload failed'];
        }

        return ['added' => $added];
    }

    /**
     * file/download 命令：返回 pointer，由 Controller 统一转为 DownloadException
     */
    private function handleFile(array $src, string $rootPath, string $rootReal): array
    {
        $target = (string) ($src['target'] ?? '');
        [$relative, $abs] = $this->resolvePath($target, $rootPath, $rootReal);
        if (!\is_file($abs)) {
            return ['error' => 'File not found'];
        }

        $fp = @\fopen($abs, 'rb');
        if ($fp === false) {
            return ['error' => 'Cannot open file'];
        }

        $mime = $this->detectMime($abs);
        $info = $this->buildFileInfo($relative, $rootPath, $rootReal);
        $info['size'] = @\filesize($abs) ?: 0;

        return [
            'pointer' => $fp,
            'info'    => $info,
            'header'  => [
                'Content-Type: ' . $mime,
            ],
        ];
    }

    private function parseSource(Request $request): array
    {
        $isPost = \strtoupper($request->getMethod()) === 'POST';
        $src = $isPost ? \array_merge($_GET, $_POST) : $_GET;

        $maxInputVars = (!$src || isset($src['targets'])) ? \ini_get('max_input_vars') : null;
        if ((!$src || $maxInputVars) && ($rawPostData = @\file_get_contents('php://input'))) {
            $parts = \explode('&', $rawPostData);
            if (!$src || (int) $maxInputVars < \count($parts)) {
                $src = [];
                foreach ($parts as $part) {
                    [$key, $value] = \array_pad(\explode('=', $part, 2), 2, '');
                    $key = \rawurldecode($key);
                    if (\preg_match('/^(.+?)\[([^\[\]]*)\]$/', $key, $m)) {
                        $key = $m[1];
                        $idx = $m[2];
                        if (!isset($src[$key])) {
                            $src[$key] = [];
                        }
                        if ($idx !== '') {
                            $src[$key][$idx] = \rawurldecode($value);
                        } else {
                            $src[$key][] = \rawurldecode($value);
                        }
                    } else {
                        $src[$key] = \rawurldecode($value);
                    }
                }
                $_POST = $this->inputFilter($src);
                $_REQUEST = $this->inputFilter(\array_merge_recursive($src, $_REQUEST));
            }
        }

        return $src;
    }

    private function inputFilter(mixed $args): mixed
    {
        if (\is_array($args)) {
            return \array_map([$this, 'inputFilter'], $args);
        }
        return \str_replace("\0", '', (string) $args);
    }
}
