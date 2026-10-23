<?php

declare(strict_types=1);

namespace Weline\Websites\Setup\Release;

use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Domain\Release\ServiceDataTransform;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Websites\Model\DomainRegistrar;
use Weline\Websites\Service\Setup\LocalRegistrarSeeder;

/** Replaces legacy migration ensure_weline_local_registrar_20260403-v1.0.0. */
final class EnsureLocalRegistrarTransform extends ServiceDataTransform
{
    public function __construct(LocalRegistrarSeeder $seeder, ConnectionFactory $connectionFactory)
    {
        $config = $connectionFactory->getConfigProvider();

        parent::__construct(
            'websites.ensure_local_registrar',
            new SchemaScope($config->getConnectionName(), $config->getDatabase(), ''),
            DomainRegistrar::schema_table,
            '1.0.0',
            '1.0.0',
            static fn (): array => (static function () use ($seeder): array {
                $seeder->ensure();

                return ['seeded' => true];
            })(),
        );
    }
}
