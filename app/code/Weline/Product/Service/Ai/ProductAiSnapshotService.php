<?php

declare(strict_types=1);

namespace Weline\Product\Service\Ai;

use Weline\Product\Api\ProductAdminReadInterface;
use Weline\Product\Model\Ai\AiDraft;
use Weline\Product\Model\Shard\Product;

/**
 * Read-only Product facts for AI prompt building, dedupe and conflict checks.
 *
 * Field values are the exact-locale EAV values the admin form writes at
 * `store_id=0`; media is the store-0 desired set that `syncProductScope()` owns.
 */
final class ProductAiSnapshotService
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private const LOCALE_FALLBACKS = ['zh_Hans_CN', 'zh_CN', 'zh_Hans', 'zh', 'en_US', 'en'];

    public function __construct(private readonly ProductAdminReadInterface $reader)
    {
    }

    /**
     * @return array{
     *   website_id:int,
     *   product_uuid:string,
     *   product_id:int,
     *   locale:string,
     *   status:string,
     *   product_type:string,
     *   sku:string,
     *   category_names:list<string>,
     *   local_version:int,
     *   identity_version:int,
     *   fields:array<string,string>,
     *   field_hashes:array<string,string>,
     *   media:list<array<string,mixed>>,
     *   input_hash:string
     * }
     */
    public function snapshot(int $websiteId, string $globalProductUuid, string $locale, int $storeId = 0): array
    {
        $uuid = \strtolower(\trim($globalProductUuid));
        $locale = (string)($this->normalizeLocale($locale));
        if (\preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            throw new ProductAiException(
                'product_ai_product_not_found',
                (string)__('商品不存在或不属于当前站点'),
                404,
                ['global_product_uuid' => $globalProductUuid],
            );
        }

        try {
            $snapshot = $this->reader->snapshot(
                $websiteId,
                $uuid,
                $storeId > 0 ? $storeId : null,
                $locale,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new ProductAiException(
                'product_ai_product_not_found',
                (string)__('商品不存在或不属于当前站点'),
                404,
                ['global_product_uuid' => $uuid, 'reason' => $exception->getMessage()],
                $exception,
            );
        }

        $product = $snapshot->product;
        $productId = (int)($product[Product::schema_fields_ID] ?? 0);
        $status = (string)($product[Product::schema_fields_STATUS] ?? '');
        if ($productId < 1) {
            throw new ProductAiException(
                'product_ai_snapshot_invalid',
                (string)__('商品快照缺少站点内商品 ID'),
                500,
                ['global_product_uuid' => $uuid],
            );
        }
        if ($status === Product::STATUS_ARCHIVED) {
            throw new ProductAiException(
                'product_ai_product_archived',
                (string)__('已归档商品不能进行 AI 优化'),
                409,
                ['global_product_uuid' => $uuid],
            );
        }

        $localVersion = $product[Product::schema_fields_PUBLISH_VERSION] ?? null;
        if ($localVersion === null || (int)$localVersion < 0) {
            throw new ProductAiException(
                'product_ai_snapshot_invalid',
                (string)__('商品快照缺少发布版本'),
                500,
                ['global_product_uuid' => $uuid],
            );
        }

        $attributes = \is_array($snapshot->attributes) ? $snapshot->attributes : [];
        $fields = [];
        $fieldHashes = [];
        foreach (AiDraft::COPY_FIELDS as $code) {
            $value = $this->localizedValue($attributes, $productId, $code, $locale);
            $fields[$code] = $value;
            $fieldHashes[$code] = $this->hashField($code, $value);
        }

        $media = $this->normalizeMedia(\is_array($snapshot->mediaAssignments) ? $snapshot->mediaAssignments : []);

        return [
            'website_id' => $websiteId,
            'product_uuid' => $uuid,
            'product_id' => $productId,
            'locale' => $locale,
            'status' => $status,
            'product_type' => (string)($snapshot->identity['product_type'] ?? 'simple'),
            'sku' => $this->primarySku(\is_array($snapshot->offers) ? $snapshot->offers : []),
            'category_names' => $this->assignedCategoryNames(
                \is_array($snapshot->categoryAssignments) ? $snapshot->categoryAssignments : [],
                \is_array($snapshot->categories) ? $snapshot->categories : [],
            ),
            'local_version' => (int)$localVersion,
            'identity_version' => (int)($snapshot->identity['version'] ?? 0),
            'fields' => $fields,
            'field_hashes' => $fieldHashes,
            'media' => $media,
            'input_hash' => $this->inputHash($websiteId, $uuid, $locale, $fields, $media),
        ];
    }

    public function hashField(string $field, string $value): string
    {
        return \hash('sha256', $field . "\0" . $value);
    }

    /** @param list<array<string,mixed>> $media @return list<array<string,mixed>> */
    public function currentMainAssetId(array $media): string
    {
        foreach ($media as $row) {
            if (($row['role'] ?? '') === 'main' && ($row['asset_id'] ?? '') !== '') {
                return (string)$row['asset_id'];
            }
        }

        return '';
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = \trim(\str_replace('-', '_', $locale));
        if ($locale === '') {
            return '';
        }

        return \preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})*$/', $locale) === 1 ? $locale : '';
    }

    /** @param list<array<string,mixed>> $attributes */
    private function localizedValue(array $attributes, int $productId, string $code, string $locale): string
    {
        /** @var array<string,string> $values */
        $values = [];
        foreach ($attributes as $attribute) {
            if (!\is_array($attribute)
                || (string)($attribute['entity_type'] ?? '') !== 'product'
                || (int)($attribute['entity_id'] ?? 0) !== $productId
                || (int)($attribute['store_id'] ?? 0) !== 0
                || (string)($attribute['attribute_code'] ?? '') !== $code
                || !empty($attribute['cleared'])
            ) {
                continue;
            }
            $value = \trim((string)($attribute['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $rowLocale = \trim((string)($attribute['locale'] ?? ''));
            if (!\array_key_exists($rowLocale, $values)) {
                $values[$rowLocale] = $value;
            }
        }
        if ($values === []) {
            return '';
        }
        if ($locale !== '') {
            foreach ($values as $rowLocale => $value) {
                if (\strcasecmp($rowLocale, $locale) === 0) {
                    return $value;
                }
            }
            $prefix = \strtolower((string)\strtok($locale, '_'));
            foreach ($values as $rowLocale => $value) {
                $normalized = \strtolower($rowLocale);
                if ($prefix !== '' && ($normalized === $prefix || \str_starts_with($normalized, $prefix . '_'))) {
                    return $value;
                }
            }
        }
        if (isset($values[''])) {
            return $values[''];
        }
        foreach (self::LOCALE_FALLBACKS as $fallback) {
            foreach ($values as $rowLocale => $value) {
                if (\strcasecmp($rowLocale, $fallback) === 0) {
                    return $value;
                }
            }
        }

        return (string)\reset($values);
    }

    /** @param list<array<string,mixed>> $offers */
    private function primarySku(array $offers): string
    {
        foreach ($offers as $offer) {
            $sku = \trim((string)($offer['sku'] ?? ''));
            if ($sku !== '') {
                return $sku;
            }
        }

        return '';
    }

    /**
     * @param list<array<string,mixed>> $assignments
     * @param list<array<string,mixed>> $catalog
     * @return list<string>
     */
    private function assignedCategoryNames(array $assignments, array $catalog): array
    {
        $labels = [];
        foreach ($catalog as $category) {
            $categoryId = (int)($category['category_id'] ?? 0);
            $label = \trim((string)($category['name'] ?? ''));
            if ($categoryId > 0 && $label !== '') {
                $labels[$categoryId] = $label;
            }
        }
        $names = [];
        foreach ($assignments as $assignment) {
            if (!\is_array($assignment) || (int)($assignment['store_id'] ?? 0) !== 0) {
                continue;
            }
            $name = $labels[(int)($assignment['category_id'] ?? 0)] ?? '';
            if ($name !== '' && !\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function normalizeMedia(array $rows): array
    {
        $media = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || (int)($row['store_id'] ?? 0) !== 0) {
                continue;
            }
            $assetId = \strtolower(\trim((string)($row['asset_id'] ?? '')));
            $policy = $row['access_policy_json'] ?? null;
            $media[] = [
                'asset_id' => $assetId,
                'role' => \strtolower(\trim((string)($row['role'] ?? ''))),
                'combination_key' => \trim((string)($row['combination_key'] ?? '')),
                'position' => \max(0, (int)($row['position'] ?? 0)),
                'hidden' => !empty($row['hidden']),
                'mime_type' => \strtolower(\trim((string)($row['mime_type'] ?? ''))),
                'asset_visibility' => \strtolower(\trim((string)($row['asset_visibility'] ?? 'public'))),
                'access_policy_json' => \is_string($policy) && \trim($policy) !== '' ? $policy : null,
                'path' => \trim((string)($row['path'] ?? '')),
                'preview_url' => \trim((string)($row['preview_url'] ?? '')),
                'legacy' => $assetId === '',
            ];
        }
        \usort(
            $media,
            static fn(array $left, array $right): int => [$left['position'], $left['asset_id']] <=> [$right['position'], $right['asset_id']],
        );

        return $media;
    }

    /**
     * @param array<string,string> $fields
     * @param list<array<string,mixed>> $media
     */
    private function inputHash(int $websiteId, string $uuid, string $locale, array $fields, array $media): string
    {
        $mediaIdentity = [];
        foreach ($media as $row) {
            $mediaIdentity[] = [
                $row['asset_id'] !== '' ? $row['asset_id'] : $row['path'],
                $row['role'],
                $row['combination_key'],
                $row['position'],
                $row['hidden'],
            ];
        }

        return \hash('sha256', \json_encode(
            [
                'website_id' => $websiteId,
                'product_uuid' => $uuid,
                'locale' => $locale,
                'fields' => $fields,
                'media' => $mediaIdentity,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
