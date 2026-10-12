<?php
/**
 * Fix audit issues in destinations.json (public catalog quality).
 * - Drop AAAAA shell duplicates / Korean mislabel / broken Oriental Pearl-2
 * - Rename remaining unique shells + numeric 798 id
 * - Rebuild index-by-region / type / scenery
 *
 * Usage: php app/design/Weline/playinginchina/scripts/fix-destination-audit-issues.php
 */

declare(strict_types=1);

$dataDir = dirname(__DIR__) . '/data';
$path = $dataDir . '/destinations.json';
$destinations = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

/** @var array<string, array{action:string, id?:string, name?:string, cn?:string, city?:string, province?:string, region?:string, types?:list<string>, scenery?:string, hl?:list<string>}> */
$ops = [
    // Exact duplicates of better canonical rows → drop
    'aaaaa-tourist-attractions-of-china' => ['action' => 'drop', 'reason' => 'dup of gen-gong-fu-guo'],
    'aaaaa-tourist-attractions-of-china-2' => ['action' => 'drop', 'reason' => 'dup of laojunshan + jiguan-cave'],
    'aaaaa-tourist-attractions-of-china-5' => ['action' => 'drop', 'reason' => 'dup of yanoda'],
    'aaaaa-tourist-attractions-of-china-6' => ['action' => 'drop', 'reason' => 'dup of binglang'],
    'aaaaa-tourist-attractions-of-china-7' => ['action' => 'drop', 'reason' => 'dup of jinfo'],
    'haedong-yonggungsa' => ['action' => 'drop', 'reason' => 'Korean temple mislabeled; keep an-shun-long-gong'],
    'oriental-pearl-tower-2' => ['action' => 'drop', 'reason' => 'EN/CN mismatch duplicate of oriental-pearl-tower'],

    // Unique shells → rename
    'aaaaa-tourist-attractions-of-china-3' => [
        'action' => 'rename',
        'id' => 'huangpi-mulan-eco',
        'name' => 'Huangpi Mulan Cultural Ecological Area',
        'cn' => '黄陂木兰文化生态旅游区',
        'city' => 'Wuhan',
        'province' => 'Hubei',
        'region' => 'Central China',
        'types' => ['history', 'culture', 'nature'],
        'scenery' => 'heritage',
        'hl' => [
            'Mulan-themed cultural parks and ecological hills in Wuhan Huangpi — an easy day trip from Hankou.',
            'Combine with Wuhan city sights; go on a weekday to avoid local tour groups.',
        ],
    ],
    'aaaaa-tourist-attractions-of-china-4' => [
        'action' => 'rename',
        'id' => 'shennongxi-trackers',
        'name' => 'Shennong Stream (Trackers Culture)',
        'cn' => '神龙溪纤夫文化旅游区',
        'city' => 'Badong',
        'province' => 'Hubei',
        'region' => 'Central China',
        'types' => ['nature', 'hiking', 'culture'],
        'scenery' => 'gorges',
        'hl' => [
            'A Yangtze tributary gorge famous for traditional boat trackers — usually done as a Three Gorges side trip.',
            'Wear grippy shoes; mist and spray make rocks slippery.',
        ],
    ],
    'aaaaa-tourist-attractions-of-china-8' => [
        'action' => 'rename',
        'id' => 'taklamakan-359-culture',
        'name' => 'Taklamakan 359 Brigade Cultural Tourism Area',
        'cn' => '塔克拉玛干·三五九旅文化旅游区',
        'city' => 'Aksu',
        'province' => 'Xinjiang',
        'region' => 'Northwest China',
        'types' => ['history', 'culture', 'desert'],
        'scenery' => 'desert',
        'hl' => [
            'Desert-edge cultural site linked to the 359 Brigade reclamation story — pair with southern Xinjiang desert travel.',
            'Bring sun protection and water; summer heat is extreme.',
        ],
    ],
    '798' => [
        'action' => 'rename',
        'id' => '798-art-district',
        'name' => '798 Art District',
        'cn' => '798艺术区',
        'city' => 'Beijing',
        'province' => 'Beijing',
        'region' => 'North China',
    ],
];

$byId = [];
foreach ($destinations as $row) {
    $byId[(string) $row['id']] = $row;
}

$dropped = [];
$renamed = [];
$out = [];

foreach ($destinations as $row) {
    $oldId = (string) $row['id'];
    if (!isset($ops[$oldId])) {
        $out[] = $row;
        continue;
    }
    $op = $ops[$oldId];
    if (($op['action'] ?? '') === 'drop') {
        $dropped[] = $oldId . ' (' . ($op['reason'] ?? '') . ')';
        continue;
    }
    if (($op['action'] ?? '') === 'rename') {
        $newId = (string) ($op['id'] ?? $oldId);
        if ($newId !== $oldId && isset($byId[$newId])) {
            throw new RuntimeException("Target id already exists: {$newId}");
        }
        $row['id'] = $newId;
        foreach (['name', 'cn', 'city', 'province', 'region', 'scenery'] as $k) {
            if (isset($op[$k]) && is_string($op[$k]) && $op[$k] !== '') {
                $row[$k] = $op[$k];
            }
        }
        if (isset($op['types']) && is_array($op['types'])) {
            $row['types'] = array_values($op['types']);
        }
        if (isset($op['hl']) && is_array($op['hl'])) {
            $row['hl'] = array_values($op['hl']);
        }
        // Soften 798 approx map if still far from Beijing (Chaoyang ~39.98,116.49)
        if ($newId === '798-art-district') {
            $row['map'] = [
                'x' => (float) ($row['map']['x'] ?? 33),
                'y' => (float) ($row['map']['y'] ?? 22.8),
                'lat' => 39.9841,
                'lng' => 116.4954,
                'coord' => 'approx',
            ];
        }
        $renamed[] = "{$oldId} → {$newId}";
        $out[] = $row;
        continue;
    }
    $out[] = $row;
}

$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
file_put_contents($path, json_encode(array_values($out), $jsonFlags) . "\n");

// Rebuild simple indexes
$byRegion = [];
$byType = [];
$byScenery = [];
foreach ($out as $row) {
    $id = (string) $row['id'];
    $region = (string) ($row['region'] ?? 'Unknown');
    $byRegion[$region][] = $id;
    foreach ($row['types'] ?? [] as $t) {
        $byType[(string) $t][] = $id;
    }
    $scenery = (string) ($row['scenery'] ?? 'unknown');
    $byScenery[$scenery][] = $id;
}
ksort($byRegion);
ksort($byType);
ksort($byScenery);
file_put_contents($dataDir . '/index-by-region.json', json_encode($byRegion, $jsonFlags) . "\n");
file_put_contents($dataDir . '/index-by-type.json', json_encode($byType, $jsonFlags) . "\n");
file_put_contents($dataDir . '/index-by-scenery.json', json_encode($byScenery, $jsonFlags) . "\n");

// Patch travel-types / routes / top20 id references 798 → 798-art-district
$idMap = ['798' => '798-art-district'];
foreach (['travel-types.json', 'routes.json', 'top20.json', 'route-lines.json', 'hotels.json'] as $rel) {
    $fp = $dataDir . '/' . $rel;
    if (!is_file($fp)) {
        continue;
    }
    $raw = (string) file_get_contents($fp);
    $changed = false;
    foreach ($idMap as $from => $to) {
        // JSON string id occurrences
        $next = str_replace('"' . $from . '"', '"' . $to . '"', $raw);
        if ($next !== $raw) {
            $raw = $next;
            $changed = true;
        }
    }
    if ($changed) {
        file_put_contents($fp, $raw);
        echo "patched {$rel}\n";
    }
}

echo 'OK total=' . count($out)
    . ' dropped=' . count($dropped)
    . ' renamed=' . count($renamed) . PHP_EOL;
foreach ($dropped as $line) {
    echo "  DROP {$line}\n";
}
foreach ($renamed as $line) {
    echo "  REN {$line}\n";
}
