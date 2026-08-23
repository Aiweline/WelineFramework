<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Api\Data\ResolvedFileImage;
use Weline\FileManager\Api\FileAccessPolicyInterface;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Model\FileAssetLocale;
use Weline\Storage\Api\Data\ResolvedStorageUrl;
use Weline\Storage\Api\Data\StorageUrlOptions;
use Weline\Storage\Api\StorageManagerInterface;

final class FileAssetManager implements FileAssetManagerInterface
{
    public function __construct(
        private readonly FileAsset $assets,
        private readonly FileAssetLocale $locales,
        private readonly StorageManagerInterface $storage,
        private readonly FileAccessPolicyInterface $accessPolicy,
    ) {
    }

    public function get(string $assetId): FileAsset
    {
        $assetId = trim($assetId);
        if ($assetId === '') {
            throw new \InvalidArgumentException((string)__('资源 ID 不能为空。'));
        }
        $asset = clone $this->assets;
        $asset->clearData()->reset()->where(FileAsset::schema_fields_ID, $assetId)->find()->fetch();
        if ($asset->getAssetId() === '') {
            throw new \RuntimeException((string)__('文件资源不存在。'));
        }
        return $asset;
    }

    public function locale(string $assetId, string $localeCode): FileAssetLocale
    {
        $localeCode = self::normalizeLocale($localeCode);
        $locale = clone $this->locales;
        $locale->clearData()->reset()
            ->where(FileAssetLocale::schema_fields_ASSET_ID, trim($assetId))
            ->where(FileAssetLocale::schema_fields_LOCALE_CODE, $localeCode)
            ->find()->fetch();
        if ((int)$locale->getData(FileAssetLocale::schema_fields_ID) < 1) {
            throw new \RuntimeException((string)__('文件资源缺少精确语言元数据：%{1}', [$localeCode]));
        }
        return $locale;
    }

    public function resolveUrl(
        string $assetId,
        FileAccessContext $context,
        ?StorageUrlOptions $options = null,
    ): ResolvedStorageUrl {
        $asset = $this->get($assetId);
        $this->accessPolicy->assertCanRead($asset, $context);
        $privateTtl = null;
        if ($asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE) {
            SharedResponseCachePolicy::forbid('private_file_asset');
            $privateTtl = $options?->ttlSeconds ?? 300;
            $options = new StorageUrlOptions(StorageUrlOptions::KIND_TEMPORARY, $privateTtl);
        }
        $resolved = $this->storage->disk($asset->getDiskCode())->resolveUrl($asset->getObjectKey(), $options);
        if ($privateTtl !== null) {
            $this->assertPrivateResolvedUrl(
                $resolved,
                StorageUrlOptions::KIND_TEMPORARY,
                $privateTtl,
                '私有文件 URL 适配器必须返回不可共享缓存的临时 URL。',
            );
        }
        return $resolved;
    }

    public function validateImageUsage(ImageUsage $usage, FileAccessContext $context): void
    {
        $usage->assertPublishable($context->localeCode);
        $asset = $this->get($usage->assetId);
        if ($asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE
            && $context->purpose === FileAccessContext::PURPOSE_PUBLIC_PUBLISH
        ) {
            throw new \RuntimeException((string)__('公开发布的页面不能引用私有文件资源。'));
        }
        $this->accessPolicy->assertCanRead($asset, $context);
        $this->assertImageMetadata($usage, $asset, true);
        $disk = $this->storage->disk($asset->getDiskCode());
        if (!$disk->exists($asset->getObjectKey())) {
            throw new \RuntimeException((string)__('图片资源对应的存储对象不存在。'));
        }
        // Publication also verifies the owning disk's URL adapter. A successful
        // existence check alone is not enough when URL generation is misconfigured.
        $this->resolveImage($usage, $context);
    }

    public function validateImageReference(ImageUsage $usage, FileAccessContext $context): void
    {
        if ($usage->localeCode !== $context->localeCode) {
            throw new \RuntimeException((string)__('图片语境语言与当前语言不一致。'));
        }
        $asset = $this->get($usage->assetId);
        $this->accessPolicy->assertCanRead($asset, $context);
        if (!str_starts_with(strtolower($asset->getMimeType()), 'image/')) {
            throw new \RuntimeException((string)__('所选资源不是图片。'));
        }
        // Drafts may legitimately carry machine-translated metadata or alt in
        // needs_review state, but the exact locale row must already exist.
        $this->locale($usage->assetId, $usage->localeCode);
    }

    public function resolveImage(
        ImageUsage $usage,
        FileAccessContext $context,
        string $class = '',
    ): ResolvedFileImage {
        $allowsUnreviewedLocale = in_array(
            $context->purpose,
            ['preview', 'media_manager', 'metadata_edit', 'draft_index'],
            true,
        );
        if ($allowsUnreviewedLocale) {
            if ($usage->localeCode !== $context->localeCode) {
                throw new \RuntimeException((string)__('图片语境语言与当前语言不一致。'));
            }
        } else {
            $usage->assertPublishable($context->localeCode);
        }
        $asset = $this->get($usage->assetId);
        $this->accessPolicy->assertCanRead($asset, $context);
        $this->assertImageMetadata($usage, $asset, !$allowsUnreviewedLocale);
        $disk = $this->storage->disk($asset->getDiskCode());
        $baseOptions = $asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE
            ? new StorageUrlOptions(StorageUrlOptions::KIND_TEMPORARY, 300)
            : new StorageUrlOptions();
        if ($asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE) {
            SharedResponseCachePolicy::forbid('private_file_asset');
        }
        $baseUrl = $disk->resolveUrl($asset->getObjectKey(), $baseOptions);
        if ($asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE) {
            $this->assertPrivateResolvedUrl(
                $baseUrl,
                StorageUrlOptions::KIND_TEMPORARY,
                300,
                '私有图片 URL 适配器必须返回不可共享缓存的临时 URL。',
            );
        }
        $src = $baseUrl->url;
        $srcset = [];
        $variantTtl = $asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE ? 300 : 3600;
        foreach (array_values(array_unique($usage->widths)) as $width) {
            $variant = $disk->resolveUrl(
                $asset->getObjectKey(),
                new StorageUrlOptions(StorageUrlOptions::KIND_IMAGE_VARIANT, $variantTtl, $width),
            );
            if ($asset->getVisibility() === FileAsset::VISIBILITY_PRIVATE) {
                $this->assertPrivateResolvedUrl(
                    $variant,
                    StorageUrlOptions::KIND_IMAGE_VARIANT,
                    $variantTtl,
                    '私有图片变体 URL 必须不可共享缓存且带过期时间。',
                );
            }
            if ($variant->url !== $src) {
                $srcset[$variant->url] = $width;
            }
        }
        $loading = $usage->priority === 'high' ? 'eager' : $usage->loading;
        $attributes = [
            'src' => $src,
            'alt' => $usage->decorative ? '' : $usage->alt,
            'loading' => $loading,
            'decoding' => 'async',
        ];
        if ($usage->priority !== 'auto') {
            $attributes['fetchpriority'] = $usage->priority;
        }
        if ($srcset !== []) {
            $attributes['srcset'] = implode(', ', array_map(
                static fn (string $url, int $width): string => $url . ' ' . $width . 'w',
                array_keys($srcset),
                array_values($srcset),
            ));
            $attributes['sizes'] = $usage->sizes;
        }
        $width = (int)$asset->getData(FileAsset::schema_fields_WIDTH);
        $height = (int)$asset->getData(FileAsset::schema_fields_HEIGHT);
        if ($width > 0) { $attributes['width'] = (string)$width; }
        if ($height > 0) { $attributes['height'] = (string)$height; }
        if (trim($class) !== '') { $attributes['class'] = trim($class); }
        if ($usage->decorative) { $attributes['aria-hidden'] = 'true'; }

        $html = '<img' . self::htmlAttributes($attributes) . '>';
        if ($usage->caption !== null && trim($usage->caption) !== '') {
            $html = '<figure>' . $html . '<figcaption>' . self::escape($usage->caption) . '</figcaption></figure>';
        }
        return new ResolvedFileImage($src, $html);
    }

    public function renderImage(ImageUsage $usage, FileAccessContext $context, string $class = ''): string
    {
        return $this->resolveImage($usage, $context, $class)->html;
    }

    /** @param array<string,string> $attributes */
    private static function htmlAttributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . self::escape($value) . '"';
        }
        return $html;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function assertImageMetadata(
        ImageUsage $usage,
        FileAsset $asset,
        bool $requireReviewedLocale,
    ): void
    {
        if (!str_starts_with(strtolower($asset->getMimeType()), 'image/')) {
            throw new \RuntimeException((string)__('所选资源不是图片。'));
        }
        $locale = $this->locale($usage->assetId, $usage->localeCode);
        if ($requireReviewedLocale && !$locale->isReviewed()) {
            throw new \RuntimeException((string)__('图片资源语言元数据尚未审核。'));
        }
    }

    private function assertPrivateResolvedUrl(
        ResolvedStorageUrl $resolved,
        string $expectedKind,
        int $ttlSeconds,
        string $errorMessage,
    ): void {
        $now = time();
        if ($resolved->kind !== $expectedKind
            || $resolved->cacheable
            || $resolved->expiresAt === null
            || $resolved->expiresAt <= $now
            || $resolved->expiresAt > $now + $ttlSeconds + 60
        ) {
            throw new \RuntimeException((string)__($errorMessage));
        }
    }

    public static function normalizeLocale(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));
        if (preg_match('/^[a-zA-Z]{2,3}(?:_[a-zA-Z]{4})?(?:_(?:[a-zA-Z]{2}|[0-9]{3}))?$/', $locale) !== 1) {
            throw new \InvalidArgumentException((string)__('语言代码无效：%{1}', [$locale]));
        }
        $parts = explode('_', $locale);
        $parts[0] = strtolower($parts[0]);
        if (isset($parts[1])) {
            $parts[1] = strlen($parts[1]) === 4 ? ucfirst(strtolower($parts[1])) : strtoupper($parts[1]);
        }
        if (isset($parts[2])) { $parts[2] = strtoupper($parts[2]); }
        return implode('_', $parts);
    }
}
