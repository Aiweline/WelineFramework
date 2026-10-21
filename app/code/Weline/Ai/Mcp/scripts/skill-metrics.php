<?php

declare(strict_types=1);

/**
 * Skill surface budget report (skill-v2).
 *
 * Usage:
 *   php app/code/Weline/Ai/Mcp/scripts/skill-metrics.php
 *   php app/code/Weline/Ai/Mcp/scripts/skill-metrics.php --json
 */

require __DIR__ . '/../src/bootstrap.php';

use LearningMcp\GuidanceWorkflowCatalog;
use LearningMcp\SkillSurfaceV2;

$json = in_array('--json', $argv, true);
$rows = [];
$total = 0;
foreach (GuidanceWorkflowCatalog::allSurfaces() as $id => $surface) {
    $chars = SkillSurfaceV2::bodyChars($surface);
    $total += $chars;
    $rows[$id] = [
        'id' => $id,
        'spec' => (string) ($surface['spec'] ?? 'legacy'),
        'body_chars' => $chars,
        'norms' => is_array($surface['norms'] ?? null) ? count($surface['norms']) : 0,
        'norm_ids' => is_array($surface['norm_ids'] ?? null) ? count($surface['norm_ids']) : 0,
        'procedure' => is_array($surface['procedure'] ?? null) ? count($surface['procedure']) : 0,
        'checklist' => is_array($surface['checklist'] ?? null) ? count($surface['checklist']) : 0,
        'docs' => is_array($surface['authoritative_docs'] ?? null) ? count($surface['authoritative_docs']) : 0,
        'violations' => $surface['spec'] === SkillSurfaceV2::SPEC ? SkillSurfaceV2::lint($surface) : [],
    ];
}
uasort($rows, static fn (array $a, array $b): int => $b['body_chars'] <=> $a['body_chars']);

$report = [
    'spec' => SkillSurfaceV2::SPEC,
    'surface_count' => count($rows),
    'legacy_count' => count(SkillSurfaceV2::legacySurfaceIds()),
    'total_body_chars' => $total,
    'avg_body_chars' => (int) round($total / max(1, count($rows))),
    'budget_body_chars' => SkillSurfaceV2::BUDGET['body_chars'],
    'surfaces' => array_values($rows),
];

if ($json) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(0);
}

printf(
    "skill-v2 surfaces=%d legacy=%d total=%d avg=%d budget=%d\n\n",
    $report['surface_count'],
    $report['legacy_count'],
    $report['total_body_chars'],
    $report['avg_body_chars'],
    $report['budget_body_chars'],
);
printf("%-30s %6s %5s %8s %4s %4s %4s %5s\n", 'surface', 'chars', 'norms', 'norm_ids', 'proc', 'chk', 'docs', 'lint');
foreach ($rows as $row) {
    printf(
        "%-30s %6d %5d %8d %4d %4d %4d %5s\n",
        $row['id'],
        $row['body_chars'],
        $row['norms'],
        $row['norm_ids'],
        $row['procedure'],
        $row['checklist'],
        $row['docs'],
        $row['violations'] === [] ? 'ok' : 'FAIL',
    );
}
