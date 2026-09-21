<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Model\FileAsset;
use Weline\Storage\Api\Data\StorageDiskCode;

/**
 * Read-side normalizer: path | asset UUID | asset:// | file-image envelope → ImageUsage.
 * Write contracts stay domain-split (Theme=file-image, config=path).
 */
final class FileImageReferenceNormalizer
{
    private const ASSET_URI_PREFIX = 'asset://';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public function __construct(private readonly FileAsset $assets)
    {
    }

    /**
     * @param mixed $ref usage attr / file-image JSON / path / ImageUsage / usage array
     * @return ImageUsage|null null when the reference cannot be resolved (caller should render empty)
     */
    public function normalizeToUsage(
        mixed $ref,
        string $assetIdFallback = '',
        string $alt = '',
        bool $decorative = false,
        string $locale = '',
        ?bool $complement = null,
        ?int $layoutWidth = null,
        ?int $layoutHeight = null,
    ): ?ImageUsage {
        $locale = trim($locale);
        if ($ref instanceof ImageUsage) {
            return $this->withLayout($ref, $layoutWidth, $layoutHeight);
        }

        if (is_string($ref)) {
            $trimmed = trim($ref);
            if ($trimmed === '') {
                $ref = null;
            } elseif ($trimmed[0] === '{' || $trimmed[0] === '[') {
                try {
                    $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    // Not JSON — treat as path / URI / UUID below.
                    $decoded = null;
                }
                if (is_array($decoded)) {
                    $ref = $decoded;
                } else {
                    return $this->fromScalarReference(
                        $trimmed,
                        $assetIdFallback,
                        $alt,
                        $decorative,
                        $locale,
                        $complement,
                        $layoutWidth,
                        $layoutHeight,
                    );
                }
            } else {
                return $this->fromScalarReference(
                    $trimmed,
                    $assetIdFallback,
                    $alt,
                    $decorative,
                    $locale,
                    $complement,
                    $layoutWidth,
                    $layoutHeight,
                );
            }
        }

        if (is_array($ref)) {
            $usageData = $this->unwrapUsageArray($ref);
            if ($usageData !== null) {
                if ($locale !== '' && trim((string)($usageData['locale_code'] ?? '')) === '') {
                    $usageData['locale_code'] = $locale;
                }
                if ($layoutWidth !== null && $layoutHeight !== null) {
                    $usageData['layout_width'] = $layoutWidth;
                    $usageData['layout_height'] = $layoutHeight;
                }
                try {
                    return ImageUsage::fromArray($usageData);
                } catch (\InvalidArgumentException) {
                    return null;
                }
            }

            $embedded = trim((string)($ref['asset_id'] ?? $ref['path'] ?? $ref['src'] ?? ''));
            if ($embedded !== '') {
                return $this->fromScalarReference(
                    $embedded,
                    $assetIdFallback,
                    $alt !== '' ? $alt : trim((string)($ref['alt'] ?? '')),
                    $decorative || !empty($ref['decorative']),
                    $locale !== '' ? $locale : trim((string)($ref['locale_code'] ?? '')),
                    $complement,
                    $layoutWidth,
                    $layoutHeight,
                );
            }
        }

        $fallback = trim($assetIdFallback);
        if ($fallback === '') {
            return null;
        }

        return $this->fromScalarReference(
            $fallback,
            '',
            $alt,
            $decorative,
            $locale,
            $complement,
            $layoutWidth,
            $layoutHeight,
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function unwrapUsageArray(array $data): ?array
    {
        if (($data['type'] ?? null) === 'file-image' && is_array($data['usage'] ?? null)) {
            return $data['usage'];
        }
        if (isset($data['asset_id'], $data['locale_code']) || isset($data['version'], $data['asset_id'])) {
            return $data;
        }

        return null;
    }

    private function fromScalarReference(
        string $raw,
        string $assetIdFallback,
        string $alt,
        bool $decorative,
        string $locale,
        ?bool $complement,
        ?int $layoutWidth,
        ?int $layoutHeight,
    ): ?ImageUsage {
        $assetId = $this->extractAssetId($raw);
        if ($assetId === '') {
            $assetId = $this->extractAssetId(trim($assetIdFallback));
        }
        if ($assetId === '') {
            $assetId = $this->resolveAssetIdByPath($raw);
        }
        if ($assetId === '' || $locale === '') {
            return null;
        }

        try {
            return new ImageUsage(
                $assetId,
                $locale,
                $decorative ? '' : trim($alt),
                ImageUsage::ALT_CONFIRMED,
                $decorative,
                complement: $complement ?? true,
                layoutWidth: $layoutWidth,
                layoutHeight: $layoutHeight,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function extractAssetId(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (str_starts_with(strtolower($raw), self::ASSET_URI_PREFIX)) {
            $raw = substr($raw, strlen(self::ASSET_URI_PREFIX));
        }
        $raw = trim($raw);
        if (preg_match(self::UUID_PATTERN, $raw) === 1) {
            return strtolower($raw);
        }

        return '';
    }

    public function normalizeMediaPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        $path = preg_replace('#^https?://[^/]+/#i', '', $path) ?? $path;
        $path = preg_replace('#^/+#', '', $path) ?? $path;
        foreach (['pub/media/', 'media/'] as $prefix) {
            if (str_starts_with(strtolower($path), $prefix)) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        return trim($path, '/');
    }

    public function resolveAssetIdByPath(string $path): string
    {
        $objectKey = $this->normalizeMediaPath($path);
        if ($objectKey === '' || $this->extractAssetId($objectKey) !== '') {
            return '';
        }

        $candidates = [$objectKey];
        if (!str_contains($objectKey, '/')) {
            // Bare filename unlikely; still try as-is.
        } else {
            // Some legacy rows store without websites/… prefix variants — exact object_key only.
        }

        $diskCode = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
        foreach ($candidates as $key) {
            $asset = $this->findByObjectKey($diskCode, $key);
            if ($asset !== null && !$asset->isDeleted()) {
                return $asset->getAssetId();
            }
        }

        // Fallback: object_key match without disk filter (exact key is usually unique).
        $asset = clone $this->assets;
        try {
            $asset->clearData()->reset()
                ->where(FileAsset::schema_fields_OBJECT_KEY, $objectKey)
                ->find()->fetch();
        } catch (\Throwable) {
            return '';
        }
        if ($asset->getAssetId() !== '' && !$asset->isDeleted()) {
            return $asset->getAssetId();
        }

        return '';
    }

    private function findByObjectKey(string $diskCode, string $objectKey): ?FileAsset
    {
        $key = trim($objectKey, '/');
        if ($key === '') {
            return null;
        }
        try {
            $asset = clone $this->assets;
            $asset->clearData()->reset()
                ->where(FileAsset::schema_fields_OBJECT_IDENTITY_HASH, FileAsset::objectIdentityHash($diskCode, $key))
                ->where(FileAsset::schema_fields_DISK_CODE, $diskCode)
                ->where(FileAsset::schema_fields_OBJECT_KEY, $key)
                ->find()->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $asset->getAssetId() !== '' ? $asset : null;
    }

    private function withLayout(ImageUsage $usage, ?int $layoutWidth, ?int $layoutHeight): ImageUsage
    {
        if ($layoutWidth === null || $layoutHeight === null) {
            return $usage;
        }

        return ImageUsage::fromArray(array_merge($usage->toArray(), [
            'layout_width' => $layoutWidth,
            'layout_height' => $layoutHeight,
        ]));
    }
}
