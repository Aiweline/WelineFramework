<?php

declare(strict_types=1);

namespace Weline\FileManager\Setup\Release;

use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Domain\Release\ServiceDataTransform;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Service\Setup\FileAssetHashBackfiller;
use Weline\Framework\Database\ConnectionFactory;

/**
 * Replaces legacy migration add_file_asset_object_identity_hash_20260822-v1.1.1.
 *
 * The unique index is planned in contract (new column), so this transform runs
 * after expand adds the column and before contract builds the unique index.
 */
final class FileAssetHashTransform extends ServiceDataTransform
{
    public function __construct(FileAssetHashBackfiller $backfiller, ConnectionFactory $connectionFactory)
    {
        $config = $connectionFactory->getConfigProvider();

        parent::__construct(
            'file_manager.file_asset_hash',
            new SchemaScope($config->getConnectionName(), $config->getDatabase(), ''),
            FileAsset::schema_table,
            '1.1.0',
            '1.1.1',
            static function () use ($backfiller): array {
                $backfiller->backfill();

                return ['backfilled' => true];
            },
        );
    }
}
