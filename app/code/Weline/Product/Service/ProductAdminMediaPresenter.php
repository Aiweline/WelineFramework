<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Product\Model\Shard\Media;

/**
 * Resolve shard media paths into admin-safe display URLs.
 */
final class ProductAdminMediaPresenter
{
    /**
     * @param array<string, mixed>|null $media
     * @return array<string, mixed>|null
     */
    public function presentMainMedia(?array $media): ?array
    {
        if ($media === null || $media === []) {
            return null;
        }

        $path = trim((string)($media[Media::schema_fields_PATH] ?? $media['path'] ?? ''));
        $displayUrl = $this->displayableImageUrl($path);
        if ($displayUrl === '') {
            return null;
        }

        return $media + [
            'display_url' => $displayUrl,
            'path' => $path,
        ];
    }

    public function displayableImageUrl(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $lower = strtolower($path);
        if (str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'data:image/')
        ) {
            return $path;
        }
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, '://')) {
            return '';
        }
        $url = \Weline\FileManager\Api\Image::pathToMediaUrl($path, 64, 64);

        return is_string($url) && $url !== '' ? $url : '';
    }
}
