<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Database\Schema\Shard\ShardProvisionResult;
use Weline\Framework\Database\Schema\Shard\ShardSchemaProvisionerInterface;
use Weline\Search\Api\SearchShardRegistryInterface;
use Weline\Search\Model\SearchShardKey;
use Weline\Search\Model\SearchShardRegistry;

/**
 * Search website shard registry state machine + canonical Framework provisioner.
 */
final class SearchShardProvisioner
{
    public function __construct(
        private readonly SearchShardRegistryInterface $registry,
        private readonly ShardSchemaProvisionerInterface $schemaProvisioner,
    ) {
    }

    public function provisionWebsite(int $websiteId, array $context = []): ShardProvisionResult
    {
        $shardKey = SearchShardKey::fromWebsiteId($websiteId);
        $this->registry->ensureWebsite($websiteId);
        $status = $this->registry->getStatus($websiteId);
        if ($status === SearchShardRegistry::STATUS_READY
            && $this->registry->getSchemaVersion($websiteId) === SearchShardSchemaCatalog::SCHEMA_VERSION
            && \trim($this->registry->getFingerprint($websiteId)) !== ''
        ) {
            return new ShardProvisionResult(
                SearchShardKey::FAMILY_CODE,
                $shardKey,
                ShardProvisionResult::STATUS_READY,
                $this->registry->getFingerprint($websiteId),
            );
        }

        $from = [
            SearchShardRegistry::STATUS_UNPROVISIONED,
            SearchShardRegistry::STATUS_MAINTENANCE,
            SearchShardRegistry::STATUS_FAILED,
        ];
        if ($status === SearchShardRegistry::STATUS_READY) {
            $from[] = SearchShardRegistry::STATUS_READY;
        }
        if (!$this->registry->compareAndSet(
            $websiteId,
            $from,
            SearchShardRegistry::STATUS_PROVISIONING,
        )) {
            return new ShardProvisionResult(
                SearchShardKey::FAMILY_CODE,
                $shardKey,
                ShardProvisionResult::STATUS_FAILED,
                '',
                errorMessage: (string)__(
                    'Search shard 无法进入 provisioning：status=%{1}',
                    [$this->registry->getStatus($websiteId)],
                ),
            );
        }

        $result = $this->schemaProvisioner->provision(
            SearchShardKey::FAMILY_CODE,
            $shardKey,
            $context + [
                'family' => SearchShardKey::FAMILY_CODE,
                'shard_key' => $shardKey,
                'website_id' => $websiteId,
            ],
        );
        if ($result->familyCode !== SearchShardKey::FAMILY_CODE
            || $result->shardKey !== $shardKey
        ) {
            $message = (string)__('Search shard provision 返回身份不匹配');
            $this->registry->markMaintenance($websiteId, $message);

            return new ShardProvisionResult(
                SearchShardKey::FAMILY_CODE,
                $shardKey,
                ShardProvisionResult::STATUS_MAINTENANCE,
                '',
                errorMessage: $message,
            );
        }
        if ($result->isReady() && \trim($result->fingerprint) !== '') {
            $this->registry->markReady(
                $websiteId,
                $result->fingerprint,
                SearchShardSchemaCatalog::SCHEMA_VERSION,
            );

            return $result;
        }

        $message = (string)($result->errorMessage ?? __('Search shard provision 失败'));
        if ($result->status === ShardProvisionResult::STATUS_MAINTENANCE) {
            $this->registry->markMaintenance($websiteId, $message);
        } else {
            $this->registry->markFailed($websiteId, $message);
        }

        return $result;
    }

    public function assertReady(int $websiteId): void
    {
        $this->registry->assertReady($websiteId);
    }
}
