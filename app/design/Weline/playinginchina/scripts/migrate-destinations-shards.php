#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 将扁平 destinations.json 迁移为省级分片，并双写主题 + 站点柜。
 *
 *   php app/design/Weline/playinginchina/scripts/migrate-destinations-shards.php
 */

$repoRoot = \dirname(__DIR__, 5);
require_once \dirname(__DIR__) . '/frontend/includes/PlayingInChinaDestinationStore.php';

$themeData = $repoRoot . '/app/design/Weline/playinginchina/data';
$flat = $themeData . '/destinations.json';
if (!\is_file($flat)) {
    \fwrite(\STDERR, "Missing {$flat}\n");
    \exit(1);
}

$rows = \json_decode((string)\file_get_contents($flat), true);
if (!\is_array($rows)) {
    \fwrite(\STDERR, "Bad destinations.json\n");
    \exit(1);
}

$result = \PlayingInChinaDestinationStore::dualWrite(\array_values($rows), $repoRoot);
echo \json_encode([
    'ok' => true,
    'input' => \count($rows),
    'theme' => $result['theme'],
    'cabinet' => $result['cabinet'],
    'manifest_theme' => \PlayingInChinaDestinationStore::manifestPath($themeData),
    'manifest_cabinet' => \PlayingInChinaDestinationStore::manifestPath($repoRoot . '/websites/playinginchina.com/data'),
], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n";
