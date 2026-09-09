<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Throwable;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\Shard\Media;

/**
 * Resolve shard media paths / FileAsset ids into admin-safe display URLs.
 */
final class ProductAdminMediaPresenter
{
    private const ASSET_PREFIX = 'asset://';

    public function __construct(
        private readonly FileAssetManagerInterface $assets,
    ) {
    }

    /**
     * @param array<string, mixed>|null $media
     * @return array<string, mixed>|null
     */
    public function presentMainMedia(?array $media, int $websiteId = 0): ?array
    {
        if ($media === null || $media === []) {
            return null;
        }

        $path = trim((string)($media[Media::schema_fields_PATH] ?? $media['path'] ?? ''));
        $displayUrl = $this->displayableImageUrl($path, $websiteId);
        if ($displayUrl === '') {
            $assetId = trim((string)($media[Media::schema_fields_ASSET_ID] ?? $media['asset_id'] ?? ''));
            if ($assetId !== '') {
                $displayUrl = $this->previewUrlForAsset($assetId, $websiteId);
            }
        }
        if ($displayUrl === '') {
            return null;
        }

        return $media + [
            'display_url' => $displayUrl,
            'path' => $path,
        ];
    }

    /**
     * Enrich a product admin media assignment with a preview URL.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function presentAssignment(array $row, int $websiteId = 0): array
    {
        $preview = trim((string)($row['preview_url'] ?? $row['display_url'] ?? ''));
        $role = strtolower(trim((string)($row['role'] ?? '')));
        $videoMeta = null;
        $policy = $row['access_policy_json'] ?? null;
        if (is_string($policy) && trim($policy) !== '') {
            try {
                $decoded = json_decode($policy, true, 64, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && is_array($decoded['video'] ?? null)) {
                    $videoMeta = $decoded['video'];
                }
            } catch (\JsonException) {
                $videoMeta = null;
            }
        } elseif (is_array($policy) && is_array($policy['video'] ?? null)) {
            $videoMeta = $policy['video'];
        }
        if ($preview === '' && is_array($videoMeta)) {
            $preview = trim((string)($videoMeta['poster_url'] ?? ''));
            $row['external_url'] = trim((string)($videoMeta['watch_url'] ?? $videoMeta['embed_url'] ?? ''));
            $row['video_provider'] = trim((string)($videoMeta['provider'] ?? ''));
            $row['embed_url'] = trim((string)($videoMeta['embed_url'] ?? ''));
        }
        if ($preview === '') {
            $assetId = trim((string)($row['asset_id'] ?? ''));
            if ($assetId !== '') {
                $preview = $this->previewUrlForAsset($assetId, $websiteId);
            }
        }
        if ($preview === '') {
            $path = trim((string)($row['path'] ?? ''));
            if ($path !== '' && !str_starts_with($path, 'video://')) {
                $preview = $this->displayableImageUrl($path, $websiteId);
            }
        }
        $row['preview_url'] = $preview;
        if (trim((string)($row['display_url'] ?? '')) === '') {
            $row['display_url'] = $preview;
        }
        if ($role === 'video' && trim((string)($row['external_url'] ?? '')) === '') {
            $path = trim((string)($row['path'] ?? ''));
            if (str_starts_with($path, 'video://youtube/')) {
                $id = substr($path, strlen('video://youtube/'));
                $row['external_url'] = 'https://www.youtube.com/watch?v=' . rawurlencode($id);
                $row['video_provider'] = 'youtube';
                $row['embed_url'] = 'https://www.youtube.com/embed/' . rawurlencode($id);
                if ($preview === '') {
                    $preview = 'https://i.ytimg.com/vi/' . rawurlencode($id) . '/hqdefault.jpg';
                    $row['preview_url'] = $preview;
                    $row['display_url'] = $preview;
                }
            } elseif (str_starts_with($path, 'video://vimeo/')) {
                $id = substr($path, strlen('video://vimeo/'));
                $row['external_url'] = 'https://vimeo.com/' . rawurlencode($id);
                $row['video_provider'] = 'vimeo';
                $row['embed_url'] = 'https://player.vimeo.com/video/' . rawurlencode($id);
            } elseif (preg_match('#^https?://#i', $path) === 1) {
                $row['external_url'] = $path;
                $row['video_provider'] = 'file';
                $row['embed_url'] = $path;
            }
        }

        return $row;
    }

    public function previewUrlForAsset(string $assetId, int $websiteId = 0): string
    {
        $assetId = trim($assetId);
        if ($assetId === '') {
            return '';
        }

        return $this->resolveAssetReference(self::ASSET_PREFIX . $assetId, $websiteId);
    }

    public function displayableImageUrl(string $path, int $websiteId = 0): string
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
        if (str_starts_with($lower, self::ASSET_PREFIX)) {
            return $this->resolveAssetReference($path, $websiteId);
        }
        if (str_contains($path, '..') || str_contains($path, '\\') || str_contains($path, '://')) {
            return '';
        }
        $url = \Weline\FileManager\Api\Image::pathToMediaUrl($path, 192, 192);

        return is_string($url) && $url !== '' ? $url : '';
    }

    /**
     * Rewrite durable asset:// img sources to admin preview HTTP(S) URLs for WYSIWYG.
     * Keeps data-weline-asset-id so save can persist back to asset://.
     *
     * @return array{html: string, preview_to_asset: array<string, string>}
     */
    public function presentDescriptionForEditor(string $html, int $websiteId = 0): array
    {
        $html = trim($html);
        $previewToAsset = [];
        if ($html === '' || !str_contains($html, self::ASSET_PREFIX)) {
            return ['html' => $html, 'preview_to_asset' => $previewToAsset];
        }

        $presented = $this->transformDescriptionImages($html, function (\DOMElement $img) use ($websiteId, &$previewToAsset): void {
            $src = trim($img->getAttribute('src'));
            if (!str_starts_with(strtolower($src), self::ASSET_PREFIX)) {
                return;
            }
            $assetId = trim(substr($src, strlen(self::ASSET_PREFIX)));
            if (!$this->isAssetUuid($assetId)) {
                return;
            }
            $preview = $this->previewUrlForAsset($assetId, $websiteId);
            if ($preview === '') {
                return;
            }
            $img->setAttribute('src', $preview);
            $img->setAttribute('data-weline-asset-id', $assetId);
            $previewToAsset[$preview] = $assetId;
        });

        return ['html' => $presented, 'preview_to_asset' => $previewToAsset];
    }

    /**
     * Rewrite durable asset:// img sources to admin preview HTTP(S) URLs for WYSIWYG.
     * Keeps data-weline-asset-id so save can persist back to asset://.
     */
    public function presentDescriptionHtml(string $html, int $websiteId = 0): string
    {
        return $this->presentDescriptionForEditor($html, $websiteId)['html'];
    }

    /**
     * Rewrite WYSIWYG preview img sources back to durable asset:// references.
     */
    public function persistDescriptionHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (!str_contains($html, 'data-weline-asset-id')
            && !str_contains(strtolower($html), self::ASSET_PREFIX)
        ) {
            return $html;
        }

        return $this->transformDescriptionImages($html, function (\DOMElement $img): void {
            $assetId = trim($img->getAttribute('data-weline-asset-id'));
            if ($assetId === '') {
                $src = trim($img->getAttribute('src'));
                if (str_starts_with(strtolower($src), self::ASSET_PREFIX)) {
                    $assetId = trim(substr($src, strlen(self::ASSET_PREFIX)));
                }
            }
            if (!$this->isAssetUuid($assetId)) {
                return;
            }
            $img->setAttribute('src', self::ASSET_PREFIX . $assetId);
            $img->removeAttribute('data-weline-asset-id');
        });
    }

    /**
     * @param callable(\DOMElement): void $mutator
     */
    private function transformDescriptionImages(string $html, callable $mutator): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'
                . '<div id="weline-product-admin-description-root">' . $html . '</div>'
                . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($document);
        $roots = $xpath->query('//*[@id="weline-product-admin-description-root"]');
        $root = $roots !== false ? $roots->item(0) : null;
        if (!$root instanceof \DOMElement) {
            return $html;
        }
        $images = $xpath->query('.//img', $root);
        if ($images !== false) {
            foreach ($images as $img) {
                if ($img instanceof \DOMElement) {
                    $mutator($img);
                }
            }
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= (string)$document->saveHTML($child);
        }

        return trim($output);
    }

    private function isAssetUuid(string $assetId): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $assetId,
        ) === 1;
    }

    private function resolveAssetReference(string $reference, int $websiteId): string
    {
        $assetId = trim(substr($reference, strlen(self::ASSET_PREFIX)));
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $assetId,
        ) !== 1) {
            return '';
        }
        $scope = ScopeIdentity::website(max(0, $websiteId), 'default');
        foreach (['zh_Hans_CN', 'en_US'] as $locale) {
            try {
                $this->assets->locale($assetId, $locale);

                return trim($this->assets->resolveUrl(
                    $assetId,
                    new FileAccessContext(
                        scope: $scope,
                        localeCode: $locale,
                        purpose: 'preview',
                    ),
                )->url);
            } catch (Throwable) {
                continue;
            }
        }

        return '';
    }
}
