<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Exception\FileAccessDeniedException;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\MediaManager\Helper\MimeTypes;
use Weline\Storage\Api\Data\StorageDiskCode;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\StorageCatalogInterface;
use Weline\Storage\Api\StorageDirectoryManagerInterface;
use Weline\Storage\Api\StorageManagerInterface;

final class ConnectorService
{
    private const DEFAULT_DISK = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
    private const MAX_UPLOAD_BYTES = MediaAssetUploadService::MAX_UPLOAD_BYTES;
    private const MAX_TARGET_HASH_BYTES = 2048;

    private ?StorageCatalogInterface $storageCatalog = null;
    private ?StorageDirectoryManagerInterface $storageDirectoryManager = null;
    private bool $storageDirectoryManagerResolved = false;
    private ?StorageManagerInterface $storageManager = null;
    private ?MediaAssetUploadService $assetUploadService = null;
    private ?MediaAssetCatalogService $assetCatalogService = null;
    private ?MediaAssetMetadataService $assetMetadataService = null;
    private ?FileAssetLibraryInterface $assetLibrary = null;

    private function getStorageCatalog(): ?StorageCatalogInterface
    {
        if ($this->storageCatalog === null && interface_exists(StorageCatalogInterface::class)) {
            try {
                $this->storageCatalog = ObjectManager::getInstance(StorageCatalogInterface::class);
            } catch (\Throwable) {
                return null;
            }
        }
        return $this->storageCatalog;
    }

    private function getStorageDirectoryManager(): ?StorageDirectoryManagerInterface
    {
        if ($this->storageDirectoryManagerResolved) {
            return $this->storageDirectoryManager;
        }
        $this->storageDirectoryManagerResolved = true;
        if (!interface_exists(StorageDirectoryManagerInterface::class)) {
            return null;
        }
        try {
            $this->storageDirectoryManager = ObjectManager::getInstance(StorageDirectoryManagerInterface::class);
        } catch (\Throwable) {
            $this->storageDirectoryManager = null;
        }
        return $this->storageDirectoryManager;
    }

    private function getStorageManager(): StorageManagerInterface
    {
        return $this->storageManager ??= ObjectManager::getInstance(StorageManagerInterface::class);
    }

    private function getAssetUploadService(): MediaAssetUploadService
    {
        return $this->assetUploadService ??= ObjectManager::getInstance(MediaAssetUploadService::class);
    }

    private function getAssetCatalogService(): MediaAssetCatalogService
    {
        return $this->assetCatalogService ??= ObjectManager::getInstance(MediaAssetCatalogService::class);
    }

    private function getAssetMetadataService(): MediaAssetMetadataService
    {
        return $this->assetMetadataService ??= ObjectManager::getInstance(MediaAssetMetadataService::class);
    }

    private function getAssetLibrary(): FileAssetLibraryInterface
    {
        return $this->assetLibrary ??= ObjectManager::getInstance(FileAssetLibraryInterface::class);
    }

    /**
     * Execute an explicit connector command. HTTP/Worker adapters must pass
     * request parameters and uploaded file records rather than mutating globals.
     *
     * @param array<string,mixed> $src
     * @param array<string,mixed> $opts
     * @param array<string,mixed> $uploadedFiles
     * @return array<string,mixed>
     */
    public function execute(array $src, array $opts = [], array $uploadedFiles = []): array
    {
        if (trim((string)($src['locale_code'] ?? '')) === '') {
            $src['locale_code'] = trim((string)($opts['locale'] ?? ''));
        }
        $cmd = trim((string)($src['cmd'] ?? 'open')) ?: 'open';
        if ($uploadedFiles !== []) {
            $cmd = 'upload';
        }
        if ($cmd === 'storages') {
            return $this->handleStorages();
        }
        $storage = trim((string)($src['storage'] ?? ''));
        if ($storage === '') {
            try {
                $storage = $this->getStorageManager()->defaultDisk()->diskCode();
            } catch (\Throwable) {
                return ['error' => (string)__('默认存储磁盘不可用')];
            }
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,189}$/', $storage)) {
            return ['error' => (string)__('存储源名称无效')];
        }
        try {
            $storage = $this->getStorageManager()->canonicalizeDiskCode($storage);
        } catch (\Throwable) {
            return ['error' => (string)__('存储磁盘不存在或未启用')];
        }

        return $this->executeStorageCommand($storage, $cmd, $src, $uploadedFiles, $opts);
    }

    /** @return array<string,mixed> */
    private function handleStorages(): array
    {
        $storages = [];
        $storageCatalog = $this->getStorageCatalog();
        $directoryManager = $this->getStorageDirectoryManager();
        if ($storageCatalog === null) {
            return ['error' => (string)__('存储源清单不可用，请稍后重试')];
        }
        try {
            foreach ($storageCatalog->all() as $item) {
                $name = trim((string)($item['disk_code'] ?? $item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $available = false;
                $capabilities = $this->unavailableCapabilities();
                if ($directoryManager !== null) {
                    try {
                        $capabilities = $this->normalizeStorageCapabilities(
                            $directoryManager->capabilities($name),
                            $name,
                        );
                        $available = $capabilities['browse'];
                    } catch (\Throwable) {
                    }
                }
                $storages[] = [
                    'name' => $name,
                    'display_name' => $item['info']['display_name'] ?? $name,
                    'driver' => $item['driver'] ?? 'unknown',
                    'is_default' => (bool)($item['is_default'] ?? false),
                    'available' => $available,
                    'capabilities' => $capabilities,
                ];
            }
        } catch (\Throwable) {
            return ['error' => (string)__('存储源清单读取失败，请稍后重试')];
        }
        if ($storages === []) {
            return ['error' => (string)__('未配置可用的存储源')];
        }

        return ['storages' => $storages];
    }

    /** @param array<string,bool> $capabilities @return array<string,bool> */
    private function normalizeStorageCapabilities(array $capabilities, string $storage = ''): array
    {
        $normalized = array_replace($this->unavailableCapabilities(), $capabilities);
        if (!array_key_exists('move_file', $capabilities)) {
            $normalized['move_file'] = (bool)($normalized['rename_file'] ?? false);
        }
        foreach (array_keys($this->unavailableCapabilities()) as $name) {
            $normalized[$name] = (bool)($normalized[$name] ?? false);
        }
        // The legacy AI draw/save pipeline is intentionally local-media only.
        // Keep it available there without advertising it for remote disks.
        $normalized['ai_edit'] = $storage === self::DEFAULT_DISK;
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function executeStorageCommand(
        string $storage,
        string $cmd,
        array $src,
        array $uploadedFiles,
        array $opts,
    ): array {
        $manager = $this->getStorageDirectoryManager();
        if ($manager === null) {
            return ['error' => (string)__('存储提供者管理能力不可用')];
        }
        $actorId = max(0, (int)($opts['actor_id'] ?? 0)) ?: null;
        try {
            return match ($cmd) {
                'open' => $this->handleStorageOpen($storage, $src, $manager, $actorId),
                'tree' => $this->handleStorageTree($storage, $src, $manager),
                'mkdir' => $this->handleStorageMkdir($storage, $src, $manager),
                'rename' => $this->handleStorageRename($storage, $src, $manager, $actorId),
                'move' => $this->handleStorageMove($storage, $src, $manager, $actorId),
                'rm' => $this->handleStorageRemove($storage, $src, $manager, $actorId),
                'upload' => $this->handleStorageUpload($storage, $src, $uploadedFiles, $opts, $manager, $actorId),
                'asset_metadata' => $this->handleStorageAssetMetadata($storage, $src, $actorId, $manager),
                'file' => $this->handleStorageResource($storage, $src, false, $actorId, $manager),
                'tmb' => $this->handleStorageResource($storage, $src, true, $actorId, $manager),
                default => ['error' => (string)__('当前存储提供者暂不支持此操作')],
            };
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return ['error' => $exception->getMessage()];
        } catch (\Throwable) {
            return ['error' => (string)__('存储提供者操作失败')];
        }
    }

    /** @return array<string,mixed> */
    private function handleStorageUpload(
        string $storage,
        array $src,
        array $files,
        array $opts,
        StorageDirectoryManagerInterface $manager,
        ?int $actorId,
    ): array {
        if ($files === []) {
            return ['error' => (string)__('没有收到上传文件')];
        }
        $this->assertStorageCapability($storage, 'browse', $manager);
        $this->assertStorageCapability($storage, 'upload', $manager);
        $directory = $this->decodeStorageTarget($src, true);
        $this->assertStorageDirectoryExists($storage, $directory, $manager);
        $this->assertUploadDestinationsAvailable($storage, $directory, $files, $manager);
        $locale = $this->requiredLocale($src);
        // Resolve the frozen request/access context before writing anything so
        // an invalid Worker or controller context cannot leave orphaned files.
        $access = $this->fileAccessContext($locale, $actorId);
        $metadata = [
            'display_name' => $src['display_name'] ?? '',
            'default_alt' => $src['default_alt'] ?? '',
            'description' => $src['description'] ?? '',
            'default_caption' => $src['default_caption'] ?? '',
        ];
        $metadataByFile = $this->parseUploadMetadata($src['upload_metadata'] ?? []);
        $requestedVisibility = strtolower(trim((string)($src['visibility'] ?? '')));
        $visibility = $requestedVisibility !== ''
            ? $requestedVisibility
            : $this->defaultStorageVisibility($storage);
        if (!in_array($visibility, [
            FileAssetLibraryInterface::VISIBILITY_PUBLIC,
            FileAssetLibraryInterface::VISIBILITY_PRIVATE,
        ], true)) {
            throw new \InvalidArgumentException((string)__('文件资源可见性无效。'));
        }
        $assetMetadata = [];
        if ($visibility === FileAssetLibraryInterface::VISIBILITY_PRIVATE) {
            if (($actorId ?? 0) < 1) {
                throw new \InvalidArgumentException((string)__('私有文件上传必须具有明确操作人。'));
            }
            $assetMetadata['access_policy'] = [
                'owner_actor_id' => $actorId,
                'policy_revision' => 1,
            ];
        }

        $added = $this->getAssetUploadService()->uploadFiles(
            $files,
            $storage,
            $directory,
            $locale,
            $access,
            $metadata,
            $visibility,
            is_array($opts['allowed_mimes'] ?? null) ? array_values($opts['allowed_mimes']) : [],
            max(1, min(self::MAX_UPLOAD_BYTES, (int)($opts['max_upload_bytes'] ?? self::MAX_UPLOAD_BYTES))),
            $metadataByFile,
            is_array($opts['allowed_extensions'] ?? null) ? array_values($opts['allowed_extensions']) : [],
            $assetMetadata,
        );
        if ($added === []) {
            return ['error' => (string)__('上传失败')];
        }

        try {
            foreach ($added as &$item) {
                $path = trim((string)($item['object_key'] ?? ''), '/');
                if ($path === '') {
                    throw new \RuntimeException((string)__('上传结果缺少存储对象键。'));
                }
                $description = $this->getAssetCatalogService()->describe(
                    $storage,
                    $path,
                    $locale,
                    $access,
                );
                $item = array_replace($item, $description, [
                    'hash' => $this->encodeHash($path),
                    'phash' => $this->encodeHash(trim(dirname($path), '/.')),
                    'ts' => time(),
                    'path' => $path,
                ]);
            }
            unset($item);
        } catch (\Throwable $throwable) {
            unset($item);
            $rollbackFailed = false;
            foreach (array_reverse($added) as $uploaded) {
                $objectKey = trim((string)($uploaded['object_key'] ?? ''), '/');
                if ($objectKey === '') {
                    $rollbackFailed = true;
                    continue;
                }
                try {
                    $this->getAssetLibrary()->deleteObject($storage, $objectKey, $access);
                } catch (\Throwable) {
                    $rollbackFailed = true;
                }
            }
            if ($rollbackFailed) {
                throw new \RuntimeException(
                    (string)__('上传完成后读取资源详情失败，且部分文件无法自动回收，请立即刷新并人工清理。'),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }
        return ['added' => $added];
    }

    /** @return array<string,mixed> */
    private function handleStorageAssetMetadata(
        string $storage,
        array $src,
        ?int $actorId,
        StorageDirectoryManagerInterface $manager,
    ): array {
        $this->assertStorageCapability($storage, 'browse', $manager);
        $assetId = trim((string)($src['asset_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $assetId)) {
            return ['error' => (string)__('文件资源标识无效')];
        }
        $assetRevision = filter_var(
            $src['asset_revision'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($assetRevision === false) {
            return ['error' => (string)__('文件资源修订版本无效')];
        }
        $path = $this->decodeStorageTarget($src, false);
        $entry = $this->findStorageEntry($storage, $path, $manager);
        if ($entry === null || ($entry['type'] ?? '') === 'directory') {
            throw new \InvalidArgumentException((string)__('目标文件不存在'));
        }
        $locale = $this->requiredLocale($src);
        $access = $this->fileAccessContext($locale, $actorId);
        $this->getAssetMetadataService()->save($assetId, $storage, $path, $locale, $access, $assetRevision, [
            'display_name' => $src['display_name'] ?? '',
            'default_alt' => $src['default_alt'] ?? '',
            'description' => $src['description'] ?? '',
            'default_caption' => $src['default_caption'] ?? '',
            'translation_state' => FileAssetLibraryInterface::TRANSLATION_REVIEWED,
            'translation_origin' => FileAssetLibraryInterface::TRANSLATION_MANUAL,
        ]);
        $description = $this->getAssetCatalogService()->describe(
            $storage,
            $path,
            $locale,
            $access,
        );
        return ['changed' => [$this->encodeHash($path) => $description]];
    }

    /** @return array<string,mixed> */
    private function handleStorageOpen(
        string $storage,
        array $src,
        StorageDirectoryManagerInterface $manager,
        ?int $actorId,
    ): array {
        $this->assertStorageCapability($storage, 'browse', $manager);
        $relative = $this->decodeStorageTarget($src, true);
        $this->assertStorageDirectoryExists($storage, $relative, $manager);
        $entries = $manager->list($storage, $relative, false);
        $locale = $this->requiredLocale($src);
        $access = $this->fileAccessContext($locale, $actorId);
        $files = [];
        foreach ($entries as $entry) {
            $file = $this->buildStorageFileInfo($entry);
            if (($entry['type'] ?? '') !== 'directory') {
                try {
                    $file = array_replace($file, $this->getAssetCatalogService()->describe(
                        $storage,
                        (string)($entry['path'] ?? ''),
                        $locale,
                        $access,
                    ));
                } catch (FileAccessDeniedException) {
                    continue;
                }
            }
            $files[] = $file;
        }

        return [
            'cwd' => $this->buildStorageFileInfo([
                'path' => $relative,
                'name' => $relative === '' ? 'Media Files' : basename($relative),
                'type' => 'directory',
                'size' => 0,
                'last_modified' => null,
            ]),
            'files' => $files,
            'tree' => $this->buildStorageTree($storage, $relative, $manager, $entries),
            'root' => $this->encodeHash(''),
            'capabilities' => $this->normalizeStorageCapabilities($manager->capabilities($storage), $storage),
        ];
    }

    /** @return array<string,mixed> */
    private function handleStorageResource(
        string $storage,
        array $src,
        bool $thumbnail,
        ?int $actorId,
        StorageDirectoryManagerInterface $manager,
    ): array
    {
        if ($thumbnail) {
            $this->assertStorageCapability($storage, 'preview', $manager);
        } else {
            $this->assertStorageCapability($storage, 'download', $manager);
        }
        $path = $this->decodeStorageTarget($src, false);
        $entry = $this->findStorageEntry($storage, $path, $manager);
        if ($entry === null || ($entry['type'] ?? '') === 'directory') {
            throw new \InvalidArgumentException((string)__('目标文件不存在'));
        }
        $locale = $this->requiredLocale($src);
        $options = $thumbnail
            ? new StorageUrlOptions(StorageUrlOptions::KIND_IMAGE_VARIANT, 300, 320, 320, null, 'contain')
            : null;
        $resourceUrl = $this->getAssetCatalogService()->resolveResourceUrl(
            $storage,
            $path,
            $this->fileAccessContext($locale, $actorId),
            $options,
        );
        $forceDownload = !$thumbnail
            && filter_var($src['download'] ?? false, FILTER_VALIDATE_BOOL);
        if (!$forceDownload) {
            return ['redirect_url' => $resourceUrl];
        }

        $handle = $this->getStorageManager()->disk($storage)->openRead($path);
        return [
            'pointer' => $handle->resource(),
            'resource_handle' => $handle,
            'force_download' => true,
            'info' => [
                'name' => (string)($entry['name'] ?? basename($path)),
                'size' => max(0, (int)($entry['size'] ?? 0)),
            ],
            'header' => ['Content-Type: ' . (
                $this->normalizeStorageMime($entry['mime_type'] ?? '', $path)
            )],
        ];
    }

    private function fileAccessContext(string $locale, ?int $actorId = null): FileAccessContext
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope === null) {
            // Authenticated backend MediaManager requests do not traverse the
            // storefront Scope resolver. Use the explicit top-level identity
            // for their asset library access without mutating RequestContext;
            // non-backend/internal callers without an actor still fail closed.
            if (($actorId ?? 0) < 1) {
                throw new \RuntimeException((string)__('媒体文件访问缺少冻结的 ScopeIdentity。'));
            }
            $scope = ScopeIdentity::global();
        }
        return new FileAccessContext(
            $scope,
            $this->normalizeLocale($locale),
            $actorId !== null && $actorId > 0 ? $actorId : null,
            [],
            'media_manager',
        );
    }

    /** @param array<string,mixed> $src */
    private function requiredLocale(array $src): string
    {
        $locale = trim((string)($src['locale_code'] ?? ''));
        if ($locale === '') {
            throw new \InvalidArgumentException((string)__('媒体文件操作缺少显式语言。'));
        }
        return $this->normalizeLocale($locale);
    }

    /** @return array<string,mixed> */
    private function handleStorageTree(
        string $storage,
        array $src,
        StorageDirectoryManagerInterface $manager,
    ): array {
        $this->assertStorageCapability($storage, 'browse', $manager);
        $relative = $this->decodeStorageTarget($src, true);
        $this->assertStorageDirectoryExists($storage, $relative, $manager);
        $tree = [];
        foreach ($manager->list($storage, $relative, false) as $entry) {
            if (($entry['type'] ?? '') !== 'directory') {
                continue;
            }
            $info = $this->buildStorageFileInfo($entry);
            $info['dirs'] = 1;
            $tree[] = $info;
        }
        return ['tree' => $tree];
    }

    /** @return array<string,mixed> */
    private function handleStorageMkdir(
        string $storage,
        array $src,
        StorageDirectoryManagerInterface $manager,
    ): array {
        $this->assertStorageCapability($storage, 'browse', $manager);
        $this->assertStorageCapability($storage, 'create_directory', $manager);
        $parent = $this->decodeStorageTarget($src, true);
        $this->assertStorageDirectoryExists($storage, $parent, $manager);
        $name = $this->sanitizeLeafName((string)($src['name'] ?? ''));
        if ($name === null) {
            return ['error' => (string)__('文件夹名称不能为空')];
        }
        $path = $this->normalizeRelativePath(($parent === '' ? '' : $parent . '/') . $name);
        if (!$manager->makeDirectory($storage, $path)) {
            return ['error' => (string)__('文件夹创建失败或已存在')];
        }
        return ['added' => [$this->buildStorageFileInfo([
            'path' => $path,
            'name' => $name,
            'type' => 'directory',
            'size' => 0,
            'last_modified' => time(),
        ])]];
    }

    /** @return array<string,mixed> */
    private function handleStorageRename(
        string $storage,
        array $src,
        StorageDirectoryManagerInterface $manager,
        ?int $actorId,
    ): array {
        $this->assertStorageCapability($storage, 'browse', $manager);
        $from = $this->decodeStorageTarget($src, false);
        $name = $this->sanitizeLeafName((string)($src['name'] ?? ''));
        if ($name === null) {
            return ['error' => (string)__('新名称不能为空')];
        }
        $entry = $this->findStorageEntry($storage, $from, $manager);
        if ($entry === null) {
            return ['error' => (string)__('目标项目不存在')];
        }
        $parent = trim(dirname($from), '/.');
        $to = $this->normalizeRelativePath(($parent === '' ? '' : $parent . '/') . $name);
        if ($this->findStorageEntry($storage, $to, $manager) !== null) {
            return ['error' => (string)__('目标名称已存在')];
        }
        $access = $this->fileAccessContext($this->requiredLocale($src), $actorId);
        if (($entry['type'] ?? '') === 'directory') {
            $this->assertStorageCapability($storage, 'rename_directory', $manager);
            $this->getAssetLibrary()->moveDirectory($storage, $from, $to, $access);
        } else {
            $this->assertStorageCapability($storage, 'rename_file', $manager);
            $this->getAssetLibrary()->moveObject($storage, $from, $to, $access);
        }
        $entry['path'] = $to;
        $entry['name'] = $name;
        return ['added' => [$this->buildStorageFileInfo($entry)]];
    }

    /** @return array<string,mixed> */
    private function handleStorageMove(
        string $storage,
        array $src,
        StorageDirectoryManagerInterface $manager,
        ?int $actorId,
    ): array {
        $this->assertStorageCapability($storage, 'browse', $manager);
        $this->assertStorageCapability($storage, 'move_file', $manager);
        $targets = $this->requiredTargetHashes($src['targets'] ?? [], (string)__('未选择移动文件'));
        $destination = $this->decodeStorageTarget($src, true);
        $this->assertStorageDirectoryExists($storage, $destination, $manager);
        $access = $this->fileAccessContext($this->requiredLocale($src), $actorId);

        $plan = [];
        $plannedDestinations = [];
        foreach ($targets as $hash) {
            if (!is_string($hash) || $hash === '') {
                return ['error' => (string)__('目标路径无效')];
            }
            $from = $this->decodeStorageHash($hash, false);
            $entry = $this->findStorageEntry($storage, $from, $manager);
            if ($entry === null || ($entry['type'] ?? '') === 'directory') {
                return ['error' => (string)__('只能移动普通文件')];
            }
            if (trim(dirname($from), '/.') === $destination) {
                return ['error' => (string)__('文件已位于目标文件夹')];
            }
            $name = basename($from);
            $to = $this->normalizeRelativePath(($destination === '' ? '' : $destination . '/') . $name);
            if (isset($plannedDestinations[$to]) || $this->findStorageEntry($storage, $to, $manager) !== null) {
                return ['error' => (string)__('目标文件已存在')];
            }
            $plannedDestinations[$to] = true;
            $plan[] = ['hash' => $hash, 'from' => $from, 'to' => $to, 'name' => $name, 'entry' => $entry];
        }

        $completed = [];
        foreach ($plan as $move) {
            try {
                $this->getAssetLibrary()->moveObject($storage, $move['from'], $move['to'], $access);
                $completed[] = $move;
            } catch (\Throwable) {
                $rollbackFailed = false;
                foreach (array_reverse($completed) as $done) {
                    try {
                        $this->getAssetLibrary()->moveObject($storage, $done['to'], $done['from'], $access);
                    } catch (\Throwable) {
                        $rollbackFailed = true;
                    }
                }
                if ($rollbackFailed) {
                    return ['error' => (string)__('文件移动失败，且部分文件无法自动恢复，请立即刷新并人工处理。')];
                }
                return ['error' => (string)__('文件移动失败，请刷新后重试')];
            }
        }

        $added = [];
        $removed = [];
        foreach ($plan as $move) {
            $entry = $move['entry'];
            $entry['path'] = $move['to'];
            $entry['name'] = $move['name'];
            $added[] = $this->buildStorageFileInfo($entry);
            $removed[] = $move['hash'];
        }
        return ['added' => $added, 'removed' => $removed];
    }

    /** @return array<string,mixed> */
    private function handleStorageRemove(
        string $storage,
        array $src,
        StorageDirectoryManagerInterface $manager,
        ?int $actorId,
    ): array {
        $this->assertStorageCapability($storage, 'browse', $manager);
        $targets = $this->requiredTargetHashes($src['targets'] ?? [], (string)__('未选择删除项目'));
        $plan = [];
        $requiresDirectoryDelete = false;
        $requiresFileDelete = false;
        foreach ($targets as $hash) {
            $path = $this->decodeStorageHash($hash, false);
            $entry = $this->findStorageEntry($storage, $path, $manager);
            if ($entry === null) {
                return ['error' => (string)__('部分项目不存在，请刷新后重试')];
            }
            $directory = ($entry['type'] ?? '') === 'directory';
            $requiresDirectoryDelete = $requiresDirectoryDelete || $directory;
            $requiresFileDelete = $requiresFileDelete || !$directory;
            $plan[] = ['hash' => $hash, 'path' => $path, 'directory' => $directory];
        }
        if ($requiresDirectoryDelete) {
            $this->assertStorageCapability($storage, 'delete_directory', $manager);
        }
        if ($requiresFileDelete) {
            $this->assertStorageCapability($storage, 'delete_file', $manager);
        }
        $access = $this->fileAccessContext($this->requiredLocale($src), $actorId);
        $removed = [];
        foreach ($plan as $item) {
            try {
                if ($item['directory']) {
                    $this->getAssetLibrary()->deleteDirectory($storage, $item['path'], $access);
                } else {
                    $this->getAssetLibrary()->deleteObject($storage, $item['path'], $access);
                }
            } catch (\Throwable $throwable) {
                if ($removed !== []) {
                    throw new \RuntimeException(
                        (string)__('批量删除未全部完成，部分项目已删除，请立即刷新并人工核对。'),
                        0,
                        $throwable,
                    );
                }
                throw $throwable;
            }
            $removed[] = $item['hash'];
        }
        return ['removed' => $removed];
    }

    /**
     * @param list<array<string,mixed>> $currentEntries
     * @return list<array<string,mixed>>
     */
    private function buildStorageTree(
        string $storage,
        string $current,
        StorageDirectoryManagerInterface $manager,
        array $currentEntries,
    ): array {
        $rootEntries = $current === '' ? $currentEntries : $manager->list($storage, '', false);
        $root = $this->buildStorageFileInfo([
            'path' => '', 'name' => 'Media Files', 'type' => 'directory', 'size' => 0, 'last_modified' => null,
        ]);
        $root['dirs'] = $this->containsStorageDirectory($rootEntries) ? 1 : 0;
        $tree = [$root];
        $seen = ['' => true];
        foreach ($rootEntries as $entry) {
            if (($entry['type'] ?? '') !== 'directory') {
                continue;
            }
            $info = $this->buildStorageFileInfo($entry);
            $info['dirs'] = 1;
            $tree[] = $info;
            $seen[(string)$entry['path']] = true;
        }
        if ($current !== '') {
            $path = '';
            foreach (explode('/', $current) as $segment) {
                $path = $path === '' ? $segment : $path . '/' . $segment;
                if (!isset($seen[$path])) {
                    $info = $this->buildStorageFileInfo([
                        'path' => $path, 'name' => $segment, 'type' => 'directory', 'size' => 0, 'last_modified' => null,
                    ]);
                    $info['dirs'] = 1;
                    $tree[] = $info;
                    $seen[$path] = true;
                }
            }
            foreach ($currentEntries as $entry) {
                if (($entry['type'] ?? '') !== 'directory' || isset($seen[$entry['path']])) {
                    continue;
                }
                $info = $this->buildStorageFileInfo($entry);
                $info['dirs'] = 1;
                $tree[] = $info;
            }
        }
        return $tree;
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function buildStorageFileInfo(array $entry): array
    {
        $path = $this->normalizeRelativePath((string)($entry['path'] ?? ''));
        $isDirectory = ($entry['type'] ?? '') === 'directory';
        $parent = $path === '' ? null : trim(dirname($path), '/.');
        $modified = $entry['last_modified'] ?? null;
        return [
            'hash' => $this->encodeHash($path),
            'name' => $path === '' ? 'Media Files' : (string)($entry['name'] ?? basename($path)),
            'phash' => $parent === null ? null : $this->encodeHash($parent),
            'mime' => $isDirectory
                ? 'directory'
                : $this->normalizeStorageMime($entry['mime_type'] ?? '', $path),
            'size' => $isDirectory ? 0 : max(0, (int)($entry['size'] ?? 0)),
            'ts' => is_numeric($modified) ? (int)$modified : null,
            'path' => $path,
        ];
    }

    private function detectStorageMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimes = $ext !== '' ? MimeTypes::getMimeTypes($ext) : [];
        return $mimes[0] ?? 'application/octet-stream';
    }

    private function normalizeStorageMime(mixed $value, string $path): string
    {
        $mime = strtolower(trim((string)$value));
        if (preg_match(
            '~^[a-z0-9][a-z0-9!#$&^_.+-]{0,94}/[a-z0-9][a-z0-9!#$&^_.+-]{0,94}$~D',
            $mime,
        ) !== 1) {
            return $this->detectStorageMime($path);
        }
        return $mime;
    }

    private function decodeStorageTarget(array $src, bool $allowRoot): string
    {
        $target = trim((string)($src['target'] ?? ''));
        if ($target !== '') {
            return $this->decodeStorageHash($target, $allowRoot);
        }
        $path = $this->normalizeRelativePath((string)($src['path'] ?? ''));
        if (!$allowRoot && $path === '') {
            throw new \InvalidArgumentException((string)__('目标目录不能为空'));
        }
        return $path;
    }

    private function decodeStorageHash(string $hash, bool $allowRoot): string
    {
        $decoded = $this->decodeHash($hash);
        if ($decoded === null) {
            throw new \InvalidArgumentException((string)__('目标路径无效'));
        }
        $decoded = $this->normalizeRelativePath($decoded);
        if (!$allowRoot && $decoded === '') {
            throw new \InvalidArgumentException((string)__('不允许操作存储根目录'));
        }
        return $decoded;
    }

    /** @return array<string,mixed>|null */
    private function findStorageEntry(
        string $storage,
        string $path,
        StorageDirectoryManagerInterface $manager,
    ): ?array {
        $parent = trim(dirname($path), '/.');
        foreach ($manager->list($storage, $parent, false) as $entry) {
            if (($entry['path'] ?? '') === $path) {
                return $entry;
            }
        }
        return null;
    }

    private function assertStorageDirectoryExists(
        string $storage,
        string $path,
        StorageDirectoryManagerInterface $manager,
    ): void {
        if ($path === '') {
            return;
        }
        $entry = $this->findStorageEntry($storage, $path, $manager);
        if ($entry === null || ($entry['type'] ?? '') !== 'directory') {
            throw new \InvalidArgumentException((string)__('目标文件夹不存在'));
        }
    }

    /** @param list<array<string,mixed>> $entries */
    private function containsStorageDirectory(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') === 'directory') {
                return true;
            }
        }
        return false;
    }

    private function assertStorageCapability(
        string $storage,
        string $capability,
        StorageDirectoryManagerInterface $manager,
    ): void {
        $capabilities = $this->normalizeStorageCapabilities($manager->capabilities($storage), $storage);
        if (($capabilities[$capability] ?? false) !== true) {
            throw new \InvalidArgumentException((string)__('当前存储提供者不支持此操作'));
        }
    }

    private function defaultStorageVisibility(string $storage): string
    {
        $visibility = $this->getStorageManager()->disk($storage)->snapshot()->visibility();
        if (!in_array($visibility, [
            FileAssetLibraryInterface::VISIBILITY_PUBLIC,
            FileAssetLibraryInterface::VISIBILITY_PRIVATE,
        ], true)) {
            throw new \RuntimeException((string)__('存储磁盘可见性配置无效。'));
        }
        return $visibility;
    }

    /** @return array<string,bool> */
    private function unavailableCapabilities(): array
    {
        return [
            'browse' => false,
            'create_directory' => false,
            'rename_directory' => false,
            'delete_directory' => false,
            'rename_file' => false,
            'move_file' => false,
            'delete_file' => false,
            'upload' => false,
            'download' => false,
            'preview' => false,
            'copy_url' => false,
            'ai_edit' => false,
        ];
    }

    private function encodeHash(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $encodedPath = $relativePath === '' ? '/' : $relativePath;
        return 'mm_' . rtrim(strtr(base64_encode($encodedPath), '+/', '-_'), '=');
    }

    private function decodeHash(string $hash): ?string
    {
        if (strlen($hash) > self::MAX_TARGET_HASH_BYTES || !str_starts_with($hash, 'mm_')) {
            return null;
        }
        $base64Url = substr($hash, 3);
        if ($base64Url === '' || preg_match('/^[A-Za-z0-9_-]+$/', $base64Url) !== 1) {
            return null;
        }
        $padded = $base64Url . str_repeat('=', (4 - strlen($base64Url) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $base64Url
        ) {
            return null;
        }
        return $decoded === '/' ? '' : trim(str_replace('\\', '/', $decoded), '/');
    }

    private function normalizeRelativePath(string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return '';
        }
        if (
            strlen($relative) > 768
            || preg_match('//u', $relative) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $relative)
        ) {
            throw new \InvalidArgumentException((string)__('目标路径无效'));
        }
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException((string)__('目标路径无效'));
            }
        }
        return implode('/', $segments);
    }

    private function sanitizeLeafName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        if (strlen($name) > 255
            || preg_match('//u', $name) !== 1
            || str_contains($name, '/') || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $name)
            || basename($name) !== $name
        ) {
            return null;
        }
        return $name;
    }

    /** @return list<string> */
    private function requiredTargetHashes(mixed $targets, string $emptyMessage): array
    {
        if (!is_array($targets) || $targets === []) {
            throw new \InvalidArgumentException($emptyMessage);
        }
        if (!array_is_list($targets)
            || count($targets) > MediaAssetUploadService::MAX_UPLOAD_FILES
        ) {
            throw new \InvalidArgumentException((string)__('选择项目数量或格式无效'));
        }
        foreach ($targets as $hash) {
            if (!is_string($hash) || $hash === '' || strlen($hash) > self::MAX_TARGET_HASH_BYTES) {
                throw new \InvalidArgumentException((string)__('目标路径无效'));
            }
        }
        return $targets;
    }

    /** @return list<array<string,mixed>> */
    private function parseUploadMetadata(mixed $metadata): array
    {
        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException((string)__('逐文件元数据格式无效。'));
            }
            $metadata = $decoded;
        }
        if ($metadata === null || $metadata === '') {
            return [];
        }
        if (!is_array($metadata) || !array_is_list($metadata)) {
            throw new \InvalidArgumentException((string)__('逐文件元数据格式无效。'));
        }
        if (count($metadata) > MediaAssetUploadService::MAX_UPLOAD_FILES) {
            throw new \InvalidArgumentException((string)__('单次上传文件数量超过限制。'));
        }
        foreach ($metadata as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException((string)__('逐文件元数据格式无效。'));
            }
        }
        return $metadata;
    }

    /** @param array<string,mixed> $files */
    private function assertUploadDestinationsAvailable(
        string $storage,
        string $directory,
        array $files,
        StorageDirectoryManagerInterface $manager,
    ): void {
        $existing = [];
        foreach ($manager->list($storage, $directory, false) as $entry) {
            $existing[mb_strtolower((string)($entry['name'] ?? basename((string)($entry['path'] ?? ''))))] = true;
        }
        $planned = [];
        foreach ($this->uploadedFileNames($files) as $name) {
            $name = $this->sanitizeLeafName($name);
            if ($name === null) {
                throw new \InvalidArgumentException((string)__('上传文件名无效。'));
            }
            $key = mb_strtolower($name);
            if (isset($existing[$key]) || isset($planned[$key])) {
                throw new \InvalidArgumentException((string)__('目标文件已存在：%{1}', [$name]));
            }
            $planned[$key] = true;
        }
    }

    /** @param array<string,mixed> $files @return list<string> */
    private function uploadedFileNames(array $files): array
    {
        if (array_is_list($files)) {
            $names = [];
            foreach ($files as $file) {
                if (is_array($file)) {
                    $names[] = (string)($file['name'] ?? '');
                }
            }
            return $names;
        }
        if (is_array($files['name'] ?? null)) {
            return array_map('strval', array_values($files['name']));
        }
        return [(string)($files['name'] ?? '')];
    }

    private function normalizeLocale(string $locale): string
    {
        return $this->getAssetLibrary()->normalizeLocale(trim($locale));
    }
}
