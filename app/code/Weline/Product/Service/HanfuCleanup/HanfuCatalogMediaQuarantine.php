<?php

declare(strict_types=1);

namespace Weline\Product\Service\HanfuCleanup;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

/**
 * Two-phase file quarantine for the frozen Hanfu test catalog cleanup.
 */
final class HanfuCatalogMediaQuarantine
{
    private const SCHEMA_VERSION = 'hanfu-media-quarantine.v1';

    /** @var list<string> */
    private const PROTECTED_PREFIXES = [
        'catalog/hanfu-entities/',
        'catalog/hanfu/r2/categories/',
    ];

    private readonly FileAccessContext $access;

    /** @var array<string,true> */
    private readonly array $allowedDiskCodes;

    /**
     * @param list<string> $allowedDiskCodes
     */
    public function __construct(
        private readonly FileAssetLibraryInterface $files,
        ?FileAccessContext $access = null,
        array $allowedDiskCodes = [StorageDiskCode::BUILTIN_LOCAL_MEDIA],
    ) {
        $this->access = $access ?? new FileAccessContext(
            ScopeIdentity::global(),
            'zh_Hans_CN',
            null,
            ['catalog_maintenance'],
            'metadata_edit',
        );
        $allowed = [];
        foreach ($allowedDiskCodes as $diskCode) {
            $canonical = (string)StorageDiskCode::parse((string)$diskCode);
            $allowed[$canonical] = true;
        }
        if ($allowed === []) {
            throw new \InvalidArgumentException('hanfu_cleanup_media_disk_invalid');
        }
        $this->allowedDiskCodes = $allowed;
    }

    /**
     * @param list<array<string,mixed>> $mediaRows
     * @param array<string,array<string,mixed>> $referenceIndex
     * @return array<string,mixed>
     */
    public function plan(string $runId, array $mediaRows, array $referenceIndex): array
    {
        $runId = $this->normalizeRunId($runId);
        $moves = [];
        $preserved = [];
        $destinations = [];

        foreach ($mediaRows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('hanfu_cleanup_media_row_invalid');
            }
            $diskCode = $this->normalizeDiskCode((string)($row['disk_code'] ?? ''));
            $objectKey = $this->normalizeObjectKey((string)($row['object_key'] ?? ''));
            $blobKey = trim((string)($row['blob_key'] ?? ''));
            if ($blobKey === '' || preg_match('/[\x00-\x1F\x7F]/', $blobKey) === 1) {
                throw new \InvalidArgumentException('hanfu_cleanup_media_blob_key_invalid');
            }
            $canonical = $row;
            $canonical['disk_code'] = $diskCode;
            $canonical['object_key'] = $objectKey;
            $canonical['blob_key'] = $blobKey;

            if ($this->isProtectedPrefix($objectKey)) {
                $preserved[] = $canonical + ['preserve_reason' => 'protected_prefix'];
                continue;
            }

            $references = $referenceIndex[$blobKey] ?? null;
            if (!is_array($references)) {
                $preserved[] = $canonical + ['preserve_reason' => 'reference_index_missing'];
                continue;
            }
            if ((int)($references['media_count'] ?? 0) !== 1) {
                $preserved[] = $canonical + ['preserve_reason' => 'shared_blob'];
                continue;
            }
            if ($this->hasExternalReferences($references)) {
                $preserved[] = $canonical + ['preserve_reason' => 'external_reference'];
                continue;
            }

            $asset = $this->assertAsset($diskCode, $objectKey);
            $sha256 = strtolower(trim((string)$asset['sha256']));
            $basename = basename($objectKey);
            if ($basename === '' || $basename === '.' || $basename === '..') {
                throw new \InvalidArgumentException('hanfu_cleanup_media_path_invalid');
            }
            $to = $this->normalizeObjectKey(
                'hanfu-1688/' . $runId . '/quarantine/' . $sha256 . '/' . $basename,
            );
            $destinationIdentity = $diskCode . '|' . $to;
            if (isset($destinations[$destinationIdentity])) {
                throw new \RuntimeException('hanfu_cleanup_media_quarantine_collision');
            }
            $destinations[$destinationIdentity] = true;
            $moves[] = [
                'media_id' => (int)($row['media_id'] ?? 0),
                'disk_code' => $diskCode,
                'from' => $objectKey,
                'to' => $to,
                'blob_key' => $blobKey,
                'asset_id' => (string)$asset['asset_id'],
                'sha256' => $sha256,
                'asset_revision' => (int)$asset['asset_revision'],
            ];
        }

        usort($moves, static fn(array $left, array $right): int => [
            (string)$left['disk_code'],
            (string)$left['from'],
            (int)$left['media_id'],
        ] <=> [
            (string)$right['disk_code'],
            (string)$right['from'],
            (int)$right['media_id'],
        ]);
        usort($preserved, static fn(array $left, array $right): int => [
            (string)$left['disk_code'],
            (string)$left['object_key'],
            (int)($left['media_id'] ?? 0),
        ] <=> [
            (string)$right['disk_code'],
            (string)$right['object_key'],
            (int)($right['media_id'] ?? 0),
        ]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'status' => 'planned',
            'moves' => $moves,
            'preserved' => $preserved,
            'completed' => [],
        ];
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function quarantine(array $manifest): array
    {
        $runId = $this->assertManifest($manifest, ['planned']);
        $moves = $this->validatedMoves($manifest['moves'] ?? [], $runId, false);
        $completed = [];

        try {
            foreach ($moves as $move) {
                $this->assertAsset(
                    $move['disk_code'],
                    $move['from'],
                    $move['sha256'],
                    $move['asset_revision'],
                );
                $this->assertMissing($move['disk_code'], $move['to']);
                $this->files->moveObject(
                    $move['disk_code'],
                    $move['from'],
                    $move['to'],
                    $this->access,
                );
                $completed[] = $move;
                $asset = $this->assertAsset(
                    $move['disk_code'],
                    $move['to'],
                    $move['sha256'],
                );
                $completed[array_key_last($completed)]['quarantine_revision'] =
                    (int)$asset['asset_revision'];
            }
        } catch (\Throwable $exception) {
            $rollbackError = null;
            foreach (array_reverse($completed) as $move) {
                try {
                    $this->restoreMove($move, false);
                } catch (\Throwable $restoreException) {
                    $rollbackError ??= $restoreException;
                }
            }
            if ($rollbackError !== null) {
                throw new \RuntimeException(
                    'hanfu_cleanup_media_rollback_failed',
                    0,
                    $exception,
                );
            }
            throw $exception;
        }

        $manifest['completed'] = $completed;
        $manifest['status'] = 'quarantined';
        return $manifest;
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function restore(array $manifest): array
    {
        if (($manifest['status'] ?? null) === 'restored') {
            return $manifest;
        }
        $runId = $this->assertManifest($manifest, ['quarantined']);
        $completed = $this->validatedMoves($manifest['completed'] ?? [], $runId, true);
        $restored = [];
        foreach (array_reverse($completed) as $move) {
            $restored[] = $this->restoreMove($move, true);
        }
        $manifest['restored'] = $restored;
        $manifest['status'] = 'restored';
        return $manifest;
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function finalize(array $manifest): array
    {
        if (($manifest['status'] ?? null) === 'finalized') {
            return $manifest;
        }
        $runId = $this->assertManifest($manifest, ['quarantined']);
        $completed = $this->validatedMoves($manifest['completed'] ?? [], $runId, true);
        $finalized = [];
        foreach ($completed as $move) {
            $this->assertAsset(
                $move['disk_code'],
                $move['to'],
                $move['sha256'],
                $move['quarantine_revision'],
            );
            $this->files->deleteObject($move['disk_code'], $move['to'], $this->access);
            $this->assertMissing($move['disk_code'], $move['to']);
            $finalized[] = $move;
        }
        $manifest['finalized'] = $finalized;
        $manifest['status'] = 'finalized';
        return $manifest;
    }

    /** @param array<string,mixed> $move @return array<string,mixed> */
    private function restoreMove(array $move, bool $requireQuarantineRevision): array
    {
        $quarantine = $this->files->describe(
            $move['disk_code'],
            $move['to'],
            $this->access->localeCode,
            $this->access,
        );
        if (!$this->assetExists($quarantine)) {
            $original = $this->assertAsset(
                $move['disk_code'],
                $move['from'],
                $move['sha256'],
            );
            $move['restored_revision'] = (int)$original['asset_revision'];
            return $move;
        }

        $expectedRevision = isset($move['quarantine_revision'])
            ? (int)$move['quarantine_revision']
            : null;
        if ($requireQuarantineRevision && ($expectedRevision ?? 0) < 1) {
            throw new \RuntimeException('hanfu_cleanup_media_revision_missing');
        }
        $this->assertDescriptor(
            $quarantine,
            $move['disk_code'],
            $move['to'],
            $move['sha256'],
            $expectedRevision,
        );
        $this->assertMissing($move['disk_code'], $move['from']);
        $this->files->moveObject(
            $move['disk_code'],
            $move['to'],
            $move['from'],
            $this->access,
        );
        $original = $this->assertAsset(
            $move['disk_code'],
            $move['from'],
            $move['sha256'],
        );
        $move['restored_revision'] = (int)$original['asset_revision'];
        return $move;
    }

    /**
     * @param mixed $rawMoves
     * @return list<array<string,mixed>>
     */
    private function validatedMoves(mixed $rawMoves, string $runId, bool $completed): array
    {
        if (!is_array($rawMoves) || !array_is_list($rawMoves)) {
            throw new \InvalidArgumentException('hanfu_cleanup_media_manifest_invalid');
        }
        $moves = [];
        $identities = [];
        foreach ($rawMoves as $move) {
            if (!is_array($move)) {
                throw new \InvalidArgumentException('hanfu_cleanup_media_manifest_invalid');
            }
            $diskCode = $this->normalizeDiskCode((string)($move['disk_code'] ?? ''));
            $from = $this->normalizeObjectKey((string)($move['from'] ?? ''));
            $to = $this->normalizeObjectKey((string)($move['to'] ?? ''));
            $sha256 = strtolower(trim((string)($move['sha256'] ?? '')));
            $assetRevision = (int)($move['asset_revision'] ?? 0);
            if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || $assetRevision < 1) {
                throw new \InvalidArgumentException('hanfu_cleanup_media_manifest_invalid');
            }
            $expectedTo = 'hanfu-1688/' . $runId . '/quarantine/'
                . $sha256 . '/' . basename($from);
            if (!hash_equals($expectedTo, $to)) {
                throw new \InvalidArgumentException('hanfu_cleanup_media_manifest_invalid');
            }
            if ($completed && (int)($move['quarantine_revision'] ?? 0) < 1) {
                throw new \RuntimeException('hanfu_cleanup_media_revision_missing');
            }
            $identity = $diskCode . '|' . $from . '|' . $to;
            if (isset($identities[$identity])) {
                throw new \InvalidArgumentException('hanfu_cleanup_media_manifest_invalid');
            }
            $identities[$identity] = true;
            $move['disk_code'] = $diskCode;
            $move['from'] = $from;
            $move['to'] = $to;
            $move['sha256'] = $sha256;
            $move['asset_revision'] = $assetRevision;
            $moves[] = $move;
        }
        return $moves;
    }

    /** @param list<string> $allowedStatuses */
    private function assertManifest(array $manifest, array $allowedStatuses): string
    {
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !in_array((string)($manifest['status'] ?? ''), $allowedStatuses, true)
        ) {
            throw new \InvalidArgumentException('hanfu_cleanup_media_manifest_invalid');
        }
        return $this->normalizeRunId((string)($manifest['run_id'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function assertAsset(
        string $diskCode,
        string $objectKey,
        ?string $expectedSha256 = null,
        ?int $expectedRevision = null,
    ): array {
        $asset = $this->files->describe(
            $diskCode,
            $objectKey,
            $this->access->localeCode,
            $this->access,
        );
        if (!$this->assetExists($asset)) {
            throw new \RuntimeException('hanfu_cleanup_media_missing');
        }
        $this->assertDescriptor(
            $asset,
            $diskCode,
            $objectKey,
            $expectedSha256,
            $expectedRevision,
        );
        return $asset;
    }

    /** @param array<string,mixed> $asset */
    private function assertDescriptor(
        array $asset,
        string $diskCode,
        string $objectKey,
        ?string $expectedSha256,
        ?int $expectedRevision,
    ): void {
        if (!hash_equals($diskCode, (string)($asset['disk_code'] ?? ''))
            || !hash_equals($objectKey, (string)($asset['object_key'] ?? ''))
        ) {
            throw new \RuntimeException('hanfu_cleanup_media_identity_drift');
        }
        $sha256 = strtolower(trim((string)($asset['sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new \RuntimeException('hanfu_cleanup_media_digest_invalid');
        }
        if ($expectedSha256 !== null && !hash_equals($expectedSha256, $sha256)) {
            throw new \RuntimeException('hanfu_cleanup_media_digest_drift');
        }
        $revision = (int)($asset['asset_revision'] ?? 0);
        if ($revision < 1) {
            throw new \RuntimeException('hanfu_cleanup_media_revision_missing');
        }
        if ($expectedRevision !== null && $revision !== $expectedRevision) {
            throw new \RuntimeException('hanfu_cleanup_media_revision_drift');
        }
    }

    private function assertMissing(string $diskCode, string $objectKey): void
    {
        $asset = $this->files->describe(
            $diskCode,
            $objectKey,
            $this->access->localeCode,
            $this->access,
        );
        if ($this->assetExists($asset)) {
            throw new \RuntimeException('hanfu_cleanup_media_destination_exists');
        }
        if (!hash_equals($diskCode, (string)($asset['disk_code'] ?? $diskCode))
            || !hash_equals($objectKey, (string)($asset['object_key'] ?? $objectKey))
        ) {
            throw new \RuntimeException('hanfu_cleanup_media_identity_drift');
        }
    }

    /** @param array<string,mixed> $asset */
    private function assetExists(array $asset): bool
    {
        return trim((string)($asset['asset_id'] ?? '')) !== '';
    }

    /** @param array<string,mixed> $references */
    private function hasExternalReferences(array $references): bool
    {
        foreach ($references as $name => $count) {
            if ($name === 'media_count') {
                continue;
            }
            if ((!is_int($count) && !(is_string($count) && ctype_digit($count)))
                || (int)$count > 0
            ) {
                return true;
            }
        }
        return false;
    }

    private function isProtectedPrefix(string $objectKey): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($objectKey, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeRunId(string $runId): string
    {
        $runId = trim($runId);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $runId) !== 1) {
            throw new \InvalidArgumentException('hanfu_cleanup_media_run_id_invalid');
        }
        return $runId;
    }

    private function normalizeDiskCode(string $diskCode): string
    {
        try {
            $diskCode = (string)StorageDiskCode::parse($diskCode);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('hanfu_cleanup_media_disk_invalid');
        }
        if (!isset($this->allowedDiskCodes[$diskCode])) {
            throw new \InvalidArgumentException('hanfu_cleanup_media_disk_invalid');
        }
        return $diskCode;
    }

    private function normalizeObjectKey(string $objectKey): string
    {
        $objectKey = trim($objectKey);
        $decoded = $objectKey;
        for ($round = 0; $round < 3; $round++) {
            $next = rawurldecode($decoded);
            if (hash_equals($decoded, $next)) {
                break;
            }
            $decoded = $next;
        }
        if ($objectKey === ''
            || strlen($objectKey) > 1024
            || $objectKey[0] === '/'
            || str_contains($objectKey, '\\')
            || str_contains($objectKey, '://')
            || preg_match('/^[A-Za-z]:/', $objectKey) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $objectKey) === 1
            || $decoded === ''
            || $decoded[0] === '/'
            || str_contains($decoded, '\\')
            || str_contains($decoded, '://')
            || preg_match('/^[A-Za-z]:/', $decoded) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1
        ) {
            throw new \InvalidArgumentException('hanfu_cleanup_media_path_invalid');
        }
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('hanfu_cleanup_media_path_invalid');
            }
        }
        return $objectKey;
    }
}
