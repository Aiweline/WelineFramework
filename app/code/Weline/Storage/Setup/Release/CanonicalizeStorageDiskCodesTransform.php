<?php

declare(strict_types=1);

namespace Weline\Storage\Setup\Release;

use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Domain\Release\ServiceDataTransform;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Storage\Model\StorageConfig;
use Weline\Storage\Service\Setup\DiskCodeCanonicalizer;

/** Replaces legacy migration canonicalize_storage_disk_codes_20260822-v1.2.0. */
final class CanonicalizeStorageDiskCodesTransform extends ServiceDataTransform
{
    public function __construct(DiskCodeCanonicalizer $canonicalizer, ConnectionFactory $connectionFactory)
    {
        $config = $connectionFactory->getConfigProvider();

        parent::__construct(
            'storage.canonicalize_disk_codes',
            new SchemaScope($config->getConnectionName(), $config->getDatabase(), ''),
            StorageConfig::schema_table,
            '1.1.0',
            '1.2.0',
            static function () use ($canonicalizer): array {
                $canonicalizer->canonicalize();

                return ['canonicalized' => true];
            },
        );
    }
}
