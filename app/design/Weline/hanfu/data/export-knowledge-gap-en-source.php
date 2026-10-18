<?php

declare(strict_types=1);

$articles = require __DIR__ . '/seed-blog-knowledge-gap-20261018-content.php';
$items = [];
foreach ($articles as $x) {
    $items[] = [
        'base_slug' => (string)$x['slug'],
        'title' => (string)$x['en']['title'],
        'excerpt' => (string)$x['en']['excerpt'],
        'content' => (string)$x['en']['content'],
        'keywords' => (string)$x['keywords_en'],
        'cover_image' => (string)$x['cover'],
        'author' => 'Amayun Editorial',
    ];
}

$outDir = dirname(__DIR__, 4) . '/code/Weline/Blog/data/locale-packs/_source';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    throw new RuntimeException('Cannot create ' . $outDir);
}
$path = $outDir . '/batch-hanfu-knowledge-gap-20261018.json';
file_put_contents($path, json_encode([
    'batch' => 'hanfu-knowledge-gap-20261018',
    'source_locale' => 'en_US',
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
echo "wrote {$path} items=" . count($items) . PHP_EOL;
