<?php

declare(strict_types=1);

namespace Weline\Product\Service\Hanfu1688;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

final class MediaImporter
{
    private const MAX_IMAGES = 128;

    private readonly FileAccessContext $access;

    public function __construct(
        private readonly PublicHttpClient $http,
        private readonly FileAssetLibraryInterface $assets,
        ?FileAccessContext $access = null,
    ) {
        $this->access = $access ?? new FileAccessContext(
            ScopeIdentity::global(),
            'zh_Hans_CN',
            null,
            ['catalog_maintenance'],
            'metadata_edit',
        );
    }

    /**
     * @param list<string> $urls
     * @param list<array{combination:array<string,string>,image_url:string}> $variantMedia
     * @param list<string> $detailUrls
     * @return array{assignments:list<array<string,mixed>>,assets:list<array<string,mixed>>,asset_ids_by_url:array<string,string>,skipped_detail_media:list<array{url:string,error_code:string,message:string}>}
     */
    public function import(
        string $sourceCode,
        string $offerId,
        string $title,
        array $urls,
        array $variantMedia = [],
        array $detailUrls = [],
    ): array {
        $sourceCode = strtolower(trim($sourceCode));
        $offerId = trim($offerId);
        $title = trim($title);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $sourceCode) !== 1
            || preg_match('/^[1-9][0-9]{0,20}$/D', $offerId) !== 1
            || $title === ''
        ) {
            throw new \InvalidArgumentException('hanfu_1688_media_identity_invalid');
        }

        $normalizedUrls = [];
        $catalogUrls = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $normalizedUrls[$url] = true;
                $catalogUrls[$url] = true;
            }
            if (count($normalizedUrls) >= self::MAX_IMAGES) {
                break;
            }
        }
        $normalizedVariantMedia = [];
        foreach ($variantMedia as $row) {
            $combination = is_array($row['combination'] ?? null) ? $row['combination'] : [];
            $imageUrl = trim((string)($row['image_url'] ?? ''));
            if ($combination === [] || $imageUrl === '') {
                throw new \InvalidArgumentException('hanfu_1688_variant_media_invalid');
            }
            $normalizedCombination = [];
            foreach ($combination as $axis => $value) {
                $axis = strtolower(trim((string)$axis));
                $value = trim((string)$value);
                if ($axis === '' || $value === '') {
                    throw new \InvalidArgumentException('hanfu_1688_variant_media_invalid');
                }
                $normalizedCombination[$axis] = $value;
            }
            ksort($normalizedCombination, SORT_STRING);
            $normalizedVariantMedia[] = ['combination' => $normalizedCombination, 'image_url' => $imageUrl];
            $normalizedUrls[$imageUrl] = true;
            $catalogUrls[$imageUrl] = true;
            if (count($normalizedUrls) > self::MAX_IMAGES) {
                throw new \RuntimeException('hanfu_1688_variant_media_limit_exceeded');
            }
        }
        $normalizedDetailUrls = [];
        foreach ($detailUrls as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            $normalizedDetailUrls[$url] = true;
            $normalizedUrls[$url] = true;
            if (count($normalizedUrls) > self::MAX_IMAGES) {
                throw new \RuntimeException('hanfu_1688_detail_media_limit_exceeded');
            }
        }
        if ($catalogUrls === []) {
            throw new \RuntimeException('hanfu_1688_media_url_missing');
        }

        $assignments = [];
        $descriptors = [];
        $assetIdByHash = [];
        $assetIdByUrl = [];
        $skippedDetailMedia = [];
        $detailPosition = 0;
        foreach (array_keys($normalizedUrls) as $url) {
            $isDetailOnly = isset($normalizedDetailUrls[$url]) && !isset($catalogUrls[$url]);
            try {
                $bytes = $this->http->getMedia($url);
            } catch (\Throwable $exception) {
                if (!$isDetailOnly) {
                    throw $exception;
                }
                $skippedDetailMedia[] = [
                    'url' => $url,
                    'error_code' => 'hanfu_1688_detail_media_download_failed',
                    'message' => $exception->getMessage(),
                ];
                continue;
            }
            $image = @getimagesizefromstring($bytes);
            if (!is_array($image) || (int)($image[0] ?? 0) < 1 || (int)($image[1] ?? 0) < 1) {
                throw new \RuntimeException('hanfu_1688_media_image_invalid');
            }
            $mime = strtolower(trim((string)($image['mime'] ?? '')));
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
                default => throw new \RuntimeException('hanfu_1688_media_mime_forbidden'),
            };
            $sha = hash('sha256', $bytes);
            if (isset($assetIdByHash[$sha])) {
                $assetIdByUrl[$url] = $assetIdByHash[$sha];
                continue;
            }

            $position = $isDetailOnly ? $detailPosition++ : count($assignments);
            $fileName = sprintf(
                '%s%02d-%s.%s',
                $isDetailOnly ? 'detail-' : '',
                $position + 1,
                substr($sha, 0, 12),
                $extension,
            );
            $objectKey = 'catalog/hanfu/1688/' . $sourceCode . '/' . $offerId . '/' . $fileName;
            $mediaLabel = $isDetailOnly ? '详情图' : '商品图';
            $metadata = [
                'display_name' => $title . ' ' . $mediaLabel . ' ' . ($position + 1),
                'default_alt' => $title . ' ' . $mediaLabel . ' ' . ($position + 1),
                'description' => '1688 商品 ' . $offerId . ' 的本地' . $mediaLabel . '。',
                'default_caption' => $title,
                'translation_state' => FileAssetLibraryInterface::TRANSLATION_REVIEWED,
                'translation_origin' => FileAssetLibraryInterface::TRANSLATION_MANUAL,
            ];
            $descriptor = $this->assets->describe(
                StorageDiskCode::BUILTIN_LOCAL_MEDIA,
                $objectKey,
                'zh_Hans_CN',
                $this->access,
            );
            if (empty($descriptor['asset_id'])) {
                $stream = fopen('php://temp', 'w+b');
                if (!is_resource($stream)) {
                    throw new \RuntimeException('hanfu_1688_media_stream_failed');
                }
                try {
                    if (fwrite($stream, $bytes) !== strlen($bytes) || !rewind($stream)) {
                        throw new \RuntimeException('hanfu_1688_media_stream_failed');
                    }
                    $descriptor = $this->assets->upload(
                        StorageDiskCode::BUILTIN_LOCAL_MEDIA,
                        $objectKey,
                        $stream,
                        $fileName,
                        $mime,
                        'zh_Hans_CN',
                        $this->access,
                        $metadata,
                        FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                        [
                            'source_platform' => '1688',
                            'source_offer_id' => $offerId,
                            'source_url' => $url,
                            'source_media_role' => $isDetailOnly ? 'detail' : 'catalog',
                            'sha256' => $sha,
                        ],
                        (int)$image[0],
                        (int)$image[1],
                    );
                } finally {
                    fclose($stream);
                }
            } elseif (!hash_equals($sha, strtolower(trim((string)($descriptor['sha256'] ?? ''))))) {
                throw new \RuntimeException('hanfu_1688_media_existing_binary_mismatch');
            } elseif (empty($descriptor['asset_selectable'])) {
                $descriptor = $this->assets->saveMetadata(
                    (string)$descriptor['asset_id'],
                    StorageDiskCode::BUILTIN_LOCAL_MEDIA,
                    $objectKey,
                    'zh_Hans_CN',
                    $this->access,
                    (int)($descriptor['asset_revision'] ?? 0),
                    $metadata,
                );
            }
            $assetId = strtolower(trim((string)($descriptor['asset_id'] ?? '')));
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $assetId) !== 1
                || empty($descriptor['asset_selectable'])
            ) {
                throw new \RuntimeException('hanfu_1688_media_asset_not_selectable');
            }
            $assetIdByHash[$sha] = $assetId;
            $assetIdByUrl[$url] = $assetId;
            if (!$isDetailOnly) {
                $assignments[] = [
                    'asset_id' => $assetId,
                    'role' => $position === 0 ? 'main' : 'gallery',
                    'position' => $position,
                ];
            }
            $descriptors[] = $descriptor;
        }
        if ($assignments === []) {
            throw new \RuntimeException('hanfu_1688_media_empty');
        }

        $seenVariantAssignments = [];
        foreach ($normalizedVariantMedia as $row) {
            $assetId = $assetIdByUrl[$row['image_url']] ?? '';
            if ($assetId === '') {
                throw new \RuntimeException('hanfu_1688_variant_media_asset_missing');
            }
            $segments = [];
            foreach ($row['combination'] as $axis => $value) {
                $segments[] = rawurlencode($axis) . '=' . rawurlencode($value);
            }
            $identity = implode('|', $segments) . "\0" . $assetId;
            if (isset($seenVariantAssignments[$identity])) {
                continue;
            }
            $seenVariantAssignments[$identity] = true;
            $assignments[] = [
                'asset_id' => $assetId,
                'role' => 'variant',
                'position' => 0,
                'combination' => $row['combination'],
            ];
        }

        return [
            'assignments' => $assignments,
            'assets' => $descriptors,
            'asset_ids_by_url' => $assetIdByUrl,
            'skipped_detail_media' => $skippedDetailMedia,
        ];
    }
}
