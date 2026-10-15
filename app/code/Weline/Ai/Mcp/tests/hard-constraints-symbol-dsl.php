<?php

declare(strict_types=1);

use LearningMcp\HardConstraintsCatalog;
use LearningMcp\HardConstraintsSymbolDsl;

require dirname(__DIR__) . '/src/bootstrap.php';

$symbol = HardConstraintsCatalog::package();
$full = HardConstraintsCatalog::packageFull();
$fullIds = [];
foreach (array_merge($full['rules'] ?? [], $full['mcp_operational'] ?? []) as $rule) {
    if (!is_array($rule)) {
        continue;
    }
    $id = trim((string) ($rule['id'] ?? ''));
    if ($id !== '') {
        $fullIds[$id] = true;
    }
}
$symbolIds = [];
$capOk = true;
foreach (array_merge($symbol['rules'] ?? [], $symbol['mcp_operational'] ?? []) as $rule) {
    if (!is_array($rule)) {
        continue;
    }
    $id = trim((string) ($rule['id'] ?? ''));
    if ($id === '') {
        continue;
    }
    $symbolIds[$id] = true;
    if (isset($rule['summary'])) {
        $capOk = false;
    }
    if (mb_strlen((string) ($rule['formula'] ?? ''), 'UTF-8') > HardConstraintsSymbolDsl::FORMULA_MAX) {
        $capOk = false;
    }
    if (mb_strlen((string) ($rule['gloss_zh'] ?? ''), 'UTF-8') > HardConstraintsSymbolDsl::GLOSS_MAX) {
        $capOk = false;
    }
    if (trim((string) ($rule['formula'] ?? '')) === '' || trim((string) ($rule['gloss_zh'] ?? '')) === '') {
        $capOk = false;
    }
}
$missing = array_diff_key($fullIds, $symbolIds);
$extra = array_diff_key($symbolIds, $fullIds);
$expand = HardConstraintsSymbolDsl::expandBodies(['architecture_grade_change_only', 'intent_lane_router']);
$lookupArch = HardConstraintsSymbolDsl::lookup('architecture_grade_change_only');
$lookupAtom = HardConstraintsSymbolDsl::lookup('Arch');
$consistency = HardConstraintsSymbolDsl::validateCatalogConsistency();
$lang = HardConstraintsSymbolDsl::languageTable();
$hasLifecycle = false;
foreach ($full['mcp_operational'] ?? [] as $rule) {
    if (is_array($rule) && ($rule['id'] ?? '') === 'hc_sl_rule_lifecycle_gate') {
        $hasLifecycle = true;
        break;
    }
}

$checks = [
    'symbol form' => ($symbol['form'] ?? '') === 'symbol',
    'expand_via pinned' => ($symbol['expand_via'] ?? '') === HardConstraintsSymbolDsl::EXPAND_VIA,
    'language_schema pointer' => ($symbol['language_schema'] ?? '') === HardConstraintsSymbolDsl::LANGUAGE_SCHEMA
        && ($symbol['language_schema_path'] ?? '') === HardConstraintsSymbolDsl::LANGUAGE_SCHEMA_PATH,
    'language table loads' => ($lang['schema'] ?? '') === HardConstraintsSymbolDsl::LANGUAGE_SCHEMA
        && isset($lang['operators'], $lang['lifecycle']['add'], $lang['lifecycle']['update'], $lang['lifecycle']['delete']),
    'legend non-empty' => is_array($symbol['legend'] ?? null) && $symbol['legend'] !== [],
    'token under budget' => (int) ($symbol['token_usage']['estimated'] ?? PHP_INT_MAX)
        < HardConstraintsSymbolDsl::PACKAGE_TOKEN_BUDGET
        && ($symbol['token_usage']['budget_exceeded'] ?? true) === false,
    'coverage matches full ids' => $missing === [] && $extra === [] && count($fullIds) >= 10,
    'caps and no hot summary' => $capOk,
    'catalog consistency' => ($consistency['ok'] ?? false) === true,
    'expand returns summary' => count($expand) === 2
        && strlen((string) ($expand[0]['summary'] ?? '')) > 100
        && ($expand[0]['id'] ?? '') === 'architecture_grade_change_only',
    'lookup id' => is_array($lookupArch)
        && ($lookupArch['id'] ?? '') === 'architecture_grade_change_only'
        && strlen((string) ($lookupArch['summary'] ?? '')) > 100,
    'lookup atom' => is_array($lookupAtom)
        && ($lookupAtom['sym'] ?? '') === 'Arch'
        && in_array('architecture_grade_change_only', $lookupAtom['rule_ids'] ?? [], true),
    'full form still has summary' => ($full['form'] ?? '') === 'full'
        && isset($full['rules'][0]['summary']),
    'lifecycle gate present' => $hasLifecycle,
    'mcpInstructions is HC-SL spine' => str_contains(HardConstraintsCatalog::mcpInstructions(), 'HC-SL')
        && str_contains(HardConstraintsCatalog::mcpInstructions(), HardConstraintsSymbolDsl::EXPAND_VIA)
        && !str_contains(HardConstraintsCatalog::mcpInstructions(), 'required 默认 JSON 注入'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "hard-constraints-symbol-dsl FAIL: " . implode(', ', $failed) . "\n");
    fwrite(STDERR, 'missing_ids=' . count($missing) . ' extra_ids=' . count($extra)
        . ' est=' . ($symbol['token_usage']['estimated'] ?? '?') . "\n");
    exit(1);
}

echo 'hard-constraints-symbol-dsl PASS (' . count($checks) . " checks)\n";
exit(0);
