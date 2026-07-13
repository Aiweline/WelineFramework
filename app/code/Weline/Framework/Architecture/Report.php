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
     * @return array{clean: bool, metrics: array<string, int>, counts: array<string, int>, findings: list<array{rule: string, message: string, file: string, line: int}>}
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
}
