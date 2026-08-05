<?php

declare(strict_types=1);

namespace Weline\MediaManager\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\MediaManager\Service\MediaStorageService;

/**
 * Internal, read-only media asset boundary for trusted module consumers.
 *
 * The operation is deliberately unavailable to frontend Query calls because
 * it returns binary bytes. Browser selection continues to use MediaManager's
 * normal iframe contract and only the validated hash crosses into this API.
 */
final class MediaManagerAssetQueryProvider implements QueryProviderInterface
{
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function __construct(
        private readonly MediaStorageService $mediaStorage,
    ) {
    }

    public function getProviderName(): string
    {
        return 'media_manager_asset';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'readAsset' => $this->readAsset($params),
            default => throw new \InvalidArgumentException(
                (string)__('媒体资产查询器不支持的操作：%{1}', $operation),
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('媒体资产只读接口'),
            'description' => (string)__('为可信模块读取经过路径约束的媒体图片。'),
            'module' => 'Weline_MediaManager',
            'operations' => [
                [
                    'name' => 'readAsset',
                    'description' => (string)__('按媒体哈希读取图片字节。'),
                    'frontend' => false,
                    'mode' => 'read',
                    'graph' => false,
                    'auth' => 'backend',
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_MediaManager::file_manager',
                    ],
                    'params' => [
                        ['name' => 'hash', 'type' => 'string', 'required' => true, 'max_length' => 4096],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string|int,mixed> $params @return array<string,mixed> */
    private function readAsset(array $params): array
    {
        $hash = \trim((string)($params['hash'] ?? ''));
        if (\strlen($hash) > 4096 || \preg_match('/^mm_[A-Za-z0-9_-]+$/D', $hash) !== 1) {
            throw new \RuntimeException('MEDIA_ASSET_SELECTION_INVALID');
        }

        try {
            // MediaStorageService::readFileBytes() is single-arg; enforce size here.
            $asset = $this->mediaStorage->readFileBytes($hash);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException('MEDIA_ASSET_READ_FAILED', 0, $throwable);
        }

        $bytes = \is_string($asset['bytes'] ?? null) ? $asset['bytes'] : '';
        if (\strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new \RuntimeException('MEDIA_ASSET_TOO_LARGE');
        }
        $mime = '';
        if ($bytes !== '') {
            try {
                $detected = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes);
                $mime = \is_string($detected) ? \strtolower(\trim($detected)) : '';
            } catch (\Throwable) {
                $mime = '';
            }
        }
        if ($mime === '' && $bytes !== '') {
            $imageInfo = @\getimagesizefromstring($bytes);
            $mime = \is_array($imageInfo)
                ? \strtolower(\trim((string)($imageInfo['mime'] ?? '')))
                : '';
        }
        if ($bytes === '' || !\in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw new \RuntimeException('MEDIA_ASSET_UNSUPPORTED_TYPE');
        }

        $relative = \trim(\str_replace('\\', '/', (string)($asset['relative'] ?? '')), '/');
        if ($relative === '') {
            throw new \RuntimeException('MEDIA_ASSET_RESPONSE_INVALID');
        }
        $publicPath = \implode('/', \array_map('rawurlencode', \explode('/', $relative)));

        return [
            'bytes' => $bytes,
            'mime_type' => $mime,
            'sha256' => \hash('sha256', $bytes),
            'size' => \strlen($bytes),
            'public_url' => '/pub/media/' . $publicPath,
            'hash' => $hash,
        ];
    }
}
