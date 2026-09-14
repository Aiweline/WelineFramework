<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Product\Model\ProductShardKey;

/**
 * Builds declarative TableSchema lists for one Product website shard.
 * Entity models bind to these physical tables; DDL stays provider-owned.
 */
final class ProductShardSchemaCatalog
{
    /** Schema generation for overlay/cleared/COW, media CAS, brand/supplier images. */
    public const SCHEMA_VERSION = '4.9.0';

    /** @var list<string> */
    public const ENTITIES = ProductShardKey::ENTITY_CODES;

    /**
     * @return list<TableSchema>
     */
    public function schemasForShard(string $shardKey): array
    {
        ProductShardKey::parse($shardKey);
        $schemas = [];
        foreach (self::ENTITIES as $entity) {
            $schemas[] = $this->schemaForEntity($shardKey, $entity);
        }
        return $schemas;
    }

    private function schemaForEntity(string $shardKey, string $entity): TableSchema
    {
        $table = ProductShardKey::tableName($shardKey, $entity);

        return match ($entity) {
            'product' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard product',
                columns: [
                    new ColumnDefinition('product_id', 'bigint', 20, false, true, true, null, 'Product ID'),
                    new ColumnDefinition('sku', 'varchar', 128, false, false, false, null, 'SKU'),
                    new ColumnDefinition('global_product_uuid', 'varchar', 36, false, false, false, null, 'Global product UUID'),
                    new ColumnDefinition('product_code', 'varchar', 32, true, false, false, null, 'Stable global product code'),
                    new ColumnDefinition('owner_website_id', 'int', 11, false, false, false, 0, 'Owning Website ID'),
                    new ColumnDefinition('provider_code', 'varchar', 64, false, false, false, 'default', 'Provider code'),
                    new ColumnDefinition('product_type', 'varchar', 64, false, false, false, 'simple', 'Product type'),
                    new ColumnDefinition('identity_version', 'int', 11, false, false, false, 1, 'Global identity version'),
                    new ColumnDefinition('source_website_id', 'int', 11, true, false, false, null, 'Copied source Website ID'),
                    new ColumnDefinition('source_version', 'int', 11, false, false, false, 0, 'Last manually copied source version'),
                    new ColumnDefinition('status', 'varchar', 32, false, false, false, 'draft', 'Status'),
                    new ColumnDefinition('publish_version', 'int', 11, false, false, false, 0, 'Optimistic publish version'),
                    new ColumnDefinition('cas_token', 'varchar', 64, false, false, false, '', 'Publish CAS owner token'),
                    new ColumnDefinition('created_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Created'),
                    new ColumnDefinition('updated_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Updated'),
                ],
                indexes: [
                    new IndexDefinition('uk_sku', ['sku'], 'UNIQUE'),
                    new IndexDefinition('uk_global_product_uuid', ['global_product_uuid'], 'UNIQUE'),
                    new IndexDefinition('idx_status', ['status']),
                ],
            ),
            'offer' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard offer',
                columns: [
                    new ColumnDefinition('offer_id', 'bigint', 20, false, true, true, null, 'Offer ID'),
                    new ColumnDefinition('product_id', 'bigint', 20, false, false, false, null, 'Product ID'),
                    new ColumnDefinition('global_offer_uuid', 'varchar', 36, false, false, false, null, 'Global offer UUID'),
                    new ColumnDefinition('sku', 'varchar', 128, true, false, false, null, 'Canonical global SKU'),
                    new ColumnDefinition('identity_version', 'int', 11, false, false, false, 1, 'Global Offer identity version'),
                    new ColumnDefinition('combination_key', 'varchar', 512, false, false, false, '', 'Canonical variant combination'),
                    new ColumnDefinition('is_default', 'tinyint', 1, false, false, false, 0, 'Default Offer'),
                    new ColumnDefinition('requires_shipping', 'tinyint', 1, false, false, false, 1, 'Requires shipping'),
                    new ColumnDefinition('shipping_profile_code', 'varchar', 50, true, false, false, null, 'Opaque shipping service profile code'),
                    new ColumnDefinition('shipping_hazard_class', 'varchar', 64, true, false, false, null, 'Hazard class for shipping capability gate'),
                    new ColumnDefinition('type_config_json', 'text', null, true, false, false, null, 'Provider Offer configuration JSON'),
                    new ColumnDefinition('status', 'varchar', 32, false, false, false, 'draft', 'Status'),
                    new ColumnDefinition('publish_version', 'int', 11, false, false, false, 0, 'Optimistic publish version'),
                    new ColumnDefinition('cas_token', 'varchar', 64, false, false, false, '', 'Publish CAS owner token'),
                    new ColumnDefinition('created_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Created'),
                    new ColumnDefinition('updated_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Updated'),
                ],
                indexes: [
                    new IndexDefinition('idx_product_id', ['product_id']),
                    new IndexDefinition('uk_global_offer_uuid', ['global_offer_uuid'], 'UNIQUE'),
                    new IndexDefinition('uk_offer_sku', ['sku'], 'UNIQUE'),
                    new IndexDefinition('uk_product_combination', ['product_id', 'combination_key'], 'UNIQUE'),
                    new IndexDefinition('idx_status', ['status']),
                ],
            ),
            'category' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard category',
                columns: [
                    new ColumnDefinition('category_id', 'bigint', 20, false, true, true, null, 'Category ID'),
                    new ColumnDefinition('global_category_uuid', 'varchar', 36, true, false, false, null, 'Cross-website category identity'),
                    new ColumnDefinition('parent_id', 'bigint', 20, true, false, false, null, 'Parent ID'),
                    new ColumnDefinition('path', 'varchar', 512, false, false, false, '', 'Path'),
                    new ColumnDefinition('position', 'int', 11, false, false, false, 0, 'Sort position among siblings'),
                    new ColumnDefinition('status', 'varchar', 32, false, false, false, 'active', 'Status'),
                    new ColumnDefinition('shipping_profile_code', 'varchar', 64, true, false, false, null, 'Shipping profile code'),
                ],
                indexes: [
                    new IndexDefinition('uk_global_category_uuid', ['global_category_uuid'], 'UNIQUE'),
                    new IndexDefinition('idx_parent_id', ['parent_id']),
                    new IndexDefinition('idx_parent_position', ['parent_id', 'position']),
                    new IndexDefinition('idx_path', ['path']),
                ],
            ),
            'category_link' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard category link',
                columns: [
                    new ColumnDefinition('link_id', 'bigint', 20, false, true, true, null, 'Link ID'),
                    new ColumnDefinition('category_id', 'bigint', 20, false, false, false, null, 'Category ID'),
                    new ColumnDefinition('product_id', 'bigint', 20, false, false, false, null, 'Product ID'),
                    new ColumnDefinition('store_id', 'int', 11, false, false, false, 0, 'Store ID (0=website)'),
                    new ColumnDefinition('scope_state', 'varchar', 16, false, false, false, 'explicit', 'explicit/cleared/inherit'),
                    new ColumnDefinition('selected', 'tinyint', 1, false, false, false, 1, 'Included at scope'),
                    new ColumnDefinition('position', 'int', 11, false, false, false, 0, 'Sort position'),
                ],
                indexes: [
                    new IndexDefinition('uk_store_category_product', ['store_id', 'category_id', 'product_id'], 'UNIQUE'),
                ],
            ),
            'category_display_selection' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard store/channel category display selection',
                columns: [
                    new ColumnDefinition('selection_id', 'bigint', 20, false, true, true, null, 'Selection ID'),
                    new ColumnDefinition('store_id', 'int', 11, false, false, false, 0, 'Store ID (0 when channel scope)'),
                    new ColumnDefinition('channel_id', 'int', 11, false, false, false, 0, 'Channel ID (0 when store scope)'),
                    new ColumnDefinition('category_id', 'bigint', 20, false, false, false, null, 'Category ID'),
                    new ColumnDefinition('enabled', 'tinyint', 1, false, false, false, 1, 'Displayed at scope'),
                    new ColumnDefinition('position', 'int', 11, false, false, false, 0, 'Display sort position'),
                ],
                indexes: [
                    new IndexDefinition(
                        'uk_store_channel_category',
                        ['store_id', 'channel_id', 'category_id'],
                        'UNIQUE',
                    ),
                    new IndexDefinition('idx_scope_position', ['store_id', 'channel_id', 'position']),
                ],
            ),
            'brand' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard brand catalog',
                columns: [
                    new ColumnDefinition('brand_id', 'bigint', 20, false, true, true, null, 'Brand ID'),
                    new ColumnDefinition('global_brand_uuid', 'varchar', 36, false, false, false, null, 'Cross-website brand identity'),
                    new ColumnDefinition('code', 'varchar', 64, false, false, false, null, 'URL-safe brand code'),
                    new ColumnDefinition('name', 'varchar', 255, false, false, false, null, 'Display name'),
                    new ColumnDefinition('logo_url', 'varchar', 512, true, false, false, null, 'Logo / brand main image URL or path'),
                    new ColumnDefinition('logo_asset_id', 'varchar', 128, true, false, false, null, 'FileManager asset id for brand main image'),
                    new ColumnDefinition('description', 'text', null, true, false, false, null, 'Brand story / description'),
                    new ColumnDefinition('status', 'varchar', 32, false, false, false, 'active', 'active/disabled'),
                    new ColumnDefinition('position', 'int', 11, false, false, false, 0, 'Sort position'),
                    new ColumnDefinition('created_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Created'),
                    new ColumnDefinition('updated_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Updated'),
                ],
                indexes: [
                    new IndexDefinition('uk_global_brand_uuid', ['global_brand_uuid'], 'UNIQUE'),
                    new IndexDefinition('uk_brand_code', ['code'], 'UNIQUE'),
                    new IndexDefinition('idx_brand_status_position', ['status', 'position']),
                ],
            ),
            'supplier' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard supplier catalog',
                columns: [
                    new ColumnDefinition('supplier_id', 'bigint', 20, false, true, true, null, 'Supplier ID'),
                    new ColumnDefinition('global_supplier_uuid', 'varchar', 36, false, false, false, null, 'Cross-website supplier identity'),
                    new ColumnDefinition('code', 'varchar', 64, false, false, false, null, 'URL-safe supplier code'),
                    new ColumnDefinition('name', 'varchar', 255, false, false, false, null, 'Display name'),
                    new ColumnDefinition('store_url', 'varchar', 512, true, false, false, null, 'Supplier storefront URL'),
                    new ColumnDefinition('image_url', 'varchar', 512, true, false, false, null, 'Supplier image URL or path'),
                    new ColumnDefinition('image_asset_id', 'varchar', 128, true, false, false, null, 'FileManager asset id for supplier image'),
                    new ColumnDefinition('contact_name', 'varchar', 128, true, false, false, null, 'Primary contact name'),
                    new ColumnDefinition('contact_phone', 'varchar', 64, true, false, false, null, 'Primary contact phone'),
                    new ColumnDefinition('contact_email', 'varchar', 255, true, false, false, null, 'Primary contact email'),
                    new ColumnDefinition('default_currency', 'varchar', 8, true, false, false, null, 'Default quote currency'),
                    new ColumnDefinition('default_payment_terms', 'varchar', 128, true, false, false, null, 'Default payment terms'),
                    new ColumnDefinition('default_lead_time_days', 'int', 11, true, false, false, null, 'Default lead time days'),
                    new ColumnDefinition('default_moq', 'int', 11, true, false, false, null, 'Default minimum order quantity'),
                    new ColumnDefinition('description', 'text', null, true, false, false, null, 'Notes / description'),
                    new ColumnDefinition('status', 'varchar', 32, false, false, false, 'active', 'active/disabled'),
                    new ColumnDefinition('position', 'int', 11, false, false, false, 0, 'Sort position'),
                    new ColumnDefinition('created_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Created'),
                    new ColumnDefinition('updated_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Updated'),
                ],
                indexes: [
                    new IndexDefinition('uk_global_supplier_uuid', ['global_supplier_uuid'], 'UNIQUE'),
                    new IndexDefinition('uk_supplier_code', ['code'], 'UNIQUE'),
                    new IndexDefinition('idx_supplier_status_position', ['status', 'position']),
                ],
            ),
            'product_supplier' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard product-supplier offer link',
                columns: [
                    new ColumnDefinition('link_id', 'bigint', 20, false, true, true, null, 'Link ID'),
                    new ColumnDefinition('product_id', 'bigint', 20, false, false, false, null, 'Product ID'),
                    new ColumnDefinition('supplier_id', 'bigint', 20, false, false, false, null, 'Supplier ID'),
                    new ColumnDefinition('is_primary', 'tinyint', 1, false, false, false, 1, 'Primary supplier for product'),
                    new ColumnDefinition('supplier_product_url', 'varchar', 512, true, false, false, null, 'Supplier product page URL'),
                    new ColumnDefinition('supplier_sku', 'varchar', 128, true, false, false, null, 'Supplier SKU / item code'),
                    new ColumnDefinition('supplier_product_name', 'varchar', 255, true, false, false, null, 'Supplier product title'),
                    new ColumnDefinition('currency', 'varchar', 8, true, false, false, null, 'Quote currency'),
                    new ColumnDefinition('unit_price_minor', 'bigint', 20, true, false, false, null, 'Supply unit price minor units'),
                    new ColumnDefinition('list_price_minor', 'bigint', 20, true, false, false, null, 'Supplier list price minor units'),
                    new ColumnDefinition('moq', 'int', 11, true, false, false, null, 'Minimum order quantity'),
                    new ColumnDefinition('lead_time_days', 'int', 11, true, false, false, null, 'Lead time days'),
                    new ColumnDefinition('pack_qty', 'int', 11, true, false, false, null, 'Pack / carton quantity'),
                    new ColumnDefinition('last_quoted_at', 'datetime', null, true, false, false, null, 'Last quote timestamp'),
                    new ColumnDefinition('notes', 'text', null, true, false, false, null, 'Sourcing notes'),
                    new ColumnDefinition('status', 'varchar', 32, false, false, false, 'active', 'active/disabled'),
                    new ColumnDefinition('created_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Created'),
                    new ColumnDefinition('updated_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Updated'),
                ],
                indexes: [
                    new IndexDefinition('uk_product_supplier', ['product_id', 'supplier_id'], 'UNIQUE'),
                    new IndexDefinition('idx_product_primary', ['product_id', 'is_primary']),
                    new IndexDefinition('idx_supplier_id', ['supplier_id']),
                ],
            ),
            'supplier_brand' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard supplier↔brand N:N link',
                columns: [
                    new ColumnDefinition('link_id', 'bigint', 20, false, true, true, null, 'Link ID'),
                    new ColumnDefinition('supplier_id', 'bigint', 20, false, false, false, null, 'Supplier ID'),
                    new ColumnDefinition('brand_id', 'bigint', 20, false, false, false, null, 'Brand ID'),
                    new ColumnDefinition('position', 'int', 11, false, false, false, 0, 'Sort position'),
                    new ColumnDefinition('created_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Created'),
                ],
                indexes: [
                    new IndexDefinition('uk_supplier_brand', ['supplier_id', 'brand_id'], 'UNIQUE'),
                    new IndexDefinition('idx_brand_id', ['brand_id']),
                ],
            ),
            'attribute_value' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard attribute value (store_id=0 = website)',
                columns: [
                    new ColumnDefinition('value_id', 'bigint', 20, false, true, true, null, 'Value ID'),
                    new ColumnDefinition('store_id', 'int', 11, false, false, false, 0, 'Store ID (0=website)'),
                    new ColumnDefinition('entity_type', 'varchar', 32, false, false, false, null, 'Entity type'),
                    new ColumnDefinition('entity_id', 'bigint', 20, false, false, false, null, 'Entity ID'),
                    new ColumnDefinition('attribute_code', 'varchar', 128, false, false, false, null, 'Attribute code'),
                    new ColumnDefinition('locale', 'varchar', 16, false, false, false, '', 'Locale'),
                    new ColumnDefinition('value_text', 'text', null, true, false, false, null, 'Legacy/text value'),
                    new ColumnDefinition('value_type', 'varchar', 16, false, false, false, 'string', 'Value type'),
                    new ColumnDefinition('value_string', 'text', null, true, false, false, null, 'String value'),
                    new ColumnDefinition('value_number', 'varchar', 64, true, false, false, null, 'Canonical numeric value'),
                    new ColumnDefinition('value_boolean', 'tinyint', 1, true, false, false, null, 'Boolean value'),
                    new ColumnDefinition('value_date', 'datetime', null, true, false, false, null, 'Date/time value'),
                    new ColumnDefinition('value_json', 'text', null, true, false, false, null, 'Single/multi option JSON'),
                    new ColumnDefinition('scope_state', 'varchar', 16, false, false, false, 'explicit', 'explicit/cleared/inherit'),
                    new ColumnDefinition('cleared', 'tinyint', 1, false, false, false, 0, 'Legacy cleared flag'),
                    new ColumnDefinition('is_required', 'tinyint', 1, false, false, false, 0, 'Required attribute'),
                ],
                indexes: [
                    new IndexDefinition(
                        'uk_attr_store_locale',
                        ['store_id', 'entity_type', 'entity_id', 'attribute_code', 'locale'],
                        'UNIQUE',
                    ),
                ],
            ),
            'price' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard price (store_id=0 = website)',
                columns: [
                    new ColumnDefinition('price_id', 'bigint', 20, false, true, true, null, 'Price ID'),
                    new ColumnDefinition('store_id', 'int', 11, false, false, false, 0, 'Store ID (0=website)'),
                    new ColumnDefinition('offer_id', 'bigint', 20, false, false, false, null, 'Offer ID'),
                    new ColumnDefinition('currency', 'varchar', 8, false, false, false, null, 'Currency'),
                    new ColumnDefinition('amount_minor', 'bigint', 20, false, false, false, 0, 'Amount minor units'),
                    new ColumnDefinition('cleared', 'tinyint', 1, false, false, false, 0, 'Cleared at scope'),
                    new ColumnDefinition('scope_state', 'varchar', 16, false, false, false, 'explicit', 'explicit/cleared/inherit'),
                    new ColumnDefinition('version', 'int', 11, false, false, false, 1, 'Optimistic price version'),
                ],
                indexes: [
                    new IndexDefinition('uk_offer_store_currency', ['store_id', 'offer_id', 'currency'], 'UNIQUE'),
                ],
            ),
            'media' => new TableSchema(
                tableName: $table,
                comment: 'Product website shard media with COW blob refs',
                columns: [
                    new ColumnDefinition('media_id', 'bigint', 20, false, true, true, null, 'Media ID'),
                    new ColumnDefinition('product_id', 'bigint', 20, false, false, false, null, 'Product ID'),
                    new ColumnDefinition('store_id', 'int', 11, false, false, false, 0, 'Store ID (0=website)'),
                    new ColumnDefinition('scope_state', 'varchar', 16, false, false, false, 'explicit', 'explicit/cleared/inherit'),
                    new ColumnDefinition('hidden', 'tinyint', 1, false, false, false, 0, 'Hidden at scope'),
                    new ColumnDefinition('role', 'varchar', 32, false, false, false, 'gallery', 'main/gallery/variant/file role'),
                    new ColumnDefinition('combination_key', 'varchar', 512, false, false, false, '', 'Canonical Offer variant combination'),
                    new ColumnDefinition('asset_id', 'varchar', 128, true, false, false, null, 'FileManager/Storage asset ID'),
                    new ColumnDefinition('asset_visibility', 'varchar', 16, false, false, false, 'public', 'public/private'),
                    new ColumnDefinition('mime_type', 'varchar', 128, true, false, false, null, 'Asset MIME type'),
                    new ColumnDefinition('access_policy_json', 'text', null, true, false, false, null, 'Download access policy JSON'),
                    new ColumnDefinition('path', 'varchar', 512, false, false, false, null, 'Legacy path/URL'),
                    new ColumnDefinition('blob_key', 'varchar', 128, false, false, false, null, 'Shared blob key'),
                    new ColumnDefinition('ref_count', 'int', 11, false, false, false, 1, 'Blob reference count'),
                    new ColumnDefinition('cow_source_media_id', 'bigint', 20, true, false, false, null, 'COW source media'),
                    new ColumnDefinition('cas_token', 'varchar', 64, false, false, false, '', 'Blob owner CAS token'),
                    new ColumnDefinition('position', 'int', 11, false, false, false, 0, 'Position'),
                ],
                indexes: [
                    new IndexDefinition('idx_product_position', ['store_id', 'product_id', 'position']),
                    new IndexDefinition('idx_product_combination_position', ['store_id', 'product_id', 'combination_key', 'position']),
                    new IndexDefinition('idx_blob_key', ['blob_key']),
                ],
            ),
            'store_product' => new TableSchema(
                tableName: $table,
                comment: 'Store product selection/overlay',
                columns: [
                    new ColumnDefinition('store_product_id', 'bigint', 20, false, true, true, null, 'ID'),
                    new ColumnDefinition('store_id', 'int', 11, false, false, false, null, 'Store ID'),
                    new ColumnDefinition('product_id', 'bigint', 20, false, false, false, null, 'Product ID'),
                    new ColumnDefinition('selected', 'tinyint', 1, false, false, false, 1, 'Selected'),
                    new ColumnDefinition('inheritance_mode', 'varchar', 16, false, false, false, 'inherit', 'inherit/explicit'),
                    new ColumnDefinition('version', 'int', 11, false, false, false, 1, 'Optimistic overlay version'),
                ],
                indexes: [
                    new IndexDefinition('uk_store_product', ['store_id', 'product_id'], 'UNIQUE'),
                ],
            ),
            'store_offer' => new TableSchema(
                tableName: $table,
                comment: 'Store offer selection/overlay',
                columns: [
                    new ColumnDefinition('store_offer_id', 'bigint', 20, false, true, true, null, 'ID'),
                    new ColumnDefinition('store_id', 'int', 11, false, false, false, null, 'Store ID'),
                    new ColumnDefinition('offer_id', 'bigint', 20, false, false, false, null, 'Offer ID'),
                    new ColumnDefinition('selected', 'tinyint', 1, false, false, false, 1, 'Selected'),
                    new ColumnDefinition('inheritance_mode', 'varchar', 16, false, false, false, 'inherit', 'inherit/explicit'),
                    new ColumnDefinition('version', 'int', 11, false, false, false, 1, 'Optimistic overlay version'),
                ],
                indexes: [
                    new IndexDefinition('uk_store_offer', ['store_id', 'offer_id'], 'UNIQUE'),
                ],
            ),
            default => throw new \InvalidArgumentException(__('未知 product shard 实体：%{1}', [$entity])),
        };
    }
}
