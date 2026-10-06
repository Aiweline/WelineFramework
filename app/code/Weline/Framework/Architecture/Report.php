<?php

declare(strict_types=1);

namespace Weline\Framework\Architecture;

final readonly class Report
{
    /**
     * @param list<Finding> $findings
     * @param array<string, int> $metrics
     */
    public function __construct(
        public array $findings,
        public array $metrics,
    ) {
    }

    public function isClean(): bool
    {
        return $this->findings === [];
    }

    /**
     * @return array<string, int>
     */
    public function countsByRule(): array
    {
        $counts = [];
        foreach ($this->findings as $finding) {
            $counts[$finding->rule] = ($counts[$finding->rule] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }

    /**
     * @return array{rule: string, message: string, file: string, line: int}[]
     */
    public function toArray(): array
    {
        return [
            'clean' => $this->isClean(),
            'metrics' => $this->metrics,
            'counts' => $this->countsByRule(),
            'findings' => array_map(
                static fn(Finding $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }

    /**
     * 棘轮基线：按规则记录当前存量违规指纹集合，供 CI 只阻断新增违规。
     *
     * @return array{schema: string, metrics: array<string, int>, counts: array<string, int>, fingerprints: array<string, list<string>>}
     */
    public function toBaseline(): array
    {
        $fingerprints = [];
        foreach ($this->findings as $finding) {
            $fingerprints[$finding->rule][] = $finding->fingerprint();
        }
        foreach ($fingerprints as $rule => $values) {
            sort($values);
            $fingerprints[$rule] = $values;
        }
        ksort($fingerprints);

        return [
            'schema' => 'weline-architecture-baseline.v1',
            'metrics' => $this->metrics,
            'counts' => $this->countsByRule(),
            'fingerprints' => $fingerprints,
        ];
    }

    /**
     * @param array<array-key, mixed> $baseline
     * @return array{regressions: list<array{rule: string, message: string, file: string, line: int}>, resolved_counts: array<string, int>}
     */
    public function diffAgainstBaseline(array $baseline): array
    {
        if (($baseline['schema'] ?? null) !== 'weline-architecture-baseline.v1') {
            throw new \InvalidArgumentException('Unsupported architecture baseline schema: ' . var_export($baseline['schema'] ?? null, true));
        }

        $known = [];
        foreach ((array)($baseline['fingerprints'] ?? []) as $rule => $values) {
            foreach ((array)$values as $value) {
                $known[(string)$rule . '|' . (string)$value] = true;
            }
        }

        $regressions = [];
        $currentCounts = [];
        foreach ($this->findings as $finding) {
            $currentCounts[$finding->rule] = ($currentCounts[$finding->rule] ?? 0) + 1;
            if (!isset($known[$finding->rule . '|' . $finding->fingerprint()])) {
                $regressions[] = $finding->toArray();
            }
        }

        $resolvedCounts = [];
        foreach ((array)($baseline['counts'] ?? []) as $rule => $count) {
            $remaining = $currentCounts[(string)$rule] ?? 0;
            if ($remaining < (int)$count) {
                $resolvedCounts[(string)$rule] = (int)$count - $remaining;
            }
        }

        return [
            'regressions' => $regressions,
            'resolved_counts' => $resolvedCounts,
        ];
    }
}
