<?php

declare(strict_types=1);

/**
 * Upsert translated locale packs into website=0 blog posts.
 *
 * Pack JSON shape (one locale file or multi-locale file):
 * {
 *   "locale": "hi_IN",
 *   "items": [
 *     {
 *       "base_slug": "foo",
 *       "title": "...",
 *       "excerpt": "...",
 *       "content": "<p>...</p>",
 *       "keywords": "...",
 *       "author_bio": "...",
 *       "author_job_title": "..."
 *     }
 *   ]
 * }
 *
 * Or multi:
 * { "locales": { "hi_IN": [ ...items ], "ar_SA": [ ... ] } }
 *
 * Usage:
 *   php app/code/Weline/Blog/data/upsert-blog-locale-packs.php app/code/Weline/Blog/data/locale-packs/hi_IN/batch-01.json
 *   php app/code/Weline/Blog/data/upsert-blog-locale-packs.php app/code/Weline/Blog/data/locale-packs
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogContentCache;
use Weline\Blog\Service\BlogContentResolver;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

$target = $argv[1] ?? (__DIR__ . '/locale-packs');
$files = [];
if (is_file($target)) {
    $files[] = $target;
} elseif (is_dir($target)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.json')) {
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR . '_source' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $files[] = $file->getPathname();
        }
    }
    sort($files);
} else {
    fwrite(STDERR, "path not found: {$target}\n");
    exit(1);
}

$admin = ObjectManager::getInstance(BlogPostAdminService::class);
$postModel = ObjectManager::getInstance(Post::class);
$suffixMap = BlogContentResolver::localeSlugSuffixMap();

$written = 0;
$updated = 0;
$skipped = 0;

/**
 * @param array<string, mixed> $item
 */
function upsertLocaleItem(
    BlogPostAdminService $admin,
    Post $postModel,
    array $suffixMap,
    string $locale,
    array $item,
): string {
    $locale = trim(str_replace('-', '_', $locale));
    $suffix = $suffixMap[$locale] ?? '';
    if ($suffix === '') {
        throw new InvalidArgumentException('unsupported locale ' . $locale);
    }
    $baseSlug = trim(strtolower((string)($item['base_slug'] ?? '')));
    if ($baseSlug === '') {
        throw new InvalidArgumentException('base_slug required');
    }
    $storageSlug = $baseSlug . '-' . $suffix;
    $title = trim((string)($item['title'] ?? ''));
    $excerpt = trim((string)($item['excerpt'] ?? ''));
    $content = (string)($item['content'] ?? '');
    if ($title === '' || $content === '') {
        throw new InvalidArgumentException('title/content required for ' . $storageSlug);
    }

    $existing = $postModel->clearData()->reset()
        ->where(Post::schema_fields_WEBSITE_ID, 0)
        ->where(Post::schema_fields_LOCALE, $locale)
        ->where(Post::schema_fields_SLUG, $storageSlug)
        ->find()
        ->fetchArray();
    $postId = is_array($existing) ? (int)($existing[Post::schema_fields_ID] ?? 0) : 0;

    $source = $postModel->clearData()->reset()
        ->where(Post::schema_fields_WEBSITE_ID, 0)
        ->where(Post::schema_fields_LOCALE, 'en_US')
        ->where(Post::schema_fields_SLUG, $baseSlug . '-en')
        ->find()
        ->fetchArray();
    if (!is_array($source) || (int)($source[Post::schema_fields_ID] ?? 0) <= 0) {
        throw new RuntimeException('missing en source for ' . $baseSlug);
    }

    $payload = [
        'post_id' => $postId,
        'website_id' => 0,
        'locale' => $locale,
        'slug' => $storageSlug,
        'title' => $title,
        'excerpt' => $excerpt,
        'content' => $content,
        'cover_image' => (string)($item['cover_image'] ?? $source[Post::schema_fields_COVER_IMAGE] ?? ''),
        'author' => (string)($item['author'] ?? $source[Post::schema_fields_AUTHOR] ?? ''),
        'author_url' => (string)($item['author_url'] ?? $source[Post::schema_fields_AUTHOR_URL] ?? ''),
        'author_bio' => (string)($item['author_bio'] ?? $source[Post::schema_fields_AUTHOR_BIO] ?? ''),
        'author_job_title' => (string)($item['author_job_title'] ?? $source[Post::schema_fields_AUTHOR_JOB_TITLE] ?? ''),
        'author_same_as' => (string)($item['author_same_as'] ?? $source[Post::schema_fields_AUTHOR_SAME_AS] ?? ''),
        'keywords' => (string)($item['keywords'] ?? $source[Post::schema_fields_KEYWORDS] ?? ''),
        'category_id' => (int)($item['category_id'] ?? $source[Post::schema_fields_CATEGORY_ID] ?? 0),
        'status' => Post::STATUS_PUBLISHED,
        'published_at' => (string)($item['published_at'] ?? $source[Post::schema_fields_PUBLISHED_AT] ?? ''),
    ];
    $admin->save($payload);

    return $postId > 0 ? 'updated' : 'created';
}

foreach ($files as $file) {
    $raw = file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        fwrite(STDERR, "invalid json: {$file}\n");
        continue;
    }

    /** @var array<string, list<array<string,mixed>>> $localeItems */
    $localeItems = [];
    if (isset($data['locales']) && is_array($data['locales'])) {
        foreach ($data['locales'] as $locale => $items) {
            if (!is_array($items)) {
                continue;
            }
            $localeItems[(string)$locale] = array_values(array_filter($items, 'is_array'));
        }
    } elseif (isset($data['locale'], $data['items']) && is_array($data['items'])) {
        $localeItems[(string)$data['locale']] = array_values(array_filter($data['items'], 'is_array'));
    } else {
        fwrite(STDERR, "unsupported pack shape: {$file}\n");
        continue;
    }

    foreach ($localeItems as $locale => $items) {
        foreach ($items as $item) {
            try {
                $result = upsertLocaleItem($admin, $postModel, $suffixMap, $locale, $item);
                if ($result === 'created') {
                    ++$written;
                } else {
                    ++$updated;
                }
                echo "{$result} {$locale} " . ($item['base_slug'] ?? '') . "\n";
            } catch (Throwable $e) {
                ++$skipped;
                fwrite(STDERR, "skip {$locale} " . ($item['base_slug'] ?? '') . ': ' . $e->getMessage() . "\n");
            }
        }
    }
}

StorefrontScopeHotCache::resetProcessCache();
BlogContentCache::clearRequestSnapshots();
try {
    ObjectManager::getInstance(NamespaceGenerationInterface::class)
        ->bumpMany(BlogContentCache::changedPaths(0, 0));
} catch (Throwable) {
}

echo "done created={$written} updated={$updated} skipped={$skipped} files=" . count($files) . "\n";
