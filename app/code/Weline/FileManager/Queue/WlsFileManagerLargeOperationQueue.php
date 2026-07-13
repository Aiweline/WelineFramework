<?php

declare(strict_types=1);

namespace Weline\FileManager\Queue;

use Weline\FileManager\Service\WlsFileManagerLargeOperationService;
use Weline\FileManager\Service\WlsFileManagerPathPolicyService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

class WlsFileManagerLargeOperationQueue implements QueueConsumerInterface
{
    public const OPERATION_COMPRESS_ZIP = 'compress_zip';
    public const OPERATION_TRASH_ENTRY = 'trash_entry';
    public const OPERATION_SOURCE_TRASH_ENTRY = 'source_trash_entry';
    public const OPERATION_SOURCE_ARCHIVE_FILE = 'source_archive_file';
    public const OPERATION_SOURCE_ARCHIVE_TREE = 'source_archive_tree';
    public const OPERATION_SOURCE_ARCHIVE_SELECTION = 'source_archive_selection';
    private const SOURCE_QUEUE_MAX_BYTES = 131072;
    private const SOURCE_QUEUE_MAX_ENTRIES = 1;
    private const SOURCE_TREE_QUEUE_MAX_BYTES = 10485760;
    private const SOURCE_TREE_QUEUE_MAX_ENTRIES = 200;
    private const SOURCE_SELECTION_QUEUE_MAX_SELECTED = 20;
    private const SOURCE_PROTECTED_SEGMENTS = ['.git', '.wls-trash', 'generated', 'node_modules', 'vendor', 'var'];
    private const SOURCE_PROTECTED_PATHS = [
        '.env',
        'app/etc/env.php',
        'composer.lock',
        'package-lock.json',
        'pnpm-lock.yaml',
        'yarn.lock',
    ];

    public function name(): string
    {
        return (string)__('WLS 文件管理器大文件操作');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('由 WLS 文件管理器创建的大文件压缩任务，避免在面板请求内执行长耗时文件操作。');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $payload = $this->payload($queue);
        $operation = (string)($payload['operation'] ?? '');
        if ($operation === self::OPERATION_SOURCE_ARCHIVE_TREE) {
            return $this->sourceArchiveTreePayloadAllowed($payload)
                && trim((string)($payload['root_path'] ?? '')) !== ''
                && trim((string)($payload['source_path'] ?? '')) !== ''
                && trim((string)($payload['source_relative_path'] ?? '')) !== ''
                && trim((string)($payload['archive_root_path'] ?? '')) !== ''
                && trim((string)($payload['target_path'] ?? '')) !== ''
                && trim((string)($payload['target_relative_path'] ?? '')) !== '';
        }

        if ($operation === self::OPERATION_SOURCE_ARCHIVE_FILE) {
            return $this->sourceArchivePayloadAllowed($payload)
                && trim((string)($payload['root_path'] ?? '')) !== ''
                && trim((string)($payload['source_path'] ?? '')) !== ''
                && trim((string)($payload['source_relative_path'] ?? '')) !== ''
                && trim((string)($payload['archive_root_path'] ?? '')) !== ''
                && trim((string)($payload['target_path'] ?? '')) !== ''
                && trim((string)($payload['target_relative_path'] ?? '')) !== '';
        }

        if ($operation === self::OPERATION_SOURCE_ARCHIVE_SELECTION) {
            return $this->sourceArchiveSelectionPayloadAllowed($payload)
                && trim((string)($payload['root_path'] ?? '')) !== ''
                && is_array($payload['source_entries'] ?? null)
                && trim((string)($payload['archive_root_path'] ?? '')) !== ''
                && trim((string)($payload['target_path'] ?? '')) !== ''
                && trim((string)($payload['target_relative_path'] ?? '')) !== '';
        }

        if ($operation === self::OPERATION_TRASH_ENTRY || $operation === self::OPERATION_SOURCE_TRASH_ENTRY) {
            if ($operation === self::OPERATION_SOURCE_TRASH_ENTRY && !$this->sourceQueuePayloadAllowed($payload)) {
                return false;
            }

            return trim((string)($payload['root_path'] ?? '')) !== ''
                && trim((string)($payload['source_path'] ?? '')) !== ''
                && trim((string)($payload['source_relative_path'] ?? '')) !== '';
        }

        return $payload !== []
            && $operation === self::OPERATION_COMPRESS_ZIP
            && trim((string)($payload['root_path'] ?? '')) !== ''
            && trim((string)($payload['source_path'] ?? '')) !== ''
            && trim((string)($payload['target_path'] ?? '')) !== '';
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $payload = $this->payload($queue);
        $operation = (string)($payload['operation'] ?? '');
        if ($payload === [] || !in_array($operation, [self::OPERATION_COMPRESS_ZIP, self::OPERATION_TRASH_ENTRY, self::OPERATION_SOURCE_TRASH_ENTRY, self::OPERATION_SOURCE_ARCHIVE_FILE, self::OPERATION_SOURCE_ARCHIVE_TREE, self::OPERATION_SOURCE_ARCHIVE_SELECTION], true)) {
            throw new \InvalidArgumentException((string)__('WLS 文件队列内容无效。'));
        }

        if ($operation === self::OPERATION_SOURCE_ARCHIVE_TREE) {
            if (!$this->sourceArchiveTreePayloadAllowed($payload)) {
                throw new \InvalidArgumentException((string)__('WLS source directory archive queue payload is invalid.'));
            }

            return $this->executeSourceArchiveTree($queue, $payload);
        }

        if ($operation === self::OPERATION_SOURCE_ARCHIVE_FILE) {
            if (!$this->sourceArchivePayloadAllowed($payload)) {
                throw new \InvalidArgumentException((string)__('WLS source archive queue payload is invalid.'));
            }

            return $this->executeSourceArchive($queue, $payload);
        }

        if ($operation === self::OPERATION_SOURCE_ARCHIVE_SELECTION) {
            if (!$this->sourceArchiveSelectionPayloadAllowed($payload)) {
                throw new \InvalidArgumentException((string)__('WLS source selection archive queue payload is invalid.'));
            }

            return $this->executeSourceArchiveSelection($queue, $payload);
        }

        if ($operation === self::OPERATION_TRASH_ENTRY || $operation === self::OPERATION_SOURCE_TRASH_ENTRY) {
            if ($operation === self::OPERATION_SOURCE_TRASH_ENTRY && !$this->sourceQueuePayloadAllowed($payload)) {
                throw new \InvalidArgumentException((string)__('WLS 源码文件队列内容无效。'));
            }

            return $this->executeTrash($queue, $payload);
        }

        $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? ''));
        $targetRelativePath = trim((string)($payload['target_relative_path'] ?? ''));
        $queue->setProcess((string)__('WLS 文件管理器队列压缩开始：%{1}', $sourceRelativePath !== '' ? $sourceRelativePath : (string)$payload['source_path']))
            ->persist();

        $service = ObjectManager::getInstance(WlsFileManagerLargeOperationService::class);
        $result = $service->createZipArchive(
            (string)$payload['source_path'],
            (string)$payload['target_path'],
            (string)$payload['root_path'],
            (int)($payload['max_entries'] ?? WlsFileManagerLargeOperationService::DEFAULT_MAX_ZIP_ENTRIES),
            (int)($payload['max_bytes'] ?? WlsFileManagerLargeOperationService::DEFAULT_MAX_ZIP_BYTES)
        );

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'compress_failed');
            $queue->setProcess((string)__('WLS 文件管理器队列压缩失败：%{1}', $errorCode))->persist();
            throw new \RuntimeException((string)__('WLS 文件管理器队列压缩失败：%{1}', $errorCode));
        }

        $message = (string)__('WLS 文件管理器队列压缩完成：%{1}（%{2} 个条目，%{3} 字节）。', [
            $targetRelativePath !== '' ? $targetRelativePath : (string)($result['target_path'] ?? ''),
            (string)((int)($result['entries'] ?? 0)),
            (string)((int)($result['bytes'] ?? 0)),
        ]);
        $queue->setProcess($message)->persist();

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeSourceArchive(QueueTaskContextInterface $queue, array $payload): string
    {
        $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? ''));
        $targetRelativePath = trim((string)($payload['target_relative_path'] ?? ''));
        $queue->setProcess((string)__('WLS source archive queue started: %{1}', $sourceRelativePath !== '' ? $sourceRelativePath : (string)$payload['source_path']))
            ->persist();

        $service = ObjectManager::getInstance(WlsFileManagerLargeOperationService::class);
        $result = $service->createSingleFileArchive(
            (string)$payload['source_path'],
            (string)$payload['target_path'],
            (string)$payload['root_path'],
            (string)$payload['archive_root_path'],
            $sourceRelativePath,
            (int)($payload['max_bytes'] ?? self::SOURCE_QUEUE_MAX_BYTES)
        );

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'compress_failed');
            $queue->setProcess((string)__('WLS source archive queue failed: %{1}', $errorCode))->persist();
            throw new \RuntimeException((string)__('WLS source archive queue failed: %{1}', $errorCode));
        }

        $payload['archive_path'] = (string)($result['target_path'] ?? '');
        $payload['archive_relative_path'] = $targetRelativePath;
        $payload['archive_entries'] = (int)($result['entries'] ?? 0);
        $payload['archive_bytes'] = (int)($result['bytes'] ?? 0);
        $queue->setContent((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $message = (string)__('WLS source archive queue completed: %{1} -> %{2} (%{3} entries, %{4} bytes)', [
            $sourceRelativePath !== '' ? $sourceRelativePath : (string)$payload['source_path'],
            $targetRelativePath !== '' ? $targetRelativePath : (string)($result['target_path'] ?? ''),
            (string)((int)($result['entries'] ?? 0)),
            (string)((int)($result['bytes'] ?? 0)),
        ]);
        $queue->setProcess($message)->persist();

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeSourceArchiveTree(QueueTaskContextInterface $queue, array $payload): string
    {
        $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? ''));
        $targetRelativePath = trim((string)($payload['target_relative_path'] ?? ''));
        $queue->setProcess((string)__('WLS source directory archive queue started: %{1}', $sourceRelativePath !== '' ? $sourceRelativePath : (string)$payload['source_path']))
            ->persist();

        $service = ObjectManager::getInstance(WlsFileManagerLargeOperationService::class);
        $result = $service->createSourceTreeArchive(
            (string)$payload['source_path'],
            (string)$payload['target_path'],
            (string)$payload['root_path'],
            (string)$payload['archive_root_path'],
            $sourceRelativePath,
            (int)($payload['max_entries'] ?? self::SOURCE_TREE_QUEUE_MAX_ENTRIES),
            (int)($payload['max_bytes'] ?? self::SOURCE_TREE_QUEUE_MAX_BYTES)
        );

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'compress_failed');
            $queue->setProcess((string)__('WLS source directory archive queue failed: %{1}', $errorCode))->persist();
            throw new \RuntimeException((string)__('WLS source directory archive queue failed: %{1}', $errorCode));
        }

        $payload['archive_path'] = (string)($result['target_path'] ?? '');
        $payload['archive_relative_path'] = $targetRelativePath;
        $payload['archive_entries'] = (int)($result['entries'] ?? 0);
        $payload['archive_bytes'] = (int)($result['bytes'] ?? 0);
        $queue->setContent((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $message = (string)__('WLS source directory archive queue completed: %{1} -> %{2} (%{3} entries, %{4} bytes)', [
            $sourceRelativePath !== '' ? $sourceRelativePath : (string)$payload['source_path'],
            $targetRelativePath !== '' ? $targetRelativePath : (string)($result['target_path'] ?? ''),
            (string)((int)($result['entries'] ?? 0)),
            (string)((int)($result['bytes'] ?? 0)),
        ]);
        $queue->setProcess($message)->persist();

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeSourceArchiveSelection(QueueTaskContextInterface $queue, array $payload): string
    {
        $sourceParentRelativePath = trim((string)($payload['source_parent_relative_path'] ?? ''));
        $targetRelativePath = trim((string)($payload['target_relative_path'] ?? ''));
        $sourceEntries = is_array($payload['source_entries'] ?? null) ? array_values((array)$payload['source_entries']) : [];
        $queue->setProcess((string)__('WLS source selection archive queue started: %{1}', $sourceParentRelativePath !== '' ? $sourceParentRelativePath : '/'))
            ->persist();

        $service = ObjectManager::getInstance(WlsFileManagerLargeOperationService::class);
        $result = $service->createSourceSelectionArchive(
            (string)$payload['root_path'],
            (string)$payload['archive_root_path'],
            (string)$payload['target_path'],
            $sourceEntries,
            (int)($payload['max_entries'] ?? self::SOURCE_TREE_QUEUE_MAX_ENTRIES),
            (int)($payload['max_bytes'] ?? self::SOURCE_TREE_QUEUE_MAX_BYTES)
        );

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'compress_failed');
            $queue->setProcess((string)__('WLS source selection archive queue failed: %{1}', $errorCode))->persist();
            throw new \RuntimeException((string)__('WLS source selection archive queue failed: %{1}', $errorCode));
        }

        $payload['archive_path'] = (string)($result['target_path'] ?? '');
        $payload['archive_relative_path'] = $targetRelativePath;
        $payload['archive_entries'] = (int)($result['entries'] ?? 0);
        $payload['archive_bytes'] = (int)($result['bytes'] ?? 0);
        $queue->setContent((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $message = (string)__('WLS source selection archive queue completed: %{1} -> %{2} (%{3} entries, %{4} bytes)', [
            $sourceParentRelativePath !== '' ? $sourceParentRelativePath : '/',
            $targetRelativePath !== '' ? $targetRelativePath : (string)($result['target_path'] ?? ''),
            (string)((int)($result['entries'] ?? 0)),
            (string)((int)($result['bytes'] ?? 0)),
        ]);
        $queue->setProcess($message)->persist();

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeTrash(QueueTaskContextInterface $queue, array $payload): string
    {
        $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? ''));
        $queue->setProcess((string)__('WLS 文件管理器队列回收开始：%{1}', $sourceRelativePath !== '' ? $sourceRelativePath : (string)$payload['source_path']))
            ->persist();

        $service = ObjectManager::getInstance(WlsFileManagerLargeOperationService::class);
        $result = $service->moveToTrash(
            (string)$payload['source_path'],
            (string)$payload['root_path'],
            $sourceRelativePath,
            (int)($payload['max_entries'] ?? WlsFileManagerLargeOperationService::DEFAULT_MAX_TRASH_ENTRIES),
            (int)($payload['max_bytes'] ?? WlsFileManagerLargeOperationService::DEFAULT_MAX_TRASH_BYTES)
        );

        if (empty($result['success'])) {
            $errorCode = (string)($result['error_code'] ?? 'trash_move_failed');
            $queue->setProcess((string)__('WLS 文件管理器队列回收失败：%{1}', $errorCode))->persist();
            throw new \RuntimeException((string)__('WLS 文件管理器队列回收失败：%{1}', $errorCode));
        }

        $payload['trash_path'] = (string)($result['target_path'] ?? '');
        $payload['trash_relative_path'] = (string)($result['target_relative_path'] ?? '');
        $payload['trash_entries'] = (int)($result['entries'] ?? 0);
        $payload['trash_bytes'] = (int)($result['bytes'] ?? 0);
        $queue->setContent((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $message = (string)__('WLS 文件管理器队列回收完成：%{1} -> %{2}（%{3} 个条目，%{4} 字节，可恢复）。', [
            $sourceRelativePath !== '' ? $sourceRelativePath : (string)$payload['source_path'],
            (string)($payload['trash_relative_path'] ?? ''),
            (string)((int)($result['entries'] ?? 0)),
            (string)((int)($result['bytes'] ?? 0)),
        ]);
        $queue->setProcess($message)->persist();

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(QueueTaskContextInterface $queue): array
    {
        $decoded = json_decode((string)$queue->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sourceQueuePayloadAllowed(array $payload): bool
    {
        $rootKey = trim((string)($payload['root_key'] ?? ''));
        if (!in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)) {
            return false;
        }

        $maxEntries = (int)($payload['max_entries'] ?? 0);
        $maxBytes = (int)($payload['max_bytes'] ?? 0);
        if ($maxEntries < 1 || $maxEntries > self::SOURCE_QUEUE_MAX_ENTRIES || $maxBytes < 1 || $maxBytes > self::SOURCE_QUEUE_MAX_BYTES) {
            return false;
        }

        $relativePath = $this->normalizeRelativePath((string)($payload['source_relative_path'] ?? ''));
        if ($relativePath === '' || str_starts_with($relativePath . '/', '.wls-trash/')) {
            return false;
        }

        if (!$this->sourceRelativePathAllowed($relativePath)) {
            return false;
        }

        if (!$this->sourcePathsMatchPayload($payload, $relativePath)) {
            return false;
        }

        $extension = strtolower((string)pathinfo($relativePath, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, WlsFileManagerPathPolicyService::SOURCE_EDIT_EXTENSIONS, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sourceArchivePayloadAllowed(array $payload): bool
    {
        return $this->sourceQueuePayloadAllowed($payload) && $this->sourceArchiveTargetAllowed($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sourceArchiveTreePayloadAllowed(array $payload): bool
    {
        if (!$this->sourceTreeQueuePayloadAllowed($payload)) {
            return false;
        }

        return $this->sourceArchiveTargetAllowed($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sourceArchiveSelectionPayloadAllowed(array $payload): bool
    {
        $rootKey = trim((string)($payload['root_key'] ?? ''));
        if (!in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)) {
            return false;
        }

        $maxSelected = (int)($payload['max_selected'] ?? 0);
        $maxEntries = (int)($payload['max_entries'] ?? 0);
        $maxBytes = (int)($payload['max_bytes'] ?? 0);
        if (
            $maxSelected < 1
            || $maxSelected > self::SOURCE_SELECTION_QUEUE_MAX_SELECTED
            || $maxEntries < 1
            || $maxEntries > self::SOURCE_TREE_QUEUE_MAX_ENTRIES
            || $maxBytes < 1
            || $maxBytes > self::SOURCE_TREE_QUEUE_MAX_BYTES
        ) {
            return false;
        }

        $entries = is_array($payload['source_entries'] ?? null) ? array_values((array)$payload['source_entries']) : [];
        if ($entries === [] || count($entries) > $maxSelected) {
            return false;
        }

        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return false;
            }

            $relativePath = $this->normalizeRelativePath((string)($entry['source_relative_path'] ?? ''));
            if ($relativePath === '' || isset($seen[$relativePath]) || str_starts_with($relativePath . '/', '.wls-trash/')) {
                return false;
            }

            $seen[$relativePath] = true;
            if (!$this->sourceRelativePathAllowed($relativePath) || !$this->sourceEntryPathsMatchPayload($payload, $entry, $relativePath)) {
                return false;
            }

            $sourcePath = trim((string)($entry['source_path'] ?? ''));
            $sourceRealPath = $sourcePath !== '' ? realpath($sourcePath) : false;
            if ($sourceRealPath === false || is_link($sourcePath) || is_link($sourceRealPath) || !is_readable($sourceRealPath)) {
                return false;
            }

            if (is_file($sourceRealPath)) {
                $extension = strtolower((string)pathinfo($relativePath, PATHINFO_EXTENSION));
                if ($extension === '' || !in_array($extension, WlsFileManagerPathPolicyService::SOURCE_EDIT_EXTENSIONS, true)) {
                    return false;
                }
                continue;
            }

            if (!is_dir($sourceRealPath)) {
                return false;
            }
        }

        return $this->sourceArchiveTargetAllowed($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sourceTreeQueuePayloadAllowed(array $payload): bool
    {
        $rootKey = trim((string)($payload['root_key'] ?? ''));
        if (!in_array($rootKey, WlsFileManagerPathPolicyService::ALLOWED_SOURCE_EDIT_ROOTS, true)) {
            return false;
        }

        $maxEntries = (int)($payload['max_entries'] ?? 0);
        $maxBytes = (int)($payload['max_bytes'] ?? 0);
        if ($maxEntries < 1 || $maxEntries > self::SOURCE_TREE_QUEUE_MAX_ENTRIES || $maxBytes < 1 || $maxBytes > self::SOURCE_TREE_QUEUE_MAX_BYTES) {
            return false;
        }

        $relativePath = $this->normalizeRelativePath((string)($payload['source_relative_path'] ?? ''));
        if ($relativePath === '' || str_starts_with($relativePath . '/', '.wls-trash/')) {
            return false;
        }

        if (!$this->sourceRelativePathAllowed($relativePath) || !$this->sourcePathsMatchPayload($payload, $relativePath)) {
            return false;
        }

        $sourcePath = trim((string)($payload['source_path'] ?? ''));
        $sourceRealPath = $sourcePath !== '' ? realpath($sourcePath) : false;
        return $sourceRealPath !== false
            && is_dir($sourceRealPath)
            && !is_link($sourcePath)
            && !is_link($sourceRealPath)
            && is_readable($sourceRealPath);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sourceArchiveTargetAllowed(array $payload): bool
    {
        $archiveRootPath = trim((string)($payload['archive_root_path'] ?? ''));
        $targetPath = trim((string)($payload['target_path'] ?? ''));
        $targetRelativePath = $this->normalizeRelativePath((string)($payload['target_relative_path'] ?? ''));
        if ($archiveRootPath === '' || $targetPath === '' || $targetRelativePath === '' || str_contains($targetRelativePath, '/')) {
            return false;
        }

        $targetName = $this->safeZipFileName(basename(str_replace('\\', '/', $targetPath)));
        if ($targetName === '' || $targetRelativePath !== $targetName) {
            return false;
        }

        $archiveRootRealPath = realpath($archiveRootPath);
        $targetDirRealPath = realpath(dirname($targetPath));
        if ($archiveRootRealPath === false || $targetDirRealPath === false || !$this->isPathWithinRoot($archiveRootRealPath, $targetDirRealPath)) {
            return false;
        }

        $targetCandidate = rtrim($targetDirRealPath, "\\/") . DIRECTORY_SEPARATOR . $targetName;
        return !file_exists($targetCandidate) && $this->isPathWithinRoot($archiveRootRealPath, $targetCandidate);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sourcePathsMatchPayload(array $payload, string $relativePath): bool
    {
        $rootPath = trim((string)($payload['root_path'] ?? ''));
        $sourcePath = trim((string)($payload['source_path'] ?? ''));
        if ($rootPath === '' || $sourcePath === '') {
            return false;
        }

        $rootRealPath = realpath($rootPath);
        $sourceRealPath = realpath($sourcePath);
        if ($rootRealPath === false || $sourceRealPath === false || !$this->isPathWithinRoot($rootRealPath, $sourceRealPath)) {
            return false;
        }

        if ($this->relativePathContainsSymlink($rootRealPath, $relativePath)) {
            return false;
        }

        $relativeCandidate = rtrim($rootRealPath, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $relativeRealPath = realpath($relativeCandidate);
        if ($relativeRealPath === false) {
            return false;
        }

        return $this->sameRealPath($sourceRealPath, $relativeRealPath);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $entry
     */
    private function sourceEntryPathsMatchPayload(array $payload, array $entry, string $relativePath): bool
    {
        $rootPath = trim((string)($payload['root_path'] ?? ''));
        $sourcePath = trim((string)($entry['source_path'] ?? ''));
        if ($rootPath === '' || $sourcePath === '') {
            return false;
        }

        $rootRealPath = realpath($rootPath);
        $sourceRealPath = realpath($sourcePath);
        if ($rootRealPath === false || $sourceRealPath === false || !$this->isPathWithinRoot($rootRealPath, $sourceRealPath)) {
            return false;
        }

        if ($this->relativePathContainsSymlink($rootRealPath, $relativePath)) {
            return false;
        }

        $relativeCandidate = rtrim($rootRealPath, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $relativeRealPath = realpath($relativeCandidate);
        if ($relativeRealPath === false) {
            return false;
        }

        return $this->sameRealPath($sourceRealPath, $relativeRealPath);
    }

    private function relativePathContainsSymlink(string $rootRealPath, string $relativePath): bool
    {
        $candidate = rtrim($rootRealPath, "\\/");
        foreach (explode('/', $this->normalizeRelativePath($relativePath)) as $segment) {
            if ($segment === '') {
                continue;
            }

            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isPathWithinRoot(string $rootPath, string $candidatePath): bool
    {
        $root = rtrim(str_replace('\\', '/', $rootPath), '/') . '/';
        $candidate = rtrim(str_replace('\\', '/', $candidatePath), '/');
        if ($this->sameRealPath($candidate, rtrim($root, '/'))) {
            return true;
        }

        return str_starts_with($this->normalizeComparablePath($candidate) . '/', $this->normalizeComparablePath($root));
    }

    private function sameRealPath(string $left, string $right): bool
    {
        return $this->normalizeComparablePath($left) === $this->normalizeComparablePath($right);
    }

    private function normalizeComparablePath(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private function sourceRelativePathAllowed(string $relativePath): bool
    {
        $relativePath = strtolower($this->normalizeRelativePath($relativePath));
        if ($relativePath === '') {
            return false;
        }

        foreach (self::SOURCE_PROTECTED_PATHS as $protectedPath) {
            if ($relativePath === strtolower($protectedPath)) {
                return false;
            }
        }

        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || in_array($segment, self::SOURCE_PROTECTED_SEGMENTS, true)) {
                return false;
            }
        }

        return true;
    }

    private function safeZipFileName(string $name): string
    {
        $name = trim(mb_substr($name, 0, 128));
        if ($name === '' || !str_ends_with(strtolower($name), '.zip')) {
            return '';
        }

        if (str_starts_with($name, '.') || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $name) !== 1) {
            return '';
        }

        return strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) === 'zip' ? $name : '';
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return '';
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
