<?php

declare(strict_types=1);

namespace Weline\FileManager\Api;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\Storage\Api\Data\StorageUrlOptions;

/**
 * Stable, data-only boundary for modules that manage persisted file assets.
 *
 * Implementations own FileAsset models and storage mutation services. Consumers
 * receive safe associative records and never depend on FileManager internals.
 */
interface FileAssetLibraryInterface
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_PRIVATE = 'private';
    public const TRANSLATION_REVIEWED = 'reviewed';
    public const TRANSLATION_MANUAL = 'manual';

    /** @return array<string,mixed> */
    public function describe(
        string $diskCode,
        string $objectKey,
        string $localeCode,
        FileAccessContext $access,
    ): array;

    public function resolveResourceUrl(
        string $diskCode,
        string $objectKey,
        FileAccessContext $access,
        ?StorageUrlOptions $options = null,
    ): string;

    /**
     * @param resource $source
     * @param array<string,mixed> $localeMetadata
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function upload(
        string $diskCode,
        string $objectKey,
        mixed $source,
        string $originalName,
        string $mimeType,
        string $localeCode,
        FileAccessContext $access,
        array $localeMetadata,
        string $visibility = self::VISIBILITY_PUBLIC,
        array $metadata = [],
        ?int $width = null,
        ?int $height = null,
    ): array;

    /**
     * @param array<string,mixed> $metadata
     * @param int $expectedRevision Revision returned by describe(); stale edits fail closed.
     * @return array<string,mixed>
     */
    public function saveMetadata(
        string $assetId,
        string $diskCode,
        string $objectKey,
        string $localeCode,
        FileAccessContext $access,
        int $expectedRevision,
        array $metadata,
    ): array;

    public function moveObject(
        string $diskCode,
        string $from,
        string $to,
        FileAccessContext $access,
    ): void;

    public function moveDirectory(
        string $diskCode,
        string $from,
        string $to,
        FileAccessContext $access,
    ): void;

    public function deleteObject(
        string $diskCode,
        string $objectKey,
        FileAccessContext $access,
    ): void;

    public function deleteDirectory(
        string $diskCode,
        string $prefix,
        FileAccessContext $access,
    ): void;

    public function normalizeLocale(string $localeCode): string;
}
