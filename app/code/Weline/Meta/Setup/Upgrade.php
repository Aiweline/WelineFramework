<?php

declare(strict_types=1);

namespace Weline\Meta\Setup;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Model\MetaConfig;

/**
 * Data-only phase-1 migration for MetaConfig identity fingerprints.
 *
 * Declarative schema migration owns the column and index DDL. This upgrade only
 * validates the complete existing data set and transactionally backfills NULLs.
 */
final class Upgrade implements UpgradeInterface
{
    public function __construct(
        private readonly MetaConfig $configs,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public function setup(Setup $setup, Context $context): void
    {
        $this->transactions->runWrite(
            $this->configs->getConnection(),
            function (): void {
                $rows = $this->readAllRows();
                $inspection = $this->inspectRows($rows, true);
                $this->assertInspection($inspection, '预检');

                foreach ($inspection['backfill'] as $configId => $fingerprint) {
                    $this->configs->newQuery()
                        ->where(MetaConfig::schema_fields_ID, $configId)
                        ->where(MetaConfig::schema_fields_IDENTITY_FINGERPRINT, null, 'IS NULL')
                        ->update([
                            MetaConfig::schema_fields_IDENTITY_FINGERPRINT => $fingerprint,
                        ], MetaConfig::schema_fields_ID)
                        ->fetch();
                }

                $finalInspection = $this->inspectRows($this->readAllRows(), false);
                $this->assertInspection($finalInspection, '回填后校验');
            },
        );
    }

    /** @return list<mixed> */
    private function readAllRows(): array
    {
        $rows = $this->configs->newQuery()
            ->order(MetaConfig::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param list<mixed> $rows
     * @return array{
     *   backfill: array<int, string>,
     *   mismatch: list<int>,
     *   null: list<int>,
     *   invalid: list<int>,
     *   duplicate: list<int>,
     *   collision: list<int>
     * }
     */
    private function inspectRows(array $rows, bool $allowNull): array
    {
        $inspection = [
            'backfill' => [],
            'mismatch' => [],
            'null' => [],
            'invalid' => [],
            'duplicate' => [],
            'collision' => [],
        ];
        /** @var array<string, array{id: int, identity: array<int, string|int|null>}> $seen */
        $seen = [];

        foreach ($rows as $row) {
            $configId = (int)$this->rowValue($row, MetaConfig::schema_fields_ID, 0);
            $identity = $this->identityFromRow($row);
            try {
                $validated = new MetaConfigIdentity(...$identity);
                $value = $this->rowValue($row, MetaConfig::schema_fields_CONFIG_VALUE);
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Meta config value must be a string.');
                }
                MetaConfigIdentity::assertValue($value);
                $expected = $validated->fingerprint();
            } catch (\Throwable) {
                $inspection['invalid'][] = $configId;
                continue;
            }
            $stored = $this->rowValue($row, MetaConfig::schema_fields_IDENTITY_FINGERPRINT);

            if ($stored === null) {
                if ($allowNull) {
                    $inspection['backfill'][$configId] = $expected;
                } else {
                    $inspection['null'][] = $configId;
                }
            } elseif (!is_string($stored) || !hash_equals($expected, $stored)) {
                $inspection['mismatch'][] = $configId;
            }

            if (isset($seen[$expected])) {
                $kind = $seen[$expected]['identity'] === $identity ? 'duplicate' : 'collision';
                $inspection[$kind][] = $seen[$expected]['id'];
                $inspection[$kind][] = $configId;
                continue;
            }
            $seen[$expected] = ['id' => $configId, 'identity' => $identity];
        }

        foreach (['mismatch', 'null', 'invalid', 'duplicate', 'collision'] as $key) {
            $inspection[$key] = array_values(array_unique(array_map('intval', $inspection[$key])));
            sort($inspection[$key], SORT_NUMERIC);
        }

        return $inspection;
    }

    /**
     * @param array{
     *   backfill: array<int, string>,
     *   mismatch: list<int>,
     *   null: list<int>,
     *   invalid: list<int>,
     *   duplicate: list<int>,
     *   collision: list<int>
     * } $inspection
     */
    private function assertInspection(array $inspection, string $stage): void
    {
        $problems = [];
        foreach ([
            'mismatch' => 'fingerprint_mismatch',
            'null' => 'fingerprint_null',
            'invalid' => 'identity_or_value_invalid',
            'duplicate' => 'exact_duplicate',
            'collision' => 'sha256_collision',
        ] as $key => $label) {
            if ($inspection[$key] !== []) {
                $problems[] = $label . ' config_id=' . implode(',', $inspection[$key]);
            }
        }
        if ($problems !== []) {
            throw new \RuntimeException(__('MetaConfig 指纹%{1}失败：%{2}', [
                $stage,
                implode('; ', $problems),
            ]));
        }
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string, 5: ?int, 6: ?string} */
    private function identityFromRow(mixed $row): array
    {
        $metaId = $this->rowValue($row, MetaConfig::schema_fields_META_ID);

        return [
            $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_NAMESPACE)),
            $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_CONFIG_KEY)),
            $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_SCOPE)),
            $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_LOCALE)),
            $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_IDENTIFY_ID)),
            $metaId === null || $metaId === '' ? null : (int)$metaId,
            $this->nullableString($this->rowValue($row, MetaConfig::schema_fields_META_IDENTIFY)),
        ];
    }

    private function rowValue(mixed $row, string $field, mixed $default = null): mixed
    {
        if (is_array($row)) {
            return array_key_exists($field, $row) ? $row[$field] : $default;
        }
        if (is_object($row) && method_exists($row, 'getData')) {
            return $row->getData($field);
        }

        return $default;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
