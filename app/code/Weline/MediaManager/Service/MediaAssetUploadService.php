<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\MediaManager\Helper\MimeTypes;
use Weline\Storage\Api\Runtime\StorageRequestResourceFactoryInterface;

final class MediaAssetUploadService
{
    // One legacy multipart request must leave 2 MiB of the default 16 MiB WLS
    // body budget for framing and metadata. Larger assets use the bounded
    // resumable protocol and never enter a Worker as one request body.
    public const MAX_UPLOAD_BYTES = 14 * 1024 * 1024;
    public const MAX_ASSET_UPLOAD_BYTES = 512 * 1024 * 1024;
    public const MAX_UPLOAD_FILES = 100;
    public const MAX_DISPLAY_NAME_CHARACTERS = 255;
    public const MAX_DEFAULT_ALT_CHARACTERS = 512;
    public const MAX_DESCRIPTION_BYTES = 8000;
    public const MAX_DEFAULT_CAPTION_BYTES = 2000;

    public function __construct(
        private readonly FileAssetLibraryInterface $assets,
        private readonly StorageRequestResourceFactoryInterface $resourceFactory,
    ) {
    }

    /**
     * @param array<string,mixed> $files
     * @param array<string,mixed> $defaultMetadata
     * @param list<array<string,mixed>> $metadataByFile
     * @param list<string> $allowedMimes
     * @param list<string> $allowedExtensions
     * @param array<string,mixed> $assetMetadata
     * @return list<array<string,mixed>>
     */
    public function uploadFiles(
        array $files,
        string $diskCode,
        string $directory,
        string $localeCode,
        FileAccessContext $access,
        array $defaultMetadata,
        string $visibility = FileAssetLibraryInterface::VISIBILITY_PUBLIC,
        array $allowedMimes = [],
        int $maxBytes = self::MAX_UPLOAD_BYTES,
        array $metadataByFile = [],
        array $allowedExtensions = [],
        array $assetMetadata = [],
    ): array {
        $normalized = $this->normalizeFiles($files);
        if ($normalized === []) {
            throw new \InvalidArgumentException((string)__('没有收到上传文件。'));
        }
        if (count($normalized) > self::MAX_UPLOAD_FILES) {
            throw new \InvalidArgumentException((string)__('单次上传文件数量超过限制。'));
        }
        if ($metadataByFile !== [] && count($metadataByFile) !== count($normalized)) {
            throw new \InvalidArgumentException((string)__('逐文件元数据数量与上传文件数量不一致。'));
        }

        $maxBytes = max(1, min(self::MAX_ASSET_UPLOAD_BYTES, $maxBytes));
        $seenNames = [];
        $totalBytes = 0;
        foreach ($normalized as $index => &$file) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['tmp_name'] === '') {
                throw new \InvalidArgumentException((string)__('上传临时文件无效。'));
            }
            $file['name'] = $this->sanitizeName($file['name']);
            $nameKey = mb_strtolower($file['name']);
            if (isset($seenNames[$nameKey])) {
                throw new \InvalidArgumentException((string)__('同一批次包含重名文件：%{1}', [$file['name']]));
            }
            $seenNames[$nameKey] = true;

            $sealed = $this->sealUploadTemporaryFile($file['tmp_name']);
            $bytes = $sealed['size'];
            if ($bytes > $maxBytes) {
                throw new \InvalidArgumentException((string)__('上传文件超过服务端大小限制：%{1}', [$file['name']]));
            }
            $file['size'] = (int)$bytes;
            $file['temp_dev'] = $sealed['dev'];
            $file['temp_ino'] = $sealed['ino'];
            $totalBytes += $file['size'];
            if ($totalBytes > $maxBytes) {
                throw new \InvalidArgumentException((string)__('单次上传文件总大小超过服务端限制。'));
            }
            $file['detected_mime'] = $this->detectMime($file['tmp_name']);
            if (!$this->isMimeAllowed($file['detected_mime'], $allowedMimes)) {
                throw new \InvalidArgumentException((string)__('上传文件的 MIME 类型不允许：%{1}', [$file['name']]));
            }
            if (!$this->isExtensionAllowed($file['name'], $file['detected_mime'], $allowedExtensions)) {
                throw new \InvalidArgumentException((string)__('上传文件的扩展名与内容类型不匹配：%{1}', [$file['name']]));
            }
            [$file['width'], $file['height']] = $this->imageDimensions(
                $file['tmp_name'],
                $file['detected_mime'],
            );
            $afterInspection = $this->sealUploadTemporaryFile($file['tmp_name']);
            if ($afterInspection !== $sealed) {
                throw new \RuntimeException((string)__('上传临时文件在校验期间发生变化。'));
            }
            $itemMetadata = is_array($file['metadata'] ?? null) ? $file['metadata'] : [];
            if (is_array($metadataByFile[$index] ?? null)) {
                $itemMetadata = array_replace($itemMetadata, $metadataByFile[$index]);
            }
            $file['metadata'] = self::normalizeMetadata(
                array_replace($defaultMetadata, $itemMetadata),
                $file['name'],
            );
        }
        unset($file);

        $added = [];
        $uploadedKeys = [];
        try {
            foreach ($normalized as $file) {
                $name = $file['name'];
                $objectKey = trim(($directory === '' ? '' : trim($directory, '/') . '/') . $name, '/');
                $stream = fopen($file['tmp_name'], 'rb');
                if ($stream === false) {
                    throw new \RuntimeException((string)__('无法读取上传临时文件：%{1}', [$name]));
                }
                $source = $this->resourceFactory->stream($stream);
                try {
                    $opened = fstat($source->stream());
                    if (!is_array($opened)
                        || (((int)($opened['mode'] ?? 0)) & 0170000) !== 0100000
                        || (int)($opened['nlink'] ?? 0) !== 1
                        || (int)($opened['dev'] ?? -1) !== $file['temp_dev']
                        || (int)($opened['ino'] ?? -1) !== $file['temp_ino']
                        || (int)($opened['size'] ?? -1) !== $file['size']
                    ) {
                        throw new \RuntimeException((string)__('上传临时文件身份已变化。'));
                    }
                    $mime = $file['detected_mime'];
                    $width = $file['width'];
                    $height = $file['height'];
                    $asset = $this->assets->upload(
                        $diskCode,
                        $objectKey,
                        $source->stream(),
                        $name,
                        $mime,
                        $localeCode,
                        $access,
                        $file['metadata'],
                        $visibility,
                        array_replace(['upload_source' => 'media_manager'], $assetMetadata),
                        $width,
                        $height,
                    );
                    $uploadedKeys[] = $objectKey;
                    $added[] = array_replace($asset, [
                        'name' => $name,
                        'mime' => $mime,
                        'size' => $file['size'],
                        'width' => $width,
                        'height' => $height,
                    ]);
                } finally {
                    $source->close();
                }
            }
        } catch (\Throwable $throwable) {
            $rollbackFailed = false;
            foreach (array_reverse($uploadedKeys) as $objectKey) {
                try {
                    $this->assets->deleteObject($diskCode, $objectKey, $access);
                } catch (\Throwable) {
                    $rollbackFailed = true;
                }
            }
            if ($rollbackFailed) {
                throw new \RuntimeException(
                    (string)__('批量上传失败，且部分已写入文件无法自动回收，请立即刷新并人工清理。'),
                    0,
                    $throwable,
                );
            }
            throw $throwable;
        }

        return $added;
    }

    /**
     * @param array<string,mixed> $files
     * @return list<array{name:string,tmp_name:string,type:string,error:int,size:int,metadata:array<string,mixed>,detected_mime?:string}>
     */
    private function normalizeFiles(array $files): array
    {
        $result = [];
        if (array_is_list($files)) {
            foreach ($files as $file) {
                if (!is_array($file)) {
                    throw new \InvalidArgumentException((string)__('上传文件载荷无效。'));
                }
                $result[] = $this->normalizeFileRecord($file);
            }
            return $result;
        }
        if (is_array($files['name'] ?? null)) {
            foreach ($files['name'] as $index => $name) {
                $result[] = $this->normalizeFileRecord([
                    'name' => $name,
                    'tmp_name' => $files['tmp_name'][$index] ?? '',
                    'type' => $files['type'][$index] ?? '',
                    'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$index] ?? 0,
                ]);
            }
            return $result;
        }
        return [$this->normalizeFileRecord($files)];
    }

    /** @param array<string,mixed> $file @return array{name:string,tmp_name:string,type:string,error:int,size:int,metadata:array<string,mixed>} */
    private function normalizeFileRecord(array $file): array
    {
        return [
            'name' => (string)($file['name'] ?? ''),
            'tmp_name' => (string)($file['tmp_name'] ?? ''),
            'type' => (string)($file['type'] ?? ''),
            'error' => (int)($file['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => max(0, (int)($file['size'] ?? 0)),
            'metadata' => is_array($file['metadata'] ?? null) ? $file['metadata'] : [],
        ];
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public static function normalizeMetadata(array $metadata, string $name): array
    {
        $displayName = self::metadataText(
            $metadata['display_name'] ?? null,
            self::MAX_DISPLAY_NAME_CHARACTERS,
            $name,
            true,
        );
        if ($displayName === '') {
            $displayName = self::metadataText(
                pathinfo($name, PATHINFO_FILENAME),
                self::MAX_DISPLAY_NAME_CHARACTERS,
                $name,
                true,
            );
        }
        $normalized = [
            'display_name' => $displayName,
            'default_alt' => self::metadataText(
                $metadata['default_alt'] ?? null,
                self::MAX_DEFAULT_ALT_CHARACTERS,
                $name,
                true,
            ),
            'description' => self::metadataText(
                $metadata['description'] ?? null,
                self::MAX_DESCRIPTION_BYTES,
                $name,
            ),
            'default_caption' => self::metadataText(
                $metadata['default_caption'] ?? null,
                self::MAX_DEFAULT_CAPTION_BYTES,
                $name,
            ),
            'translation_state' => FileAssetLibraryInterface::TRANSLATION_REVIEWED,
            'translation_origin' => FileAssetLibraryInterface::TRANSLATION_MANUAL,
        ];
        if ($normalized['display_name'] === '' || $normalized['default_alt'] === '' || $normalized['description'] === '') {
            throw new \InvalidArgumentException((string)__('文件资源必须填写显示名称、默认 alt 和资源描述：%{1}', [$name]));
        }
        return $normalized;
    }

    private static function metadataText(
        mixed $value,
        int $maxLength,
        string $name,
        bool $countCharacters = false,
    ): string
    {
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException((string)__('文件资源元数据格式无效。'));
        }
        $text = trim((string)$value);
        $validUtf8 = preg_match('//u', $text) === 1;
        $length = $countCharacters && $validUtf8 && function_exists('mb_strlen')
            ? mb_strlen($text, 'UTF-8')
            : strlen($text);
        if (
            !$validUtf8
            || $length > $maxLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1
        ) {
            throw new \InvalidArgumentException((string)__('文件资源元数据内容或长度无效：%{1}', [$name]));
        }
        return $text;
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        $validUtf8 = preg_match('//u', $name) === 1;
        $nameLength = $validUtf8 && function_exists('mb_strlen')
            ? mb_strlen($name, 'UTF-8')
            : strlen($name);
        if (
            $name === ''
            || !$validUtf8
            || $nameLength > 255
            || basename($name) !== $name
            || preg_match('/[\\x00-\\x1F\\x7F\\\\\/]/', $name)
        ) {
            throw new \InvalidArgumentException((string)__('上传文件名无效。'));
        }
        return $name;
    }

    private function detectMime(string $path): string
    {
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        } catch (\Throwable) {
        }
        return 'application/octet-stream';
    }

    /** @param list<string> $allowedMimes */
    private function isMimeAllowed(string $mime, array $allowedMimes): bool
    {
        if ($allowedMimes === []) {
            return false;
        }
        $mime = strtolower(trim($mime));
        foreach ($allowedMimes as $allowed) {
            $allowed = strtolower(trim((string)$allowed));
            if ($allowed === $mime || ($allowed === 'image' && str_starts_with($mime, 'image/'))) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $allowedExtensions */
    private function isExtensionAllowed(string $name, string $mime, array $allowedExtensions): bool
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            return false;
        }
        $expected = MimeTypes::getMimeTypes($extension);
        if (in_array($mime, $expected, true)) {
            return true;
        }
        return false;
    }

    /** @return array{0:?int,1:?int} */
    private function imageDimensions(string $path, string $mime): array
    {
        if (!str_starts_with($mime, 'image/') || !function_exists('getimagesize')) {
            return [null, null];
        }
        $size = @getimagesize($path);
        if (!is_array($size) || (int)($size[0] ?? 0) < 1 || (int)($size[1] ?? 0) < 1) {
            return [null, null];
        }
        return [(int)$size[0], (int)$size[1]];
    }

    /** @return array{dev:int,ino:int,size:int} */
    private function sealUploadTemporaryFile(string $path): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)
            || is_link($path)
            || (((int)($stat['mode'] ?? 0)) & 0170000) !== 0100000
            || (int)($stat['nlink'] ?? 0) !== 1
            || (int)($stat['dev'] ?? -1) < 0
            || (int)($stat['ino'] ?? -1) < 0
            || (int)($stat['size'] ?? -1) < 0
        ) {
            throw new \InvalidArgumentException((string)__('上传临时文件身份无效。'));
        }
        return [
            'dev' => (int)$stat['dev'],
            'ino' => (int)$stat['ino'],
            'size' => (int)$stat['size'],
        ];
    }
}
