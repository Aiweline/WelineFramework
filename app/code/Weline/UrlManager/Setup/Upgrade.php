<?php

declare(strict_types=1);

namespace Weline\UrlManager\Setup;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\UrlManager\Model\UrlRewrite;

/** Data-only phase-1 migration for exact UrlRewrite path fingerprints. */
final class Upgrade implements UpgradeInterface
{
    public function __construct(
        private readonly UrlRewrite $urlRewrites,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public function setup(Setup $setup, Context $context): void
    {
        $this->transactions->runWrite(
            $this->urlRewrites->getConnection(),
            function (): void {
                $inspection = $this->inspectRows($this->readAllRows(), true);
                $this->assertInspection($inspection, '预检');

                foreach ($inspection['backfill'] as $rewriteId => $fingerprint) {
                    $this->urlRewrites->newQuery()
                        ->where(UrlRewrite::schema_fields_ID, $rewriteId)
                        ->update([
                            UrlRewrite::schema_fields_PATH_FINGERPRINT => $fingerprint,
                        ], UrlRewrite::schema_fields_ID)
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
        $rows = $this->urlRewrites->newQuery()
            ->order(UrlRewrite::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return \is_array($rows) ? \array_values($rows) : [];
    }

    /**
     * @param list<mixed> $rows
     * @return array{backfill: array<int, string>, mismatch: list<int>, missing: list<int>, invalid: list<int>}
     */
    private function inspectRows(array $rows, bool $allowMissing): array
    {
        $inspection = [
            'backfill' => [],
            'mismatch' => [],
            'missing' => [],
            'invalid' => [],
        ];

        foreach ($rows as $row) {
            $rewriteId = (int)$this->rowValue($row, UrlRewrite::schema_fields_ID, 0);
            $path = $this->rowValue($row, UrlRewrite::schema_fields_PATH);
            if ($rewriteId <= 0 || !\is_string($path)) {
                $inspection['invalid'][] = $rewriteId;
                continue;
            }

            $expected = UrlRewrite::pathFingerprint($path);
            $stored = $this->rowValue($row, UrlRewrite::schema_fields_PATH_FINGERPRINT);
            if ($stored === null || $stored === '') {
                if ($allowMissing) {
                    $inspection['backfill'][$rewriteId] = $expected;
                } else {
                    $inspection['missing'][] = $rewriteId;
                }
                continue;
            }
            if (!\is_string($stored) || !\hash_equals($expected, $stored)) {
                $inspection['mismatch'][] = $rewriteId;
            }
        }

        foreach (['mismatch', 'missing', 'invalid'] as $key) {
            $inspection[$key] = \array_values(\array_unique(\array_map('intval', $inspection[$key])));
            \sort($inspection[$key], \SORT_NUMERIC);
        }

        return $inspection;
    }

    /**
     * @param array{backfill: array<int, string>, mismatch: list<int>, missing: list<int>, invalid: list<int>} $inspection
     */
    private function assertInspection(array $inspection, string $stage): void
    {
        $problems = [];
        foreach ([
            'mismatch' => 'fingerprint_mismatch',
            'missing' => 'fingerprint_missing',
            'invalid' => 'invalid_row',
        ] as $key => $label) {
            if ($inspection[$key] !== []) {
                $problems[] = $label . ' rewrite_id=' . \implode(',', $inspection[$key]);
            }
        }
        if ($problems !== []) {
            throw new \RuntimeException((string)__('路由重写路径指纹%{stage}失败：%{problems}', [
                'stage' => $stage,
                'problems' => \implode('; ', $problems),
            ]));
        }
    }

    private function rowValue(mixed $row, string $field, mixed $default = null): mixed
    {
        if (\is_array($row)) {
            return \array_key_exists($field, $row) ? $row[$field] : $default;
        }
        if (\is_object($row) && \method_exists($row, 'getData')) {
            return $row->getData($field);
        }

        return $default;
    }
}
