<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\MediaManager\Helper\MimeTypes;
use Weline\Storage\Api\StorageCatalogInterface;

class ConnectorService
{
    private ?ThumbnailService $thumbnailService = null;
    private ?StorageCatalogInterface $storageCatalog = null;
    
    private function getThumbnailService(): ThumbnailService
    {
        if ($this->thumbnailService === null) {
            $this->thumbnailService = ObjectManager::getInstance(ThumbnailService::class);
        }
        return $this->thumbnailService;
    }
    
    private function getStorageManager(): ?StorageCatalogInterface
    {
        if ($this->storageCatalog === null) {
            if (\interface_exists(StorageCatalogInterface::class)) {
                $this->storageCatalog = ObjectManager::getInstance(StorageCatalogInterface::class);
            }
        }
        return $this->storageCatalog;
    }

    /**
     * 文件管理引擎：解析请求并执行 open/mkdir/rename/rm/upload/file 等命令
     */
    public function execute(Request $request, array $opts): array
    {
        $rootPath = $this->normalizeRootPath($opts);
        if (!\is_dir($rootPath) && !@\mkdir($rootPath, 0755, true)) {
            return ['error' => 'Invalid path'];
        }

        $rootReal = \realpath($rootPath);
        if ($rootReal === false) {
            return ['error' => 'Invalid path'];
        }
        $rootReal = $this->normalizeAbsolutePath($rootReal);

        $src = $this->parseSource($request);
        $cmd = $src['cmd'] ?? 'open';

        // multipart 上传时部分环境（WLS/反向代理/PHP 解析差异）下 $_GET/$_POST 可能无 target，导致上传到根目录；多源兜底
        if (\strtoupper($request->getMethod()) === 'POST' && !empty($_FILES['upload'])) {
            $cmd = 'upload';
            $target = \trim((string) (
                $src['target']
                ?? $_GET['target']
                ?? $_POST['target']
                ?? $request->getParam('target')
                ?? $request->getQuery('target')
                ?? ''
            ));
            if ($target === '') {
                $qs = $request->getServer('QUERY_STRING');
                if (\is_string($qs) && $qs !== '') {
                    \parse_str($qs, $parsed);
                    $target = \trim((string) ($parsed['target'] ?? ''));
                }
                if ($target === '') {
                    $uri = $request->getServer('REQUEST_URI');
                    if (\is_string($uri) && \str_contains($uri, '?')) {
                        $qsFromUri = \substr($uri, (int) \strpos($uri, '?') + 1);
                        \parse_str($qsFromUri, $parsedUri);
                        $target = \trim((string) ($parsedUri['target'] ?? ''));
                    }
                }
            }
            $src['target'] = $target;
        }

        return match ($cmd) {
            'open'    => $this->handleOpen($src, $rootPath, $rootReal),
            'tree'    => $this->handleTree($src, $rootPath, $rootReal),
            'mkdir'   => $this->handleMkdir($src, $rootPath, $rootReal),
            'rename'  => $this->handleRename($src, $rootPath, $rootReal),
            'rm'      => $this->handleRemove($src, $rootPath, $rootReal),
            'upload'  => $this->handleUpload($src, $rootPath, $rootReal),
            'file'    => $this->handleFile($src, $rootPath, $rootReal),
            'tmb'     => $this->handleTmb($src, $rootPath, $rootReal),
            'storages' => $this->handleStorages(),
            default   => ['error' => 'Unknown command: ' . $cmd],
        };
    }
    
    /**
     * 获取可用存储源列表
     */
    private function handleStorages(): array
    {
        $storages = [
            [
                'name' => 'local',
                'display_name' => __('本地存储'),
                'driver' => 'local',
                'is_default' => true,
            ],
        ];
        
        $storageManager = $this->getStorageManager();
        if ($storageManager !== null) {
            try {
                $list = $storageManager->all();
                foreach ($list as $item) {
                    $storages[] = [
                        'name' => $item['name'],
                        'display_name' => $item['info']['display_name'] ?? $item['name'],
                        'driver' => $item['driver'],
                        'is_default' => $item['is_default'] ?? false,
                    ];
                }
            } catch (\Throwable $e) {
            }
        }
        
        return ['storages' => $storages];
    }

    private function normalizeRootPath(array $opts): string
    {
        // 兼容 OptionsBuilder 的 roots[0].path，也支持简单的 rootPath 配置
        $path = $opts['rootPath']
            ?? ($opts['roots'][0]['path'] ?? (PUB . 'media' . \DIRECTORY_SEPARATOR));

        return \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path), \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
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
        return \trim(\str_replace('\\', '/', $decoded), '/');
    }

    /**
     * 解析 target hash 为安全路径
     */
    private function resolvePath(string $hash, string $rootPath, string $rootReal): array
    {
        $relative = $hash === '' ? '' : ($this->decodeHash($hash) ?? '');
        $relative = $this->normalizeRelativePath($relative);
        $abs = $this->joinRootPath($rootPath, $relative);
        $real = \realpath($abs);
        $checked = $real !== false ? $this->normalizeAbsolutePath($real) : $this->assertWriteTargetInRoot($abs, $rootReal);

        if (!$this->isPathInsideRoot($checked, $rootReal)) {
            throw new \RuntimeException('Invalid path');
        }

        return [$relative, $real !== false ? $checked : $abs];
    }

    private function buildFileInfo(string $relative, string $rootPath, string $rootReal): array
    {
        $relative = \trim(str_replace('\\', '/', $relative), '/');
        $abs = $rootPath . ($relative === '' ? '' : $relative);
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
        $ts   = @\filemtime($abs) ?: \time();

        $info = [
            'hash'  => $hash,
            'name'  => $name,
            'phash' => $phash,
            'mime'  => $mime,
            'size'  => $size,
            'ts'    => $ts,
            'path'  => $relative,
        ];
        
        if (!$isDir && $this->getThumbnailService()->isPreviewable($mime)) {
            $info['tmb'] = '1';
        }
        
        return $info;
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

    /**
     * 构建目录树：只加载指定目录的直接子目录（懒加载，不递归）
     * 同时加载当前路径上的所有父目录及其同级目录
     */
    private function buildDirectoryTree(string $currentRelative, string $rootPath, string $rootReal): array
    {
        $tree = [];
        $visited = [];
        
        // 1. 添加根目录
        $rootInfo = $this->buildFileInfo('', $rootPath, $rootReal);
        $rootInfo['dirs'] = $this->hasSubDirs('', $rootPath) ? 1 : 0;
        $tree[] = $rootInfo;
        $visited[''] = true;
        
        // 2. 加载根目录的直接子目录
        $rootDirs = $this->getDirectChildren('', $rootPath, $rootReal, true);
        foreach ($rootDirs as $dir) {
            if (!isset($visited[$dir['_rel']])) {
                $tree[] = $dir;
                $visited[$dir['_rel']] = true;
            }
        }
        
        // 3. 如果有当前路径，加载路径上每一级的同级目录
        if ($currentRelative !== '') {
            $parts = \explode('/', \str_replace('\\', '/', $currentRelative));
            $pathSoFar = '';
            
            foreach ($parts as $part) {
                $pathSoFar = $pathSoFar === '' ? $part : $pathSoFar . '/' . $part;
                
                // 确保当前路径在树中
                if (!isset($visited[$pathSoFar])) {
                    $info = $this->buildFileInfo($pathSoFar, $rootPath, $rootReal);
                    $info['dirs'] = $this->hasSubDirs($pathSoFar, $rootPath) ? 1 : 0;
                    $tree[] = $info;
                    $visited[$pathSoFar] = true;
                }
                
                // 加载当前路径的直接子目录
                $childDirs = $this->getDirectChildren($pathSoFar, $rootPath, $rootReal, true);
                foreach ($childDirs as $dir) {
                    if (!isset($visited[$dir['_rel']])) {
                        $tree[] = $dir;
                        $visited[$dir['_rel']] = true;
                    }
                }
            }
        }
        
        // 移除临时字段
        foreach ($tree as &$item) {
            unset($item['_rel']);
        }
        
        return $tree;
    }

    /**
     * 获取目录的直接子目录（只返回目录，不递归）
     */
    private function getDirectChildren(string $relative, string $rootPath, string $rootReal, bool $onlyDirs = false): array
    {
        $result = [];
        $abs = $rootPath . ($relative === '' ? '' : $relative);
        $entries = @\scandir($abs) ?: [];
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            $childRel = \trim(($relative === '' ? '' : $relative . '/') . $entry, '/');
            $childAbs = $rootPath . $childRel;
            
            if ($onlyDirs && !\is_dir($childAbs)) {
                continue;
            }
            
            $info = $this->buildFileInfo($childRel, $rootPath, $rootReal);
            $info['_rel'] = $childRel;
            
            // 标记是否有子目录（用于前端显示展开箭头）
            if (\is_dir($childAbs)) {
                $info['dirs'] = $this->hasSubDirs($childRel, $rootPath) ? 1 : 0;
            }
            
            $result[] = $info;
        }
        
        return $result;
    }

    /**
     * 检查目录是否有子目录
     */
    private function hasSubDirs(string $relative, string $rootPath): bool
    {
        $abs = $rootPath . ($relative === '' ? '' : $relative);
        $entries = @\scandir($abs) ?: [];
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }
            if (\is_dir($abs . \DIRECTORY_SEPARATOR . $entry)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 懒加载：获取指定目录的直接子目录
     */
    private function handleTree(array $src, string $rootPath, string $rootReal): array
    {
        $targetHash = $src['target'] ?? '';
        
        if ($targetHash === '') {
            return ['error' => 'Target required'];
        }
        
        try {
            [$relative, $abs] = $this->resolvePath($targetHash, $rootPath, $rootReal);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
        
        if (!\is_dir($abs)) {
            return ['error' => 'Directory not found'];
        }
        
        // 只获取直接子目录
        $tree = $this->getDirectChildren($relative, $rootPath, $rootReal, true);
        
        // 移除临时字段
        foreach ($tree as &$item) {
            unset($item['_rel']);
        }
        
        return ['tree' => $tree];
    }

    private function handleOpen(array $src, string $rootPath, string $rootReal): array
    {
        $targetHash = $src['target'] ?? '';
        $pathParam = $src['path'] ?? '';
        
        // 如果指定了 path 参数（初始路径），优先使用它
        if ($pathParam !== '' && $targetHash === '') {
            try {
                $pathParam = $this->normalizeRelativePath((string) $pathParam);
                $abs = $this->joinRootPath($rootPath, $pathParam);
                $this->assertWriteTargetInRoot($abs, $rootReal);
            } catch (\Throwable) {
                $pathParam = '';
                $abs = $rootPath;
            }
            
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

        // 构建目录树：只加载根目录的直接子目录，以及当前路径上的父目录
        $tree = $this->buildDirectoryTree($relative, $rootPath, $rootReal);
        
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
        $name   = $this->sanitizeLeafName((string) ($src['name'] ?? ''));
        if ($name === null) {
            return ['error' => 'Folder name is required'];
        }

        [$relative, $abs] = $this->resolvePath($target, $rootPath, $rootReal);
        $dirRel = \trim(($relative === '' ? '' : $relative . '/') . $name, '/');
        $dirAbs = $this->joinRootPath($rootPath, $dirRel);
        try {
            $this->assertWriteTargetInRoot($dirAbs, $rootReal);
        } catch (\Throwable) {
            return ['error' => 'Invalid path'];
        }

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
        $name   = $this->sanitizeLeafName((string) ($src['name'] ?? ''));
        if ($name === null) {
            return ['error' => 'New name is required'];
        }

        [$relative, $abs] = $this->resolvePath($target, $rootPath, $rootReal);
        if ($relative === '') {
            return ['error' => 'Cannot rename root'];
        }

        $dirRel = \trim(\dirname($relative), '/.');
        $newRel = \trim(($dirRel === '' ? '' : $dirRel . '/') . $name, '/');
        $newAbs = $this->joinRootPath($rootPath, $newRel);
        try {
            $this->assertWriteTargetInRoot($newAbs, $rootReal);
        } catch (\Throwable) {
            return ['error' => 'Invalid path'];
        }

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
                $error = $files['error'][$idx] ?? \UPLOAD_ERR_NO_FILE;
                if ($tmp === '' || $error !== \UPLOAD_ERR_OK || !\file_exists($tmp)) {
                    continue;
                }
                $cleanName = $this->sanitizeLeafName((string) $name);
                if ($cleanName === null) {
                    continue;
                }
                $rel = \trim(($relative === '' ? '' : $relative . '/') . $cleanName, '/');
                $rel = \str_replace('\\', '/', $rel);
                $dest = $rootPath . \str_replace('/', \DIRECTORY_SEPARATOR, $rel);
                if ($this->ensureParentDir($dest, $rootReal) && $this->moveUploadedFile($tmp, $dest)) {
                    $added[] = $this->buildFileInfo($rel, $rootPath, $rootReal);
                }
            }
        } else {
            $tmp = $files['tmp_name'] ?? '';
            $name = $files['name'] ?? '';
            $error = $files['error'] ?? \UPLOAD_ERR_NO_FILE;
            if ($tmp !== '' && $error === \UPLOAD_ERR_OK && \file_exists($tmp)) {
                $cleanName = $this->sanitizeLeafName((string) $name);
                if ($cleanName === null) {
                    return ['error' => 'Upload failed'];
                }
                $rel = \trim(($relative === '' ? '' : $relative . '/') . $cleanName, '/');
                $rel = \str_replace('\\', '/', $rel);
                $dest = $rootPath . \str_replace('/', \DIRECTORY_SEPARATOR, $rel);
                if ($this->ensureParentDir($dest, $rootReal) && $this->moveUploadedFile($tmp, $dest)) {
                    $added[] = $this->buildFileInfo($rel, $rootPath, $rootReal);
                }
            }
        }

        if (!$added) {
            return ['error' => 'Upload failed'];
        }

        return ['added' => $added];
    }

    /** 确保目标文件的父目录存在（Linux 下路径一致） */
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

    private function normalizeRelativePath(string $relative): string
    {
        $relative = \trim(\str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return '';
        }
        if (\preg_match('/[\x00-\x1F\x7F]/', $relative)) {
            throw new \RuntimeException('Invalid path');
        }

        $segments = [];
        foreach (\explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('Invalid path');
            }
            $segments[] = $segment;
        }

        return \implode('/', $segments);
    }

    private function sanitizeLeafName(string $name): ?string
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

    private function joinRootPath(string $rootPath, string $relative): string
    {
        return \rtrim($rootPath, DIRECTORY_SEPARATOR) . ($relative === '' ? '' : DIRECTORY_SEPARATOR . \str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }

    private function assertWriteTargetInRoot(string $targetPath, string $rootReal): string
    {
        $real = \realpath($targetPath);
        if ($real !== false) {
            $real = $this->normalizeAbsolutePath($real);
            if (!$this->isPathInsideRoot($real, $rootReal)) {
                throw new \RuntimeException('Invalid path');
            }
            return $real;
        }

        $parent = \dirname($targetPath);
        while (!\file_exists($parent)) {
            $next = \dirname($parent);
            if ($next === $parent) {
                throw new \RuntimeException('Invalid path');
            }
            $parent = $next;
        }

        $parentReal = \realpath($parent);
        if ($parentReal === false) {
            throw new \RuntimeException('Invalid path');
        }
        $parentReal = $this->normalizeAbsolutePath($parentReal);
        if (!$this->isPathInsideRoot($parentReal, $rootReal)) {
            throw new \RuntimeException('Invalid path');
        }

        return $this->normalizeAbsolutePath($targetPath);
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        while (\str_contains($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR)) {
            $path = \str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path);
        }
        return \rtrim($path, DIRECTORY_SEPARATOR);
    }

    private function isPathInsideRoot(string $path, string $rootReal): bool
    {
        $path = $this->normalizeAbsolutePath($path);
        $root = $this->normalizeAbsolutePath($rootReal);
        if (IS_WIN) {
            $path = \strtolower($path);
            $root = \strtolower($root);
        }

        return $path === $root || \str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * WLS 兼容的文件移动方法
     *
     * 在 WLS 环境下，is_uploaded_file() 和 move_uploaded_file() 不能正常工作，
     * 因为文件是通过手动解析 multipart 数据写入的临时文件，PHP 不认为它是"上传"文件。
     * 此方法首先尝试标准 move_uploaded_file()，失败则回退到 rename/copy。
     */
    private function moveUploadedFile(string $tmpFile, string $destination): bool
    {
        if (@\is_uploaded_file($tmpFile) && @\move_uploaded_file($tmpFile, $destination)) {
            return true;
        }

        if (@\rename($tmpFile, $destination)) {
            return true;
        }

        if (@\copy($tmpFile, $destination)) {
            @\unlink($tmpFile);
            return true;
        }

        return false;
    }

    /**
     * tmb 命令：返回缩略图（使用 ThumbnailService 按需生成）
     */
    private function handleTmb(array $src, string $rootPath, string $rootReal): array
    {
        $target = (string) ($src['target'] ?? '');
        
        try {
            [$relative, $abs] = $this->resolvePath($target, $rootPath, $rootReal);
        } catch (\Throwable $e) {
            return ['error' => 'Invalid target'];
        }
        
        if (!\is_file($abs)) {
            return ['error' => 'File not found'];
        }

        $mime = $this->detectMime($abs);
        $thumbService = $this->getThumbnailService();
        
        if (!$thumbService->isPreviewable($mime)) {
            return ['error' => 'Not a previewable file'];
        }

        $thumbPath = $thumbService->getOrGenerate($abs);
        if ($thumbPath === null) {
            $fp = @\fopen($abs, 'rb');
            if ($fp === false) {
                return ['error' => 'Cannot open file'];
            }
            $thumbMime = $mime;
            $thumbSize = @\filesize($abs) ?: 0;
        } else {
            $fp = @\fopen($thumbPath, 'rb');
            if ($fp === false) {
                return ['error' => 'Cannot open thumbnail'];
            }
            $thumbMime = \str_ends_with($thumbPath, '.webp') ? 'image/webp' : 'image/jpeg';
            $thumbSize = @\filesize($thumbPath) ?: 0;
        }

        $info = $this->buildFileInfo($relative, $rootPath, $rootReal);
        $info['size'] = $thumbSize;

        return [
            'pointer' => $fp,
            'info'    => $info,
            'header'  => [
                'Content-Type: ' . $thumbMime,
                'Cache-Control: public, max-age=604800',
            ],
        ];
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
