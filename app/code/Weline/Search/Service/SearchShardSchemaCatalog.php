<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Search\Model\SearchShardKey;

/**
 * Declarative TableSchema catalog for one Search website shard.
 * DDL is provider-owned; Search never writes Product catalog tables.
 */
final class SearchShardSchemaCatalog
{
    public const SCHEMA_VERSION = '2.0.1';

    /** @var list<string> */
    public const ENTITIES = [
        'document',
        'watermark',
        'applied_event',
    ];

    /**
     * @return list<TableSchema>
     */
    public function schemasForShard(string $shardKey): array
    {
        SearchShardKey::parse($shardKey);
        $schemas = [];
        foreach (self::ENTITIES as $entity) {
            $schemas[] = $this->schemaForEntity($shardKey, $entity);
        }

        return $schemas;
    }

    private function schemaForEntity(string $shardKey, string $entity): TableSchema
    {
        $table = SearchShardKey::tableName($shardKey, $entity);

        return match ($entity) {
            'document' => new TableSchema(
                tableName: $table,
                comment: 'Search website shard index document projection',
                columns: [
                    new ColumnDefinition('document_id', 'bigint', 20, false, true, true, null, 'Document ID'),
                    new ColumnDefinition('entity_type', 'varchar', 32, false, false, false, null, 'Entity type'),
                    new ColumnDefinition('entity_id', 'varchar', 64, false, false, false, null, 'Entity ID'),
                    new ColumnDefinition('website_id', 'int', 11, false, false, false, 0, 'Website ID'),
                    new ColumnDefinition('website_code', 'varchar', 64, false, false, false, '', 'Website code'),
                    new ColumnDefinition('store_id', 'int', 11, false, false, false, 0, 'Store ID'),
                    new ColumnDefinition('store_code', 'varchar', 64, false, false, false, '', 'Store code'),
                    new ColumnDefinition('channel_id', 'int', 11, false, false, false, 0, 'Channel ID'),
                    new ColumnDefinition('channel_code', 'varchar', 64, false, false, false, '', 'Channel code'),
                    new ColumnDefinition('locale', 'varchar', 32, false, false, false, '', 'Locale'),
                    new ColumnDefinition('currency', 'varchar', 8, false, false, false, '', 'Currency'),
                    new ColumnDefinition('generation', 'bigint', 20, false, false, false, 0, 'Index generation'),
                    new ColumnDefinition('document_version', 'bigint', 20, false, false, false, 0, 'Document version CAS'),
                    new ColumnDefinition('payload_hash', 'varchar', 64, false, false, false, '', 'Payload hash'),
                    new ColumnDefinition('title', 'varchar', 512, false, false, false, '', 'Title'),
                    new ColumnDefinition('sku', 'varchar', 128, false, false, false, '', 'SKU'),
                    new ColumnDefinition('status', 'varchar', 32, false, false, false, 'published', 'Status'),
                    new ColumnDefinition('updated_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Updated'),
                ],
                indexes: [
                    new IndexDefinition('uk_search_doc_scope_v2', ['generation', 'entity_type', 'entity_id', 'store_id', 'channel_id', 'locale', 'currency'], 'UNIQUE'),
                    new IndexDefinition('idx_search_doc_generation_version', ['generation', 'document_version']),
                    new IndexDefinition('idx_search_doc_scope', ['generation', 'store_id', 'channel_id']),
                    new IndexDefinition('idx_search_doc_sku', ['sku']),
                    new IndexDefinition('idx_search_doc_status', ['status']),
                ],
            ),
            'watermark' => new TableSchema(
                tableName: $table,
                comment: 'Search website shard full/incremental watermark',
                columns: [
                    new ColumnDefinition('watermark_id', 'bigint', 20, false, true, true, null, 'Watermark ID'),
                    new ColumnDefinition('website_id', 'int', 11, false, false, false, 0, 'Website ID'),
                    new ColumnDefinition('active_generation', 'bigint', 20, false, false, false, 0, 'Active generation'),
                    new ColumnDefinition('build_generation', 'bigint', 20, false, false, false, 0, 'Staging generation'),
                    new ColumnDefinition('build_source_watermark', 'bigint', 20, false, false, false, 0, 'Staging source watermark'),
                    new ColumnDefinition('full_watermark', 'bigint', 20, false, false, false, 0, 'Full rebuild watermark'),
                    new ColumnDefinition('incremental_watermark', 'bigint', 20, false, false, false, 0, 'Incremental watermark'),
                    new ColumnDefinition('build_token', 'char', 64, false, false, false, '', 'Build fencing token'),
                    new ColumnDefinition('build_status', 'varchar', 32, false, false, false, 'idle', 'Build status'),
                    new ColumnDefinition('shard_fingerprint', 'varchar', 64, false, false, false, '', 'Shard fingerprint'),
                    new ColumnDefinition('row_version', 'bigint', 20, false, false, false, 0, 'Watermark row CAS'),
                    new ColumnDefinition('updated_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Updated'),
                ],
                indexes: [
                    new IndexDefinition('uk_search_wm_website', ['website_id'], 'UNIQUE'),
                ],
            ),
            'applied_event' => new TableSchema(
                tableName: $table,
                comment: 'Search incremental event idempotency and sequence evidence',
                columns: [
                    new ColumnDefinition('applied_event_id', 'bigint', 20, false, true, true, null, 'Applied event ID'),
                    new ColumnDefinition('generation', 'bigint', 20, false, false, false, 0, 'Index generation'),
                    new ColumnDefinition('event_seq', 'bigint', 20, false, false, false, 0, 'Product source sequence'),
                    new ColumnDefinition('idempotency_key', 'varchar', 191, false, false, false, '', 'Event idempotency key'),
                    new ColumnDefinition('payload_hash', 'char', 64, false, false, false, '', 'Canonical event payload hash'),
                    new ColumnDefinition('applied_at', 'datetime', null, false, false, false, 'CURRENT_TIMESTAMP', 'Applied at'),
                ],
                indexes: [
                    new IndexDefinition('uk_search_event_idem', ['generation', 'idempotency_key'], 'UNIQUE'),
                    new IndexDefinition('uk_search_event_seq', ['generation', 'event_seq'], 'UNIQUE'),
                ],
            ),
            default => throw new \InvalidArgumentException(__('未知 search shard 实体：%{1}', [$entity])),
        };
    }
}
