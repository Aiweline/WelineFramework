<?php

declare(strict_types=1);

/**
 * Export published en_US posts as translation source batches.
 *
 * Usage:
 *   php app/code/Weline/Blog/data/export-en-posts-for-locale-packs.php
 *   php app/code/Weline/Blog/data/export-en-posts-for-locale-packs.php --batch-size=10
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

$batchSize = 10;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--batch-size=')) {
        $batchSize = max(1, (int)substr($arg, strlen('--batch-size=')));
    }
}

$outDir = __DIR__ . '/locale-packs/_source';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    throw new RuntimeException('Cannot create ' . $outDir);
}

$model = ObjectManager::getInstance(Post::class);
$rows = $model->clearData()->reset()
    ->where(Post::schema_fields_WEBSITE_ID, 0)
    ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
    ->where(Post::schema_fields_LOCALE, 'en_US')
    ->order(Post::schema_fields_ID, 'ASC')
    ->select()
    ->fetchArray();
if (!is_array($rows)) {
    $rows = [];
}

$items = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $storageSlug = trim((string)($row[Post::schema_fields_SLUG] ?? ''));
    $baseSlug = BlogContentResolver::localeSlugSuffixMap()
        ? preg_replace('/-en$/', '', $storageSlug) ?? $storageSlug
        : $storageSlug;
    if (str_ends_with($storageSlug, '-en')) {
        $baseSlug = substr($storageSlug, 0, -3);
    } else {
        $baseSlug = $storageSlug;
    }
    $items[] = [
        'source_post_id' => (int)($row[Post::schema_fields_ID] ?? 0),
        'website_id' => (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0),
        'base_slug' => $baseSlug,
        'source_locale' => 'en_US',
        'source_slug' => $storageSlug,
        'category_id' => (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0),
        'title' => (string)($row[Post::schema_fields_TITLE] ?? ''),
        'excerpt' => (string)($row[Post::schema_fields_EXCERPT] ?? ''),
        'content' => (string)($row[Post::schema_fields_CONTENT] ?? ''),
        'cover_image' => (string)($row[Post::schema_fields_COVER_IMAGE] ?? ''),
        'author' => (string)($row[Post::schema_fields_AUTHOR] ?? ''),
        'author_url' => (string)($row[Post::schema_fields_AUTHOR_URL] ?? ''),
        'author_bio' => (string)($row[Post::schema_fields_AUTHOR_BIO] ?? ''),
        'author_job_title' => (string)($row[Post::schema_fields_AUTHOR_JOB_TITLE] ?? ''),
        'author_same_as' => (string)($row[Post::schema_fields_AUTHOR_SAME_AS] ?? ''),
        'keywords' => (string)($row[Post::schema_fields_KEYWORDS] ?? ''),
        'published_at' => (string)($row[Post::schema_fields_PUBLISHED_AT] ?? ''),
        'status' => Post::STATUS_PUBLISHED,
    ];
}

foreach (glob($outDir . '/batch-*.json') ?: [] as $old) {
    @unlink($old);
}

$batches = array_chunk($items, $batchSize);
foreach ($batches as $i => $batch) {
    $n = $i + 1;
    $path = sprintf('%s/batch-%02d.json', $outDir, $n);
    $payload = [
        'batch' => $n,
        'source_locale' => 'en_US',
        'count' => count($batch),
        'items' => $batch,
    ];
    file_put_contents(
        $path,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n",
    );
    echo "wrote {$path} count=" . count($batch) . "\n";
}

echo "total_posts=" . count($items) . " batches=" . count($batches) . " batch_size={$batchSize}\n";
