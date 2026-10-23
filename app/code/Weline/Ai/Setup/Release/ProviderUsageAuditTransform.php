<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Release;

use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\Provider\UsageAuditLegacyBackfill;
use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Domain\Release\ServiceDataTransform;
use Weline\Framework\Database\ConnectionFactory;

/** Replaces legacy migration provider_usage_audit_identity_20260730-v1.1.1. */
final class ProviderUsageAuditTransform extends ServiceDataTransform
{
    public function __construct(UsageAuditLegacyBackfill $backfill, ConnectionFactory $connectionFactory)
    {
        $config = $connectionFactory->getConfigProvider();

        parent::__construct(
            'ai.provider_usage_audit_identity',
            new SchemaScope($config->getConnectionName(), $config->getDatabase(), ''),
            UsageRecord::schema_table,
            '1.1.0',
            '1.1.1',
            static fn (): array => $backfill->backfill(),
        );
    }
}
