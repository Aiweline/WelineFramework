<?php

return [
    "name" => 'Weline_Framework',
    "version" => '2.3.5',
    "requires" => [
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Framework\Cache\Contract\NamespaceGenerationInterface::class
            => \Weline\Framework\Cache\Namespace\NamespaceGenerationRepository::class,
        \Weline\Framework\Database\Transaction\TransactionCoordinatorInterface::class
            => \Weline\Framework\Database\Transaction\TransactionCoordinator::class,
        \Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface::class
            => \Weline\Framework\Database\Transaction\TransactionCoordinator::class,
        \Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface::class
            => \Weline\Framework\Database\Service\DatabaseTransactionRunner::class,
        \Weline\Framework\Database\Schema\SchemaReaderInterface::class
            => \Weline\Framework\Database\Schema\DbSchemaReader::class,
        \Weline\Framework\Database\Schema\SchemaMigrationExecutorInterface::class
            => \Weline\Framework\Database\Schema\SchemaMigrationExecutor::class,
        \Weline\Framework\Database\Schema\Shard\ShardSchemaProvisionerInterface::class
            => \Weline\Framework\Database\Schema\Shard\ShardSchemaProvisioner::class,
        \Weline\Framework\Api\Event\AsyncEventDeliveryRunnerInterface::class
            => \Weline\Framework\Event\Async\AsyncEventDeliveryRunner::class,
        \Weline\Framework\Api\Event\AsyncEventDeliveryMaintenanceInterface::class
            => \Weline\Framework\Event\Async\AsyncEventDeliveryMaintenance::class,
    ],
];
