<?php

declare(strict_types=1);

namespace Weline\FileManager\Setup\Release;

use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Domain\Release\ServiceDataTransform;
use Weline\FileManager\Model\FileAsset;
use Weline\FileManager\Service\Setup\LegacyLocalMediaImporter;
use Weline\Framework\Database\ConnectionFactory;

/** Replaces legacy migration import_legacy_local_media_20260822-v1.1.0. */
final class LegacyLocalMediaImportTransform extends ServiceDataTransform
{
    public function __construct(LegacyLocalMediaImporter $importer, ConnectionFactory $connectionFactory)
    {
        $config = $connectionFactory->getConfigProvider();

        parent::__construct(
            'file_manager.import_legacy_local_media',
            new SchemaScope($config->getConnectionName(), $config->getDatabase(), ''),
            FileAsset::schema_table,
            '1.0.0',
            '1.1.0',
            static function () use ($importer): array {
                $importer->import();

                return ['imported' => true];
            },
        );
    }
}
