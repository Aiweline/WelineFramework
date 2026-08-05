<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Tax\Api\TaxEngineInterface;
use Weline\Tax\Model\TaxRuleSetLkg;

/**
 * Durable verified Tax rule-set LKG repository.
 *
 * Production persists canonical rule snapshots in Tax-owned storage. The
 * legacy save/markVerified methods are intentionally limited to explicit
 * memory harnesses so a quote result cannot become a production LKG.
 */
final class TaxLkgStore
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $entries = null;

    /** @var (\Closure():TaxRuleSetLkg)|null */
    private readonly ?\Closure $modelFactory;

    /**
     * @param (callable():TaxRuleSetLkg)|null $modelFactory
     */
    public function __construct(
        ?callable $modelFactory = null,
        bool $useMemory = false,
    ) {
        $this->modelFactory = $modelFactory === null ? null : \Closure::fromCallable($modelFactory);
        if ($useMemory) {
            $this->entries = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /**
     * @param array<string,mixed> $snapshot Canonical, replayable rule-set snapshot.
     */
    public function saveVerified(
        array $snapshot,
        string $requestSetHash,
        string $reportHash,
        int $sampleCount,
    ): string {
        $schemaVersion = trim((string) ($snapshot['schema_version'] ?? ''));
        $ruleSetHash = trim((string) ($snapshot['rule_set_hash'] ?? ''));
        $scopeKey = trim((string) ($snapshot['scope_key'] ?? ''));
        $websiteId = $snapshot['website_id'] ?? null;
        $storeId = $snapshot['store_id'] ?? null;
        if ($schemaVersion !== TaxEngine::SCHEMA_VERSION
            || preg_match('/^[a-f0-9]{64}$/D', $ruleSetHash) !== 1
            || $scopeKey === ''
            || !is_int($websiteId) || $websiteId < 0
            || !is_int($storeId) || $storeId < 0
            || preg_match('/^[a-f0-9]{64}$/D', $requestSetHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $reportHash) !== 1
            || $sampleCount < TaxShadowComparator::MIN_OBSERVATION_QUOTES
        ) {
            throw new TaxConflictException(
                TaxEngineInterface::ERROR_LKG_VERSION,
                __('已验证 LKG 元数据无效或观察窗不足'),
            );
        }
        // Rebuild verifies the snapshot's own canonical hash before storage.
        TaxEngine::fromSnapshot($snapshot);
        $snapshotJson = json_encode(
            $this->canonicalize($snapshot),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $id = $this->id($scopeKey, $schemaVersion, $ruleSetHash);
        $row = [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'scope_key' => $scopeKey,
            'rule_schema_version' => $schemaVersion,
            'rule_set_hash' => $ruleSetHash,
            'verified' => true,
            'payload' => $snapshot,
            'snapshot_json' => $snapshotJson,
            'request_set_hash' => $requestSetHash,
            'report_hash' => $reportHash,
            'sample_count' => $sampleCount,
            'verified_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($this->entries !== null) {
            $this->entries[$id] = $row;
            return $id;
        }

        $model = $this->findModel($scopeKey, $schemaVersion, $ruleSetHash);
        $model->setData([
            TaxRuleSetLkg::schema_fields_WEBSITE_ID => $websiteId,
            TaxRuleSetLkg::schema_fields_STORE_ID => $storeId,
            TaxRuleSetLkg::schema_fields_SCOPE_KEY => $scopeKey,
            TaxRuleSetLkg::schema_fields_SCHEMA_VERSION => $schemaVersion,
            TaxRuleSetLkg::schema_fields_RULE_SET_HASH => $ruleSetHash,
            TaxRuleSetLkg::schema_fields_SNAPSHOT_JSON => $snapshotJson,
            TaxRuleSetLkg::schema_fields_REQUEST_SET_HASH => $requestSetHash,
            TaxRuleSetLkg::schema_fields_REPORT_HASH => $reportHash,
            TaxRuleSetLkg::schema_fields_SAMPLE_COUNT => $sampleCount,
            TaxRuleSetLkg::schema_fields_VERIFIED => 1,
            TaxRuleSetLkg::schema_fields_VERIFIED_AT => $row['verified_at'],
            TaxRuleSetLkg::schema_fields_UPDATED_AT => $row['verified_at'],
        ])->save();

        if ($this->readVerified($schemaVersion, $ruleSetHash, $scopeKey) === null) {
            throw new \RuntimeException('tax_rule_set_lkg_persist_failed');
        }

        return $id;
    }

    /**
     * Compatibility helper for old unit callers. Production callers must use
     * saveVerified() with a canonical rule-set snapshot.
     *
     * @param array<string,mixed> $payload
     */
    public function save(string $schemaVersion, string $ruleSetHash, array $payload, bool $verified = false): string
    {
        if ($this->entries === null) {
            throw new \LogicException('save() is test-only; production must use saveVerified()');
        }
        $scopeKey = trim((string) ($payload['scope_key'] ?? 'legacy-test-scope'));
        $id = $this->id($scopeKey, $schemaVersion, $ruleSetHash);
        $this->entries[$id] = [
            'website_id' => (int) ($payload['website_id'] ?? 0),
            'store_id' => (int) ($payload['store_id'] ?? 0),
            'scope_key' => $scopeKey,
            'rule_schema_version' => $schemaVersion,
            'rule_set_hash' => $ruleSetHash,
            'verified' => $verified,
            'payload' => $payload,
            'saved_at' => time(),
        ];

        return $id;
    }

    public function markVerified(string $schemaVersion, string $ruleSetHash, ?string $scopeKey = null): void
    {
        if ($this->entries === null) {
            throw new \LogicException('markVerified() is test-only; production uses atomic saveVerified()');
        }
        $ids = $this->matchingMemoryIds($schemaVersion, $ruleSetHash, $scopeKey);
        if (count($ids) !== 1) {
            throw new TaxConflictException(
                TaxEngineInterface::ERROR_LKG_VERSION,
                __('LKG 条目不存在或 Scope 不唯一'),
                ['schema' => $schemaVersion, 'hash' => $ruleSetHash, 'scope_key' => $scopeKey],
            );
        }
        $this->entries[$ids[0]]['verified'] = true;
    }

    /**
     * Exact Scope is required for production use. Omitting it is retained only
     * for compatibility and succeeds only when there is exactly one match.
     *
     * @return array<string,mixed>|null
     */
    public function readVerified(
        string $schemaVersion,
        string $ruleSetHash,
        ?string $scopeKey = null,
    ): ?array {
        if ($this->entries !== null) {
            $ids = $this->matchingMemoryIds($schemaVersion, $ruleSetHash, $scopeKey);
            if (count($ids) !== 1) {
                return null;
            }
            $row = $this->entries[$ids[0]];
            if (($row['verified'] ?? false) !== true) {
                return null;
            }
            return is_array($row['payload'] ?? null) ? $row['payload'] : null;
        }

        $rows = $this->findVerifiedRows($schemaVersion, $ruleSetHash, $scopeKey);
        if (count($rows) !== 1) {
            return null;
        }
        $row = $rows[0];
        if ((int) ($row[TaxRuleSetLkg::schema_fields_VERIFIED] ?? 0) !== 1
            || (string) ($row[TaxRuleSetLkg::schema_fields_SCHEMA_VERSION] ?? '') !== $schemaVersion
            || (string) ($row[TaxRuleSetLkg::schema_fields_RULE_SET_HASH] ?? '') !== $ruleSetHash
            || ($scopeKey !== null
                && (string) ($row[TaxRuleSetLkg::schema_fields_SCOPE_KEY] ?? '') !== $scopeKey)
        ) {
            return null;
        }
        $snapshot = json_decode(
            (string) ($row[TaxRuleSetLkg::schema_fields_SNAPSHOT_JSON] ?? ''),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($snapshot)
            || (string) ($snapshot['schema_version'] ?? '') !== $schemaVersion
            || (string) ($snapshot['rule_set_hash'] ?? '') !== $ruleSetHash
            || (string) ($snapshot['scope_key'] ?? '')
                !== (string) ($row[TaxRuleSetLkg::schema_fields_SCOPE_KEY] ?? '')
        ) {
            return null;
        }
        try {
            TaxEngine::fromSnapshot($snapshot);
        } catch (\Throwable) {
            return null;
        }

        return $snapshot;
    }

    /**
     * @return array<string,mixed>
     */
    public function requireVerified(
        string $schemaVersion,
        string $ruleSetHash,
        ?string $scopeKey = null,
    ): array {
        $payload = $this->readVerified($schemaVersion, $ruleSetHash, $scopeKey);
        if ($payload === null) {
            throw new TaxConflictException(
                TaxEngineInterface::ERROR_LKG_VERSION,
                __('无同 Scope、同版本且已验证的 LKG'),
                ['schema' => $schemaVersion, 'hash' => $ruleSetHash, 'scope_key' => $scopeKey],
            );
        }

        return $payload;
    }

    public function has(string $schemaVersion, string $ruleSetHash, ?string $scopeKey = null): bool
    {
        if ($this->entries !== null) {
            return $this->matchingMemoryIds($schemaVersion, $ruleSetHash, $scopeKey) !== [];
        }

        return $this->findVerifiedRows($schemaVersion, $ruleSetHash, $scopeKey) !== [];
    }

    /**
     * @return list<string>
     */
    private function matchingMemoryIds(string $schemaVersion, string $ruleSetHash, ?string $scopeKey): array
    {
        $ids = [];
        foreach ($this->entries ?? [] as $id => $row) {
            if ((string) ($row['rule_schema_version'] ?? '') !== $schemaVersion
                || (string) ($row['rule_set_hash'] ?? '') !== $ruleSetHash
                || ($scopeKey !== null && (string) ($row['scope_key'] ?? '') !== $scopeKey)
            ) {
                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findVerifiedRows(
        string $schemaVersion,
        string $ruleSetHash,
        ?string $scopeKey,
    ): array {
        $query = $this->newModel()
            ->clear()
            ->where(TaxRuleSetLkg::schema_fields_SCHEMA_VERSION, $schemaVersion)
            ->where(TaxRuleSetLkg::schema_fields_RULE_SET_HASH, $ruleSetHash)
            ->where(TaxRuleSetLkg::schema_fields_VERIFIED, 1);
        if ($scopeKey !== null) {
            $query->where(TaxRuleSetLkg::schema_fields_SCOPE_KEY, $scopeKey);
        }
        $rows = $query
            ->order(TaxRuleSetLkg::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function findModel(string $scopeKey, string $schemaVersion, string $ruleSetHash): TaxRuleSetLkg
    {
        $model = $this->newModel();
        $hit = $model
            ->clear()
            ->where(TaxRuleSetLkg::schema_fields_SCOPE_KEY, $scopeKey)
            ->where(TaxRuleSetLkg::schema_fields_SCHEMA_VERSION, $schemaVersion)
            ->where(TaxRuleSetLkg::schema_fields_RULE_SET_HASH, $ruleSetHash)
            ->find()
            ->fetch();

        return $hit instanceof TaxRuleSetLkg ? $hit : $model;
    }

    private function newModel(): TaxRuleSetLkg
    {
        return $this->modelFactory !== null
            ? ($this->modelFactory)()
            : ObjectManager::create(TaxRuleSetLkg::class, [], false);
    }

    private function id(string $scopeKey, string $schemaVersion, string $ruleSetHash): string
    {
        return $scopeKey . '|' . $schemaVersion . '|' . $ruleSetHash;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
