<?php
/**
 * Reclassify zoo / safari / aquarium destinations as wildlife (+ family).
 *
 * Usage:
 *   php app/design/Weline/playinginchina/scripts/fix-zoo-wildlife-types.php
 *   php app/design/Weline/playinginchina/scripts/fix-zoo-wildlife-types.php --dry-run
 *
 * Dual-writes theme + websites cabinet shards via PlayingInChinaDestinationStore.
 * Then rebuild type/scenery indexes and run build-route-themes.php separately.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/frontend/includes/PlayingInChinaDestinationStore.php';

$dryRun = in_array('--dry-run', $argv, true);
// scripts/ → playinginchina → Weline → design → app → repo root
$repoRoot = dirname(__DIR__, 5);
$themeData = dirname(__DIR__) . '/data';
if (!is_dir($repoRoot . '/app/design/Weline/playinginchina')) {
    fwrite(STDERR, "Bad repoRoot={$repoRoot}\n");
    exit(1);
}

$destinations = PlayingInChinaDestinationStore::loadAll($themeData);
if ($destinations === []) {
    fwrite(STDERR, "No destinations loaded from {$themeData}\n");
    exit(1);
}

/** Exclude false positives (ethnic Shui museum, movies, paleo museums as primary). */
$excludeRe = '/水族博物馆|zootopia|paleozoological|古动物|恐龙园|china.?dinosaurs/iu';

/** Positive zoo / safari / aquarium signals. */
$includeRe = '/\bzoo\b|\bsafari\b|\baquarium\b|zoological|zoopark|wildlife.?park|动物园|野生动物|海洋馆|水族馆|熊猫基地|panda.?base|chimelong/iu';

$changed = [];
$skipped = [];

foreach ($destinations as $i => $d) {
    if (!is_array($d)) {
        continue;
    }
    $id = (string)($d['id'] ?? '');
    $name = (string)($d['name'] ?? '');
    $cn = (string)($d['cn'] ?? '');
    $blob = $id . ' ' . $name . ' ' . $cn;

    if ($excludeRe !== '' && preg_match($excludeRe, $blob) === 1) {
        $skipped[] = ['id' => $id, 'reason' => 'exclude_pattern', 'name' => $name];
        continue;
    }
    if (preg_match($includeRe, $blob) !== 1) {
        continue;
    }

    $types = array_values(array_unique(array_map('strval', (array)($d['types'] ?? []))));
    $beforeTypes = $types;
    $beforeScenery = (string)($d['scenery'] ?? '');

    // Drop mistaken heritage labels for zoo venues.
    $types = array_values(array_filter(
        $types,
        static fn(string $t): bool => !in_array($t, ['history', 'culture', 'ancient', 'religion', 'art'], true)
    ));
    if (!in_array('wildlife', $types, true)) {
        array_unshift($types, 'wildlife');
    }
    if (!in_array('family', $types, true)) {
        $types[] = 'family';
    }
    // Keep nature if already present; otherwise add for soft overlap.
    if (!in_array('nature', $types, true)) {
        $types[] = 'nature';
    }

    $scenery = $beforeScenery;
    if ($scenery === '' || $scenery === 'heritage' || $scenery === 'ancient' || $scenery === 'temples') {
        $lower = strtolower($blob);
        if (str_contains($lower, 'aquarium') || str_contains($cn, '水族馆') || str_contains($cn, '海洋馆')) {
            $scenery = 'coast';
        } elseif (str_contains($lower, 'forest') || str_contains($cn, '森林') || str_contains($lower, 'safari') || str_contains($cn, '野生')) {
            $scenery = 'forest';
        } else {
            $scenery = 'city';
        }
    }

    $typesChanged = $beforeTypes !== $types;
    $sceneryChanged = $beforeScenery !== $scenery;
    if (!$typesChanged && !$sceneryChanged) {
        continue;
    }

    $destinations[$i]['types'] = $types;
    $destinations[$i]['scenery'] = $scenery;
    $changed[] = [
        'id' => $id,
        'name' => $name,
        'types_before' => $beforeTypes,
        'types_after' => $types,
        'scenery_before' => $beforeScenery,
        'scenery_after' => $scenery,
    ];
}

echo 'matched_changed=' . count($changed) . ' skipped_exclude=' . count($skipped) . "\n";
foreach (array_slice($changed, 0, 15) as $row) {
    echo sprintf(
        "  %s | %s → [%s] scenery %s→%s\n",
        $row['id'],
        $row['name'],
        implode(',', $row['types_after']),
        $row['scenery_before'] ?: '-',
        $row['scenery_after']
    );
}
if (count($changed) > 15) {
    echo '  … +' . (count($changed) - 15) . " more\n";
}

if ($dryRun) {
    echo "dry-run: no write\n";
    exit(0);
}

$stats = PlayingInChinaDestinationStore::dualWrite($destinations, $repoRoot);
echo 'dualWrite theme=' . $stats['theme']['total'] . ' shards=' . $stats['theme']['shards']
    . ' cabinet=' . $stats['cabinet']['total'] . ' shards=' . $stats['cabinet']['shards'] . "\n";

// Rebuild type / scenery / region indexes (same pattern as fix-destination-audit-issues.php).
$byType = [];
$byScenery = [];
$byRegion = [];
foreach ($destinations as $d) {
    if (!is_array($d)) {
        continue;
    }
    $id = (string)($d['id'] ?? '');
    if ($id === '') {
        continue;
    }
    foreach ((array)($d['types'] ?? []) as $t) {
        $t = (string)$t;
        if ($t === '') {
            continue;
        }
        $byType[$t][] = $id;
    }
    $sc = (string)($d['scenery'] ?? '');
    if ($sc !== '') {
        $byScenery[$sc][] = $id;
    }
    $region = (string)($d['region'] ?? '');
    if ($region !== '') {
        $byRegion[$region][] = $id;
    }
}
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
foreach ([$themeData, $repoRoot . '/websites/playinginchina.com/data'] as $root) {
    file_put_contents($root . '/index-by-type.json', json_encode($byType, $flags) . "\n");
    file_put_contents($root . '/index-by-scenery.json', json_encode($byScenery, $flags) . "\n");
    file_put_contents($root . '/index-by-region.json', json_encode($byRegion, $flags) . "\n");
}
echo "indexes rebuilt (theme + cabinet)\n";

$reportDir = $repoRoot . '/websites/playinginchina.com/opc/dispatch/2026-10-12T1515-zoo-wildlife-connect';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}
file_put_contents(
    $reportDir . '/p0-zoo-reclassify.json',
    json_encode([
        'as_of' => gmdate('c'),
        'changed_count' => count($changed),
        'skipped_exclude' => $skipped,
        'changed' => $changed,
    ], $flags | JSON_PRETTY_PRINT) . "\n"
);
echo "report → {$reportDir}/p0-zoo-reclassify.json\n";
