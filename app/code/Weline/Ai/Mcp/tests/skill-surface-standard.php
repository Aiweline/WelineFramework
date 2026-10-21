<?php

declare(strict_types=1);

use LearningMcp\GuidanceWorkflowCatalog;
use LearningMcp\McpSkillCatalog;
use LearningMcp\SkillSurfaceV2;

require dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;
$checks = [];

function standardCheck(bool $ok, string $label): void
{
    global $failed, $checks;
    $checks[] = ['label' => $label, 'passed' => $ok];
    fwrite(($ok ? STDOUT : STDERR), ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n");
    if (!$ok) {
        $failed = true;
    }
}

$surfaces = SkillSurfaceV2::all();
standardCheck($surfaces !== [], 'skill-v2 surface files exist');
standardCheck(isset($surfaces[GuidanceWorkflowCatalog::SURFACE_DESIGN_TASTE]), 'design_taste surface file exists');

foreach ($surfaces as $id => $surface) {
    $violations = SkillSurfaceV2::lint($surface);
    standardCheck($violations === [], 'skill-v2 budget/structure: ' . $id);
    foreach ($violations as $violation) {
        fwrite(STDERR, '  - ' . $violation . "\n");
    }
    standardCheck(
        ($surface['spec'] ?? '') === SkillSurfaceV2::SPEC,
        'skill-v2 spec marker: ' . $id
    );
    standardCheck(
        ($surface['id'] ?? '') === $id,
        'skill-v2 id matches filename: ' . $id
    );
    standardCheck(
        is_array($surface['authoritative_docs'] ?? null) && ($surface['authoritative_docs'] ?? []) !== [],
        'skill-v2 declares authoritative docs: ' . $id
    );
    standardCheck(
        is_string($surface['description'] ?? null) && trim((string) $surface['description']) !== '',
        'skill-v2 description present: ' . $id
    );
    standardCheck(
        count($surface['triggers'] ?? []) >= 2,
        'skill-v2 has >=2 triggers: ' . $id
    );
}

// Every override must be reachable from the merged catalog (no orphan files).
$catalog = GuidanceWorkflowCatalog::allSurfaces();
foreach (array_keys($surfaces) as $id) {
    standardCheck(isset($catalog[$id]), 'skill-v2 surface reachable from allSurfaces: ' . $id);
}

// design_taste must be loadable through MCP get_skill and rendered as a v2 body.
$taste = McpSkillCatalog::get('design_taste', true);
standardCheck(is_array($taste), 'design_taste loadable via get_skill');
$tasteBody = (string) ($taste['content'] ?? '');
standardCheck(str_contains($tasteBody, '## 步骤'), 'design_taste body has 步骤');
standardCheck(str_contains($tasteBody, '## 检查清单'), 'design_taste body has 检查清单');
standardCheck(str_contains($tasteBody, '## 证据'), 'design_taste body has 证据');
standardCheck(mb_strlen($tasteBody) <= SkillSurfaceV2::BUDGET['body_chars'] + 800, 'design_taste rendered body stays small');

// Legacy inventory is informational: it must only shrink over time.
$legacy = SkillSurfaceV2::legacySurfaceIds();
fwrite(STDOUT, sprintf(
    "[INFO] skill-v2=%d legacy=%d\n[INFO] legacy ids: %s\n",
    count($surfaces),
    count($legacy),
    implode(', ', $legacy)
));

$report = [
    'schema_version' => 'skill-surface-standard-tests.v1',
    'spec' => SkillSurfaceV2::SPEC,
    'passed' => !$failed,
    'v2_count' => count($surfaces),
    'legacy_count' => count($legacy),
    'checks' => $checks,
];
fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

exit($failed ? 1 : 0);
