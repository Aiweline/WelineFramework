<?php

declare(strict_types=1);

namespace Weline\FileManager\Queue;

use Weline\FileManager\Service\WlsFileManagerLargeOperationService;
use Weline\FileManager\Service\WlsFileManagerPathPolicyService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class WlsFileManagerLargeOperationQueue implements QueueInterface
{
    public const OPERATION_COMPRESS_ZIP = 'compress_zip';
    public const OPERATION_TRASH_ENTRY = 'trash_entry';
    public const OPERATION_SOURCE_TRASH_ENTRY = 'source_trash_entry';
    private const SOURCE_QUEUE_MAX_BYTES = 131072;
    private const SOURCE_QUEUE_MAX_ENTRIES = 1;
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

    public function validate(Queue &$queue): bool
    {
        $payload = $this->payload($queue);
        $operation = (string)($payload['operation'] ?? '');
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

    public function execute(Queue &$queue): string
    {
        $payload = $this->payload($queue);
        $operation = (string)($payload['operation'] ?? '');
        if ($payload === [] || !in_array($operation, [self::OPERATION_COMPRESS_ZIP, self::OPERATION_TRASH_ENTRY, self::OPERATION_SOURCE_TRASH_ENTRY], true)) {
            throw new \InvalidArgumentException((string)__('WLS 文件队列内容无效。'));
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
            ->save();

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
            $queue->setProcess((string)__('WLS 文件管理器队列压缩失败：%{1}', $errorCode))->save();
            throw new \RuntimeException((string)__('WLS 文件管理器队列压缩失败：%{1}', $errorCode));
        }

        $message = (string)__('WLS 文件管理器队列压缩完成：%{1}（%{2} 个条目，%{3} 字节）。', [
            $targetRelativePath !== '' ? $targetRelativePath : (string)($result['target_path'] ?? ''),
            (string)((int)($result['entries'] ?? 0)),
            (string)((int)($result['bytes'] ?? 0)),
        ]);
        $queue->setProcess($message)->save();

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeTrash(Queue &$queue, array $payload): string
    {
        $sourceRelativePath = trim((string)($payload['source_relative_path'] ?? ''));
        $queue->setProcess((string)__('WLS 文件管理器队列回收开始：%{1}', $sourceRelativePath !== '' ? $sourceRelativePath : (string)$payload['source_path']))
            ->save();

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
            $queue->setProcess((string)__('WLS 文件管理器队列回收失败：%{1}', $errorCode))->save();
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
        $queue->setProcess($message)->save();

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Queue $queue): array
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

        $relativeCandidate = rtrim($rootRealPath, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $relativeRealPath = realpath($relativeCandidate);
        if ($relativeRealPath === false) {
            return false;
        }

        return $this->sameRealPath($sourceRealPath, $relativeRealPath);
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
