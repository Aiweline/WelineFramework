<?php

declare(strict_types=1);

/**
 * Export textile-heritage en_US posts as locale-pack source.
 * Usage: php app/code/Weline/Blog/data/export-textile-heritage-en-source.php
 */

use Weline\Blog\Model\Post;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

$model = ObjectManager::getInstance(Post::class);
$rows = $model->clearData()->reset()
    ->where(Post::schema_fields_WEBSITE_ID, 0)
    ->where(Post::schema_fields_LOCALE, 'en_US')
    ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
    ->select()
    ->fetchArray();

$items = [];
foreach (is_array($rows) ? $rows : [] as $row) {
    if (!is_array($row)) {
        continue;
    }
    $slug = (string)($row[Post::schema_fields_SLUG] ?? '');
    if (!str_starts_with($slug, 'textile-') || !str_ends_with($slug, '-en')) {
        continue;
    }
    $items[] = [
        'source_post_id' => (int)($row[Post::schema_fields_ID] ?? 0),
        'website_id' => 0,
        'base_slug' => substr($slug, 0, -3),
        'source_locale' => 'en_US',
        'source_slug' => $slug,
        'category_id' => (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0),
        'title' => (string)($row[Post::schema_fields_TITLE] ?? ''),
        'excerpt' => (string)($row[Post::schema_fields_EXCERPT] ?? ''),
        'content' => (string)($row[Post::schema_fields_CONTENT] ?? ''),
        'cover_image' => (string)($row[Post::schema_fields_COVER_IMAGE] ?? ''),
        'author' => (string)($row[Post::schema_fields_AUTHOR] ?? ''),
        'keywords' => (string)($row[Post::schema_fields_KEYWORDS] ?? ''),
        'published_at' => (string)($row[Post::schema_fields_PUBLISHED_AT] ?? ''),
        'status' => Post::STATUS_PUBLISHED,
    ];
}

$dir = __DIR__ . '/locale-packs/_source';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create ' . $dir);
}
$path = $dir . '/batch-textile-heritage.json';
file_put_contents(
    $path,
    json_encode(['batch' => 'textile-heritage', 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
);
echo count($items) . ' items -> ' . $path . PHP_EOL;
