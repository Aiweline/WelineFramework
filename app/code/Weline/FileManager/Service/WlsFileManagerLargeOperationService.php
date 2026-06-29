<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

class WlsFileManagerLargeOperationService
{
    public const DEFAULT_MAX_ZIP_BYTES = 536870912;
    public const DEFAULT_MAX_ZIP_ENTRIES = 2000;
    public const DEFAULT_MAX_TRASH_BYTES = 536870912;
    public const DEFAULT_MAX_TRASH_ENTRIES = 2000;
    private const TRASH_DIRECTORY = '.wls-trash';

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, items: array<int, array{path: string, local_name: string, type: string}>}
     */
    public function createZipArchive(
        string $sourcePath,
        string $targetPath,
        string $rootPath,
        int $maxEntries = self::DEFAULT_MAX_ZIP_ENTRIES,
        int $maxBytes = self::DEFAULT_MAX_ZIP_BYTES
    ): array {
        if (!class_exists(\ZipArchive::class)) {
            return $this->compressResult(false, 'failed', 'zip_extension_missing');
        }

        $target = $this->resolveTargetPath($targetPath, $rootPath);
        if ($target['error_code'] !== '') {
            return $this->compressResult(false, 'denied', $target['error_code']);
        }

        $entries = $this->collectCompressEntries($sourcePath, $rootPath, $maxEntries, $maxBytes);
        if (!$entries['success']) {
            return $entries;
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($target['path'], \ZipArchive::CREATE | \ZipArchive::EXCL);
        if ($opened !== true) {
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        $sourceRealPath = realpath($sourcePath);
        $sourceBaseName = $sourceRealPath !== false ? basename($sourceRealPath) : 'archive';
        $writeOk = true;

        if ($entries['entries'] === 0 && is_dir($sourcePath)) {
            $writeOk = $zip->addEmptyDir($sourceBaseName);
        }

        foreach ($entries['items'] as $item) {
            $localName = (string)($item['local_name'] ?? '');
            $path = (string)($item['path'] ?? '');
            if ($localName === '' || $path === '') {
                $writeOk = false;
                break;
            }

            $writeOk = (string)($item['type'] ?? '') === 'directory'
                ? $zip->addEmptyDir($localName)
                : $zip->addFile($path, $localName);

            if (!$writeOk) {
                break;
            }
        }

        if (!$zip->close() || !$writeOk) {
            if (is_file($target['path'])) {
                @unlink($target['path']);
            }
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        return $this->compressResult(
            true,
            'success',
            '',
            (int)$entries['entries'],
            (int)$entries['bytes'],
            (array)$entries['items'],
            $target['path']
        );
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, items: array<int, array{path: string, local_name: string, type: string}>}
     */
    public function createSingleFileArchive(
        string $sourcePath,
        string $targetPath,
        string $sourceRootPath,
        string $archiveRootPath,
        string $sourceRelativePath,
        int $maxBytes
    ): array {
        if (!class_exists(\ZipArchive::class)) {
            return $this->compressResult(false, 'failed', 'zip_extension_missing');
        }

        $sourceRelativePath = $this->normalizeRelativePath($sourceRelativePath);
        if ($sourceRelativePath === '') {
            return $this->compressResult(false, 'denied', 'entry_not_found');
        }

        $sourceRootRealPath = realpath($sourceRootPath);
        $sourceRealPath = realpath($sourcePath);
        if (
            $sourceRootRealPath === false
            || $sourceRealPath === false
            || !$this->isCandidateWithinRoot($sourceRootRealPath, $sourceRealPath)
        ) {
            return $this->compressResult(false, 'denied', 'entry_not_found');
        }

        if (!is_file($sourceRealPath) || is_link($sourcePath) || is_link($sourceRealPath) || !is_readable($sourceRealPath)) {
            return $this->compressResult(false, 'denied', 'entry_not_readable');
        }

        $bytes = filesize($sourceRealPath);
        if ($bytes === false || $bytes > max(1, $maxBytes)) {
            return $this->compressResult(false, 'denied', 'compress_source_too_large');
        }

        $target = $this->resolveTargetPath($targetPath, $archiveRootPath);
        if ($target['error_code'] !== '') {
            return $this->compressResult(false, 'denied', $target['error_code']);
        }

        $localName = $this->safeArchiveLocalName($sourceRelativePath);
        if ($localName === '') {
            return $this->compressResult(false, 'denied', 'entry_not_found');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($target['path'], \ZipArchive::CREATE | \ZipArchive::EXCL);
        if ($opened !== true) {
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        $writeOk = $zip->addFile($sourceRealPath, $localName);
        if (!$zip->close() || !$writeOk) {
            if (is_file($target['path'])) {
                @unlink($target['path']);
            }
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        return $this->compressResult(true, 'success', '', 1, (int)$bytes, [[
            'path' => $sourceRealPath,
            'local_name' => $localName,
            'type' => 'file',
        ]], $target['path']);
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, items: array<int, array{path: string, local_name: string, type: string}>}
     */
    public function createSourceTreeArchive(
        string $sourcePath,
        string $targetPath,
        string $sourceRootPath,
        string $archiveRootPath,
        string $sourceRelativePath,
        int $maxEntries,
        int $maxBytes
    ): array {
        if (!class_exists(\ZipArchive::class)) {
            return $this->compressResult(false, 'failed', 'zip_extension_missing');
        }

        $sourceRelativePath = $this->normalizeRelativePath($sourceRelativePath);
        if ($sourceRelativePath === '') {
            return $this->compressResult(false, 'denied', 'entry_not_found');
        }

        $sourceRootRealPath = realpath($sourceRootPath);
        $sourceRealPath = realpath($sourcePath);
        if (
            $sourceRootRealPath === false
            || $sourceRealPath === false
            || !$this->isCandidateWithinRoot($sourceRootRealPath, $sourceRealPath)
        ) {
            return $this->compressResult(false, 'denied', 'entry_not_found');
        }

        if (!is_dir($sourceRealPath) || is_link($sourcePath) || is_link($sourceRealPath) || !is_readable($sourceRealPath)) {
            return $this->compressResult(false, 'denied', 'entry_not_readable');
        }

        $target = $this->resolveTargetPath($targetPath, $archiveRootPath);
        if ($target['error_code'] !== '') {
            return $this->compressResult(false, 'denied', $target['error_code']);
        }

        $localRootName = $this->safeArchiveLocalName($sourceRelativePath);
        if ($localRootName === '') {
            return $this->compressResult(false, 'denied', 'entry_not_found');
        }

        $entries = $this->collectCompressEntries($sourceRealPath, $sourceRootRealPath, $maxEntries, $maxBytes);
        if (!$entries['success']) {
            return $entries;
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($target['path'], \ZipArchive::CREATE | \ZipArchive::EXCL);
        if ($opened !== true) {
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        $writeOk = true;
        $items = [];
        if ($entries['entries'] === 0) {
            $writeOk = $zip->addEmptyDir($localRootName);
        }

        foreach ($entries['items'] as $item) {
            $path = (string)($item['path'] ?? '');
            $localName = $this->sourceTreeArchiveLocalName($sourceRealPath, $localRootName, $path);
            if ($localName === '' || $path === '') {
                $writeOk = false;
                break;
            }

            $writeOk = (string)($item['type'] ?? '') === 'directory'
                ? $zip->addEmptyDir($localName)
                : $zip->addFile($path, $localName);

            if (!$writeOk) {
                break;
            }

            $items[] = [
                'path' => $path,
                'local_name' => $localName,
                'type' => (string)($item['type'] ?? 'file'),
            ];
        }

        if (!$zip->close() || !$writeOk) {
            if (is_file($target['path'])) {
                @unlink($target['path']);
            }
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        return $this->compressResult(
            true,
            'success',
            '',
            (int)$entries['entries'],
            (int)$entries['bytes'],
            $items,
            $target['path']
        );
    }

    /**
     * @param array<int, array<string, mixed>> $sourceEntries
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, items: array<int, array{path: string, local_name: string, type: string}>}
     */
    public function createSourceSelectionArchive(
        string $sourceRootPath,
        string $archiveRootPath,
        string $targetPath,
        array $sourceEntries,
        int $maxEntries,
        int $maxBytes
    ): array {
        if (!class_exists(\ZipArchive::class)) {
            return $this->compressResult(false, 'failed', 'zip_extension_missing');
        }

        $sourceRootRealPath = realpath($sourceRootPath);
        if ($sourceRootRealPath === false || $sourceEntries === []) {
            return $this->compressResult(false, 'denied', 'entry_not_found');
        }

        $target = $this->resolveTargetPath($targetPath, $archiveRootPath);
        if ($target['error_code'] !== '') {
            return $this->compressResult(false, 'denied', $target['error_code']);
        }

        $items = [];
        $bytes = 0;
        $seenLocalNames = [];
        foreach ($sourceEntries as $entry) {
            $relativePath = $this->normalizeRelativePath((string)($entry['source_relative_path'] ?? ''));
            $sourcePath = trim((string)($entry['source_path'] ?? ''));
            if ($relativePath === '' || $sourcePath === '') {
                return $this->compressResult(false, 'denied', 'entry_not_found');
            }

            $sourceRealPath = realpath($sourcePath);
            $relativeCandidate = rtrim($sourceRootRealPath, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $relativeRealPath = realpath($relativeCandidate);
            if (
                $sourceRealPath === false
                || $relativeRealPath === false
                || !$this->isCandidateWithinRoot($sourceRootRealPath, $sourceRealPath)
                || !$this->sameComparablePath($sourceRealPath, $relativeRealPath)
                || is_link($sourcePath)
                || is_link($sourceRealPath)
                || !is_readable($sourceRealPath)
            ) {
                return $this->compressResult(false, 'denied', 'entry_not_readable');
            }

            $localRootName = $this->safeArchiveLocalName($relativePath);
            if ($localRootName === '') {
                return $this->compressResult(false, 'denied', 'entry_not_found');
            }

            if (is_file($sourceRealPath)) {
                $fileBytes = filesize($sourceRealPath);
                if ($fileBytes === false) {
                    return $this->compressResult(false, 'denied', 'entry_not_readable');
                }
                $bytes += (int)$fileBytes;
                if ($bytes > $maxBytes) {
                    return $this->compressResult(false, 'denied', 'compress_source_too_large');
                }

                if (!$this->appendSelectionArchiveItem($items, $seenLocalNames, [
                    'path' => $sourceRealPath,
                    'local_name' => $localRootName,
                    'type' => 'file',
                ], $maxEntries)) {
                    return $this->compressResult(false, 'denied', 'compress_entry_limit');
                }
                continue;
            }

            if (!is_dir($sourceRealPath)) {
                return $this->compressResult(false, 'denied', 'entry_not_readable');
            }

            $remainingEntries = $maxEntries - count($items);
            $remainingBytes = $maxBytes - $bytes;
            if ($remainingEntries < 1 || $remainingBytes < 1) {
                return $this->compressResult(false, 'denied', 'compress_entry_limit');
            }

            $treeEntries = $this->collectCompressEntries($sourceRealPath, $sourceRootRealPath, $remainingEntries, $remainingBytes);
            if (!$treeEntries['success']) {
                return $treeEntries;
            }

            $bytes += (int)$treeEntries['bytes'];
            if ((int)$treeEntries['entries'] === 0) {
                if (!$this->appendSelectionArchiveItem($items, $seenLocalNames, [
                    'path' => $sourceRealPath,
                    'local_name' => $localRootName,
                    'type' => 'directory',
                ], $maxEntries)) {
                    return $this->compressResult(false, 'denied', 'compress_entry_limit');
                }
                continue;
            }

            foreach ($treeEntries['items'] as $item) {
                $entryPath = (string)($item['path'] ?? '');
                $localName = $this->sourceTreeArchiveLocalName($sourceRealPath, $localRootName, $entryPath);
                if ($localName === '' || $entryPath === '') {
                    return $this->compressResult(false, 'denied', 'entry_not_found');
                }

                if (!$this->appendSelectionArchiveItem($items, $seenLocalNames, [
                    'path' => $entryPath,
                    'local_name' => $localName,
                    'type' => (string)($item['type'] ?? 'file'),
                ], $maxEntries)) {
                    return $this->compressResult(false, 'denied', 'compress_entry_limit');
                }
            }
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($target['path'], \ZipArchive::CREATE | \ZipArchive::EXCL);
        if ($opened !== true) {
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        $writeOk = true;
        foreach ($items as $item) {
            $localName = (string)($item['local_name'] ?? '');
            $path = (string)($item['path'] ?? '');
            if ($localName === '' || $path === '') {
                $writeOk = false;
                break;
            }

            $writeOk = (string)($item['type'] ?? '') === 'directory'
                ? $zip->addEmptyDir($localName)
                : $zip->addFile($path, $localName);

            if (!$writeOk) {
                break;
            }
        }

        if (!$zip->close() || !$writeOk) {
            if (is_file($target['path'])) {
                @unlink($target['path']);
            }
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        return $this->compressResult(true, 'success', '', count($items), $bytes, $items, $target['path']);
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, target_relative_path: string}
     */
    public function moveToTrash(
        string $sourcePath,
        string $rootPath,
        string $sourceRelativePath,
        int $maxEntries = self::DEFAULT_MAX_TRASH_ENTRIES,
        int $maxBytes = self::DEFAULT_MAX_TRASH_BYTES
    ): array {
        $sourceRelativePath = $this->normalizeRelativePath($sourceRelativePath);
        if ($sourceRelativePath === '' || str_starts_with($sourceRelativePath . '/', self::TRASH_DIRECTORY . '/')) {
            return $this->trashResult(false, 'denied', 'trash_source_forbidden');
        }

        $sourceRealPath = realpath($sourcePath);
        $rootRealPath = realpath($rootPath);
        if ($sourceRealPath === false || $rootRealPath === false || !$this->isCandidateWithinRoot($rootRealPath, $sourceRealPath)) {
            return $this->trashResult(false, 'denied', 'entry_not_found');
        }

        if (is_link($sourcePath) || is_link($sourceRealPath)) {
            return $this->trashResult(false, 'denied', 'trash_symlink_unsupported');
        }

        $parentPath = dirname($sourceRealPath);
        if (!is_writable($parentPath) || !is_writable($rootRealPath)) {
            return $this->trashResult(false, 'denied', 'entry_not_writable');
        }

        $entries = $this->collectTrashMetrics($sourceRealPath, $rootRealPath, $maxEntries, $maxBytes);
        if (!$entries['success']) {
            return $this->trashResult(false, (string)$entries['result'], (string)$entries['error_code']);
        }

        $trashDir = rtrim($rootRealPath, "\\/") . DIRECTORY_SEPARATOR . self::TRASH_DIRECTORY;
        if (!is_dir($trashDir) && !mkdir($trashDir, 0775, true) && !is_dir($trashDir)) {
            return $this->trashResult(false, 'failed', 'trash_directory_create_failed');
        }

        if (!is_writable($trashDir)) {
            return $this->trashResult(false, 'denied', 'trash_directory_not_writable');
        }

        $targetName = $this->buildTrashName($sourceRealPath);
        $targetPath = $trashDir . DIRECTORY_SEPARATOR . $targetName;
        if (!$this->isCandidateWithinRoot($rootRealPath, $targetPath) || file_exists($targetPath)) {
            return $this->trashResult(false, 'failed', 'trash_target_exists');
        }

        if (!rename($sourceRealPath, $targetPath)) {
            return $this->trashResult(false, 'failed', 'trash_move_failed');
        }

        return $this->trashResult(
            true,
            'success',
            '',
            (int)$entries['entries'],
            (int)$entries['bytes'],
            $targetPath,
            self::TRASH_DIRECTORY . '/' . $targetName
        );
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, target_relative_path: string}
     */
    public function restoreFromTrash(string $trashPath, string $originalPath, string $rootPath): array
    {
        $trashRealPath = realpath($trashPath);
        $rootRealPath = realpath($rootPath);
        if ($trashRealPath === false || $rootRealPath === false) {
            return $this->trashResult(false, 'denied', 'trash_entry_not_found');
        }

        $trashRoot = rtrim($rootRealPath, "\\/") . DIRECTORY_SEPARATOR . self::TRASH_DIRECTORY;
        if (!$this->isCandidateWithinRoot($trashRoot, $trashRealPath) || is_link($trashRealPath)) {
            return $this->trashResult(false, 'denied', 'trash_entry_invalid');
        }

        $originalPath = rtrim(dirname($originalPath), "\\/") . DIRECTORY_SEPARATOR . basename($originalPath);
        if (!$this->isCandidateWithinRoot($rootRealPath, $originalPath)) {
            return $this->trashResult(false, 'denied', 'path_escape');
        }

        if (file_exists($originalPath)) {
            return $this->trashResult(false, 'denied', 'restore_target_exists');
        }

        $parentPath = dirname($originalPath);
        if (!is_dir($parentPath) || !is_writable($parentPath)) {
            return $this->trashResult(false, 'denied', 'directory_not_writable');
        }

        $entries = $this->collectTrashMetrics($trashRealPath, $trashRoot, self::DEFAULT_MAX_TRASH_ENTRIES, self::DEFAULT_MAX_TRASH_BYTES);
        if (!$entries['success']) {
            return $this->trashResult(false, (string)$entries['result'], (string)$entries['error_code']);
        }

        if (!rename($trashRealPath, $originalPath)) {
            return $this->trashResult(false, 'failed', 'restore_failed');
        }

        return $this->trashResult(true, 'success', '', (int)$entries['entries'], (int)$entries['bytes'], $originalPath);
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, target_relative_path: string}
     */
    public function purgeTrash(string $trashPath, string $rootPath): array
    {
        $trashRealPath = realpath($trashPath);
        $rootRealPath = realpath($rootPath);
        if ($trashRealPath === false || $rootRealPath === false) {
            return $this->trashResult(false, 'denied', 'trash_entry_not_found');
        }

        $trashRoot = rtrim($rootRealPath, "\\/") . DIRECTORY_SEPARATOR . self::TRASH_DIRECTORY;
        $trashRootRealPath = realpath($trashRoot);
        if ($trashRootRealPath === false || !$this->isCandidateWithinRoot($rootRealPath, $trashRootRealPath)) {
            return $this->trashResult(false, 'denied', 'trash_entry_invalid');
        }

        if ($trashRealPath === $trashRootRealPath) {
            return $this->trashResult(false, 'denied', 'trash_root_purge_forbidden');
        }

        if (!$this->isCandidateWithinRoot($trashRootRealPath, $trashRealPath)) {
            return $this->trashResult(false, 'denied', 'trash_entry_invalid');
        }

        if (is_link($trashPath) || is_link($trashRealPath)) {
            return $this->trashResult(false, 'denied', 'trash_symlink_unsupported');
        }

        $entries = $this->collectTrashMetrics(
            $trashRealPath,
            $trashRootRealPath,
            self::DEFAULT_MAX_TRASH_ENTRIES,
            self::DEFAULT_MAX_TRASH_BYTES
        );
        if (!$entries['success']) {
            return $this->trashResult(false, (string)$entries['result'], (string)$entries['error_code']);
        }

        if (!$this->removeTrashEntry($trashRealPath, $trashRootRealPath)) {
            return $this->trashResult(false, 'failed', 'trash_purge_failed');
        }

        return $this->trashResult(true, 'success', '', (int)$entries['entries'], (int)$entries['bytes'], $trashRealPath);
    }

    /**
     * @return array{path: string, error_code: string}
     */
    private function resolveTargetPath(string $targetPath, string $rootPath): array
    {
        $targetPath = trim($targetPath);
        $rootRealPath = realpath($rootPath);
        if ($targetPath === '' || $rootRealPath === false) {
            return ['path' => '', 'error_code' => 'invalid_archive_name'];
        }

        $targetName = $this->safeZipFileName(basename(str_replace('\\', '/', $targetPath)));
        if ($targetName === '') {
            return ['path' => '', 'error_code' => 'invalid_archive_name'];
        }

        $targetDirRealPath = realpath(dirname($targetPath));
        if ($targetDirRealPath === false || !$this->isCandidateWithinRoot($rootPath, $targetDirRealPath)) {
            return ['path' => '', 'error_code' => 'path_escape'];
        }

        if (!is_writable($targetDirRealPath)) {
            return ['path' => '', 'error_code' => 'directory_not_writable'];
        }

        $target = rtrim($targetDirRealPath, "\\/") . DIRECTORY_SEPARATOR . $targetName;
        if (!$this->isCandidateWithinRoot($rootPath, $target)) {
            return ['path' => '', 'error_code' => 'path_escape'];
        }

        if (file_exists($target)) {
            return ['path' => '', 'error_code' => 'archive_target_exists'];
        }

        return ['path' => $target, 'error_code' => ''];
    }

    private function safeZipFileName(string $name): string
    {
        $name = trim(mb_substr($name, 0, 128));
        if ($name === '') {
            return '';
        }

        if (!str_ends_with(strtolower($name), '.zip')) {
            $name .= '.zip';
        }

        if (str_starts_with($name, '.') || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) !== 1) {
            return '';
        }

        return strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) === 'zip' ? $name : '';
    }

    private function safeArchiveLocalName(string $sourceRelativePath): string
    {
        $sourceRelativePath = $this->normalizeRelativePath($sourceRelativePath);
        if ($sourceRelativePath === '') {
            return '';
        }

        return 'source/' . $sourceRelativePath;
    }

    private function sourceTreeArchiveLocalName(string $sourceRealPath, string $localRootName, string $entryPath): string
    {
        $sourcePrefix = rtrim(str_replace('\\', '/', $sourceRealPath), '/') . '/';
        $entryRealPath = realpath($entryPath);
        if ($entryRealPath === false) {
            return '';
        }

        $entryNormalized = str_replace('\\', '/', $entryRealPath);
        $relativeName = ltrim(str_starts_with($entryNormalized, $sourcePrefix)
            ? substr($entryNormalized, strlen($sourcePrefix))
            : basename($entryRealPath), '/');
        if ($relativeName === '') {
            return '';
        }

        return trim($localRootName . '/' . $relativeName, '/');
    }

    /**
     * @param array<int, array{path: string, local_name: string, type: string}> $items
     * @param array<string, bool> $seenLocalNames
     * @param array{path: string, local_name: string, type: string} $item
     */
    private function appendSelectionArchiveItem(array &$items, array &$seenLocalNames, array $item, int $maxEntries): bool
    {
        $localName = trim((string)($item['local_name'] ?? ''));
        if ($localName === '' || isset($seenLocalNames[$localName]) || count($items) >= max(1, $maxEntries)) {
            return false;
        }

        $seenLocalNames[$localName] = true;
        $items[] = $item;

        return true;
    }

    private function sameComparablePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $left = strtolower($left);
            $right = strtolower($right);
        }

        return $left === $right;
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, items: array<int, array{path: string, local_name: string, type: string}>}
     */
    private function collectCompressEntries(string $sourcePath, string $rootPath, int $maxEntries, int $maxBytes): array
    {
        $maxEntries = max(1, $maxEntries);
        $maxBytes = max(1, $maxBytes);
        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !$this->isCandidateWithinRoot($rootPath, $sourceRealPath)) {
            return $this->compressResult(false, 'denied', 'entry_not_found');
        }

        if (is_link($sourcePath) || is_link($sourceRealPath)) {
            return $this->compressResult(false, 'denied', 'compress_symlink_unsupported');
        }

        if (is_file($sourceRealPath)) {
            $bytes = filesize($sourceRealPath);
            if ($bytes === false || !is_readable($sourceRealPath)) {
                return $this->compressResult(false, 'denied', 'entry_not_readable');
            }
            if ($bytes > $maxBytes) {
                return $this->compressResult(false, 'denied', 'compress_source_too_large');
            }

            return $this->compressResult(true, 'success', '', 1, (int)$bytes, [[
                'path' => $sourceRealPath,
                'local_name' => basename($sourceRealPath),
                'type' => 'file',
            ]]);
        }

        if (!is_dir($sourceRealPath) || !is_readable($sourceRealPath)) {
            return $this->compressResult(false, 'denied', 'entry_not_readable');
        }

        $items = [];
        $bytes = 0;
        $sourcePrefix = rtrim(str_replace('\\', '/', $sourceRealPath), '/') . '/';
        $sourceBaseName = basename($sourceRealPath);

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRealPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo) {
                    continue;
                }

                if ($fileInfo->isLink()) {
                    return $this->compressResult(false, 'denied', 'compress_symlink_unsupported');
                }

                $entryPath = $fileInfo->getPathname();
                $entryRealPath = realpath($entryPath);
                if (
                    $entryRealPath === false
                    || !$this->isCandidateWithinRoot($rootPath, $entryRealPath)
                    || !$this->isCandidateWithinRoot($sourceRealPath, $entryRealPath)
                ) {
                    return $this->compressResult(false, 'denied', 'path_escape');
                }

                if (count($items) >= $maxEntries) {
                    return $this->compressResult(false, 'denied', 'compress_entry_limit');
                }

                $entryType = $fileInfo->isDir() ? 'directory' : 'file';
                if ($entryType === 'file') {
                    if (!$fileInfo->isReadable()) {
                        return $this->compressResult(false, 'denied', 'entry_not_readable');
                    }
                    $bytes += (int)$fileInfo->getSize();
                    if ($bytes > $maxBytes) {
                        return $this->compressResult(false, 'denied', 'compress_source_too_large');
                    }
                }

                $entryNormalized = str_replace('\\', '/', $entryRealPath);
                $relativeName = ltrim(str_starts_with($entryNormalized, $sourcePrefix)
                    ? substr($entryNormalized, strlen($sourcePrefix))
                    : basename($entryRealPath), '/');
                $localName = trim($sourceBaseName . '/' . $relativeName, '/');

                if ($localName === '') {
                    continue;
                }

                $items[] = [
                    'path' => $entryRealPath,
                    'local_name' => $localName,
                    'type' => $entryType,
                ];
            }
        } catch (\Throwable) {
            return $this->compressResult(false, 'failed', 'compress_failed');
        }

        return $this->compressResult(true, 'success', '', count($items), $bytes, $items);
    }

    private function isCandidateWithinRoot(string $rootPath, string $candidatePath): bool
    {
        $rootRealPath = realpath($rootPath);
        if ($rootRealPath === false) {
            return false;
        }

        $root = rtrim(str_replace('\\', '/', $rootRealPath), '/') . '/';
        $candidate = rtrim(str_replace('\\', '/', $candidatePath), '/');

        return $candidate === rtrim($root, '/') || str_starts_with($candidate . '/', $root);
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int}
     */
    private function collectTrashMetrics(string $sourcePath, string $rootPath, int $maxEntries, int $maxBytes): array
    {
        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !$this->isCandidateWithinRoot($rootPath, $sourceRealPath)) {
            return $this->trashMetrics(false, 'denied', 'entry_not_found');
        }

        if (is_link($sourcePath) || is_link($sourceRealPath)) {
            return $this->trashMetrics(false, 'denied', 'trash_symlink_unsupported');
        }

        if (is_file($sourceRealPath)) {
            $bytes = filesize($sourceRealPath);
            if ($bytes === false || !is_readable($sourceRealPath)) {
                return $this->trashMetrics(false, 'denied', 'entry_not_readable');
            }

            if ($bytes > $maxBytes) {
                return $this->trashMetrics(false, 'denied', 'trash_source_too_large');
            }

            return $this->trashMetrics(true, 'success', '', 1, (int)$bytes);
        }

        if (!is_dir($sourceRealPath) || !is_readable($sourceRealPath)) {
            return $this->trashMetrics(false, 'denied', 'entry_not_readable');
        }

        $entries = 1;
        $bytes = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRealPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo) {
                    continue;
                }

                if ($fileInfo->isLink()) {
                    return $this->trashMetrics(false, 'denied', 'trash_symlink_unsupported');
                }

                $entryPath = $fileInfo->getPathname();
                $entryRealPath = realpath($entryPath);
                if (
                    $entryRealPath === false
                    || !$this->isCandidateWithinRoot($rootPath, $entryRealPath)
                    || !$this->isCandidateWithinRoot($sourceRealPath, $entryRealPath)
                ) {
                    return $this->trashMetrics(false, 'denied', 'path_escape');
                }

                $entries++;
                if ($entries > $maxEntries) {
                    return $this->trashMetrics(false, 'denied', 'trash_entry_limit');
                }

                if ($fileInfo->isFile()) {
                    if (!$fileInfo->isReadable()) {
                        return $this->trashMetrics(false, 'denied', 'entry_not_readable');
                    }
                    $bytes += (int)$fileInfo->getSize();
                    if ($bytes > $maxBytes) {
                        return $this->trashMetrics(false, 'denied', 'trash_source_too_large');
                    }
                }
            }
        } catch (\Throwable) {
            return $this->trashMetrics(false, 'failed', 'trash_scan_failed');
        }

        return $this->trashMetrics(true, 'success', '', $entries, $bytes);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', $path) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return '';
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function buildTrashName(string $sourceRealPath): string
    {
        $baseName = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($sourceRealPath)) ?: 'entry';
        $baseName = trim($baseName, '._-');
        if ($baseName === '') {
            $baseName = 'entry';
        }

        return date('Ymd-His') . '-' . substr(sha1($sourceRealPath . '|' . microtime(true)), 0, 12) . '-' . mb_substr($baseName, 0, 96);
    }

    private function removeTrashEntry(string $sourceRealPath, string $trashRootRealPath): bool
    {
        if (!$this->isCandidateWithinRoot($trashRootRealPath, $sourceRealPath) || $sourceRealPath === $trashRootRealPath) {
            return false;
        }

        if (is_file($sourceRealPath)) {
            return @unlink($sourceRealPath);
        }

        if (!is_dir($sourceRealPath)) {
            return false;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceRealPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo) {
                    continue;
                }

                if ($fileInfo->isLink()) {
                    return false;
                }

                $entryPath = $fileInfo->getPathname();
                $entryRealPath = realpath($entryPath);
                if ($entryRealPath === false || !$this->isCandidateWithinRoot($trashRootRealPath, $entryRealPath)) {
                    return false;
                }

                if ($fileInfo->isDir()) {
                    if (!@rmdir($entryRealPath)) {
                        return false;
                    }
                    continue;
                }

                if (!@unlink($entryRealPath)) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return @rmdir($sourceRealPath);
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int}
     */
    private function trashMetrics(bool $success, string $result, string $errorCode, int $entries = 0, int $bytes = 0): array
    {
        return [
            'success' => $success,
            'result' => $result,
            'error_code' => $errorCode,
            'entries' => $entries,
            'bytes' => $bytes,
        ];
    }

    /**
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, target_relative_path: string}
     */
    private function trashResult(
        bool $success,
        string $result,
        string $errorCode,
        int $entries = 0,
        int $bytes = 0,
        string $targetPath = '',
        string $targetRelativePath = ''
    ): array {
        return [
            'success' => $success,
            'result' => $result,
            'error_code' => $errorCode,
            'entries' => $entries,
            'bytes' => $bytes,
            'target_path' => $targetPath,
            'target_relative_path' => $targetRelativePath,
        ];
    }

    /**
     * @param array<int, array{path: string, local_name: string, type: string}> $items
     * @return array{success: bool, result: string, error_code: string, entries: int, bytes: int, target_path: string, items: array<int, array{path: string, local_name: string, type: string}>}
     */
    private function compressResult(
        bool $success,
        string $result,
        string $errorCode,
        int $entries = 0,
        int $bytes = 0,
        array $items = [],
        string $targetPath = ''
    ): array {
        return [
            'success' => $success,
            'result' => $result,
            'error_code' => $errorCode,
            'entries' => $entries,
            'bytes' => $bytes,
            'target_path' => $targetPath,
            'items' => $items,
        ];
    }
}
