<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Storage\Api\Data\StorageDiskCode;

/**
 * Download remote catalog images into FileAssetLibrary for ProductAdmin media_assignments.
 */
class DropshipRemoteMediaImporter
{
    private const MAX_IMAGES = 48;
    private const MAX_BYTES = 8_388_608;

    private readonly FileAccessContext $access;

    public function __construct(
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
     * @return array<string,string> url => asset_id
     */
    public function importUrls(string $providerCode, string $externalSpu, string $title, array $urls): array
    {
        $providerCode = strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', trim($providerCode)) ?? 'provider');
        $externalSpu = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($externalSpu)) ?: 'spu';
        $title = trim($title) !== '' ? trim($title) : $externalSpu;

        $normalized = [];
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '' || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
                continue;
            }
            $normalized[$url] = true;
            if (count($normalized) >= self::MAX_IMAGES) {
                break;
            }
        }
        if ($normalized === []) {
            return [];
        }

        $assetIdByUrl = [];
        $assetIdByHash = [];
        $index = 0;
        foreach (array_keys($normalized) as $url) {
            $bytes = $this->download($url);
            $image = @getimagesizefromstring($bytes);
            if (!is_array($image) || (int)($image[0] ?? 0) < 1 || (int)($image[1] ?? 0) < 1) {
                throw new \RuntimeException('dropship_media_image_invalid:' . $url);
            }
            $mime = strtolower(trim((string)($image['mime'] ?? '')));
            $extension = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => throw new \RuntimeException('dropship_media_mime_forbidden:' . $mime),
            };
            $sha = hash('sha256', $bytes);
            if (isset($assetIdByHash[$sha])) {
                $assetIdByUrl[$url] = $assetIdByHash[$sha];
                continue;
            }

            $fileName = sprintf('%02d-%s.%s', ++$index, substr($sha, 0, 12), $extension);
            $objectKey = 'catalog/dropship/' . $providerCode . '/' . $externalSpu . '/' . $fileName;
            $metadata = [
                'display_name' => $title . ' 商品图 ' . $index,
                'default_alt' => $title . ' 商品图 ' . $index,
                'description' => 'Dropship ' . $providerCode . ' / ' . $externalSpu,
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
                    throw new \RuntimeException('dropship_media_stream_failed');
                }
                try {
                    if (fwrite($stream, $bytes) !== strlen($bytes) || !rewind($stream)) {
                        throw new \RuntimeException('dropship_media_stream_failed');
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
                            'source_platform' => $providerCode,
                            'source_spu' => $externalSpu,
                            'source_url' => $url,
                        ],
                        (int)$image[0],
                        (int)$image[1],
                    );
                } finally {
                    fclose($stream);
                }
            }
            $assetId = strtolower(trim((string)($descriptor['asset_id'] ?? '')));
            if ($assetId === '') {
                throw new \RuntimeException('dropship_media_asset_missing:' . $url);
            }
            $assetIdByHash[$sha] = $assetId;
            $assetIdByUrl[$url] = $assetId;
        }

        return $assetIdByUrl;
    }

    private function download(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('dropship_media_curl_init_failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'WelineDropshipMedia/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || !is_string($body) || $body === '') {
            throw new \RuntimeException('dropship_media_download_failed:' . $url);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('dropship_media_http_status_' . $status);
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('dropship_media_too_large');
        }

        return $body;
    }
}
