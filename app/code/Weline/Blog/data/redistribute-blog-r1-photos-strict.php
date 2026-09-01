<?php

declare(strict_types=1);

/**
 * Strict photo redistribute for all website-0 published posts.
 *
 * Root cause of “总是同样的图”: 29 product photos × 45 articles × 4 slots ≈ each file in ~7 articles.
 *
 * New hard rules:
 * - Only product-*.jpg
 * - Within article: no duplicate
 * - Each product-*.jpg appears in at most MAX_USAGE articles (cover+body counted)
 * - Prefer 1 cover; add at most 1 body figure only if capacity remains
 * - zh/en same base slug share the same assignment
 *
 * Usage: php app/code/Weline/Blog/data/redistribute-blog-r1-photos-strict.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const PHOTO_BASE = '/media/blog/hanfu/articles/photos';
const PHOTO_DIR = BP . 'pub/media/blog/hanfu/articles/photos';
const CAPTIONS_FILE = __DIR__ . '/hanfu-photo-captions.json';
const MAX_USAGE = 2;

/** @return list<int> */
function poolNumbers(): array
{
    $nums = [];
    foreach (glob(PHOTO_DIR . '/product-*.jpg') ?: [] as $path) {
        if (preg_match('/product-(\d{2})\.jpg$/', basename($path), $m)) {
            $nums[] = (int)$m[1];
        }
    }
    sort($nums);
    if (count($nums) < 10) {
        throw new RuntimeException('Photo pool too small in ' . PHOTO_DIR);
    }

    return array_values(array_unique($nums));
}

/** @return array<string, array{zh:string,en:string}> */
function loadCaptions(): array
{
    $raw = file_get_contents(CAPTIONS_FILE);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $file => $row) {
        if (!is_string($file) || !is_array($row) || !preg_match('/^product-\d{2}\.jpg$/', $file)) {
            continue;
        }
        $out[$file] = [
            'zh' => trim((string)($row['zh'] ?? '')),
            'en' => trim((string)($row['en'] ?? '')),
        ];
    }

    return $out;
}

function baseSlug(string $slug, string $locale): string
{
    if ((str_starts_with($locale, 'en') || str_ends_with($slug, '-en')) && str_ends_with($slug, '-en')) {
        return substr($slug, 0, -3);
    }

    return $slug;
}

function fileName(int $n): string
{
    return sprintf('product-%02d.jpg', $n);
}

function stripProductFigures(string $html): string
{
    $html = preg_replace(
        '#<figure\b[^>]*class=("|\')[^"\']*blog-illust[^"\']*\1[^>]*>.*?</figure>#is',
        '',
        $html
    ) ?? $html;
    // also strip any leftover product img tags not in figure
    $html = preg_replace(
        '#<img\b[^>]*product-\d{2}\.jpg[^>]*>#i',
        '',
        $html
    ) ?? $html;

    return trim(preg_replace("#\n{3,}#", "\n\n", $html) ?? $html);
}

function figureHtml(string $file, string $locale, array $captions, string $alt): string
{
    $src = PHOTO_BASE . '/' . $file;
    $cap = $captions[$file][str_starts_with($locale, 'en') ? 'en' : 'zh'] ?? $file;

    return '<figure class="blog-illust">'
        . '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="'
        . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '" loading="lazy" />'
        . '<figcaption>' . htmlspecialchars($cap, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</figcaption>'
        . '</figure>';
}

function injectBodyFigure(string $html, string $figure): string
{
    if ($figure === '') {
        return $html;
    }
    if (preg_match('#</h2>#i', $html)) {
        return preg_replace('#</h2>#i', '</h2>' . $figure, $html, 1) ?? ($figure . $html);
    }

    return $figure . $html;
}

/**
 * @param list<string> $baseSlugs
 * @param list<int> $pool
 * @return array<string, array{cover:int, body:?int}>
 */
function allocateStrict(array $baseSlugs, array $pool, int $maxUsage = MAX_USAGE): array
{
    $usage = [];
    foreach ($pool as $n) {
        $usage[$n] = 0;
    }
    $assign = [];

    $pick = static function (array $usage, array $pool, int $maxUsage, ?int $exclude = null): ?int {
        $candidates = [];
        foreach ($pool as $n) {
            if ($exclude !== null && $n === $exclude) {
                continue;
            }
            if ($usage[$n] >= $maxUsage) {
                continue;
            }
            $candidates[] = $n;
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static function (int $a, int $b) use ($usage): int {
            return ($usage[$a] <=> $usage[$b]) ?: ($a <=> $b);
        });

        return $candidates[0];
    };

    // Pass 1: unique-as-possible covers
    foreach ($baseSlugs as $slug) {
        $cover = $pick($usage, $pool, $maxUsage, null);
        if ($cover === null) {
            throw new RuntimeException('No cover capacity left for ' . $slug . ' (increase pool or lower article count)');
        }
        ++$usage[$cover];
        $assign[$slug] = ['cover' => $cover, 'body' => null];
    }

    // Pass 2: optional body only while capacity remains
    foreach ($baseSlugs as $slug) {
        $body = $pick($usage, $pool, $maxUsage, $assign[$slug]['cover']);
        if ($body === null) {
            continue;
        }
        ++$usage[$body];
        $assign[$slug]['body'] = $body;
    }

    // Verify caps
    foreach ($usage as $n => $c) {
        if ($c > $maxUsage) {
            throw new RuntimeException('Usage cap broken for product-' . sprintf('%02d', $n) . " = {$c}");
        }
    }

    return $assign;
}

$captions = loadCaptions();
$pool = poolNumbers();
$postModel = ObjectManager::getInstance(Post::class);
$admin = ObjectManager::getInstance(BlogPostAdminService::class);

$rows = $postModel->clear()->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
    ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
    ->select()->fetchArray();
if (!is_array($rows)) {
    $rows = [];
}
$beforeCount = count($rows);

/** @var array<string, list<array<string,mixed>>> $byBase */
$byBase = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $slug = (string)($row[Post::schema_fields_SLUG] ?? '');
    $locale = (string)($row[Post::schema_fields_LOCALE] ?? 'zh_Hans_CN');
    if ($slug === '') {
        continue;
    }
    $base = baseSlug($slug, $locale);
    $byBase[$base][] = $row;
}

$baseSlugs = array_keys($byBase);
sort($baseSlugs);
$plan = allocateStrict($baseSlugs, $pool, MAX_USAGE);

$updated = 0;
$withBody = 0;
$usageCheck = [];

foreach ($plan as $base => $pair) {
    $coverFile = fileName($pair['cover']);
    $bodyFile = $pair['body'] !== null ? fileName($pair['body']) : null;
    $coverPath = PHOTO_BASE . '/' . $coverFile;
    $usageCheck[$coverFile] = ($usageCheck[$coverFile] ?? 0) + 1;
    if ($bodyFile !== null) {
        $usageCheck[$bodyFile] = ($usageCheck[$bodyFile] ?? 0) + 1;
        ++$withBody;
    }

    foreach ($byBase[$base] as $row) {
        $postId = (int)($row[Post::schema_fields_ID] ?? 0);
        $slug = (string)$row[Post::schema_fields_SLUG];
        $locale = (string)($row[Post::schema_fields_LOCALE] ?? 'zh_Hans_CN');
        $title = (string)($row[Post::schema_fields_TITLE] ?? $slug);
        $content = stripProductFigures((string)($row[Post::schema_fields_CONTENT] ?? ''));
        if ($bodyFile !== null) {
            $content = injectBodyFigure($content, figureHtml($bodyFile, $locale, $captions, $title));
        }

        // assert within uniqueness
        preg_match_all('#product-\d{2}\.jpg#', $coverPath . "\n" . $content, $m);
        $counts = array_count_values($m[0] ?? []);
        foreach ($counts as $f => $n) {
            if ($n > 1) {
                throw new RuntimeException("Within dup {$slug} {$f}x{$n}");
            }
        }

        $admin->save([
            'post_id' => $postId,
            'website_id' => WEBSITE_ID,
            'locale' => $locale,
            'slug' => $slug,
            'title' => $title,
            'excerpt' => (string)($row[Post::schema_fields_EXCERPT] ?? ''),
            'content' => $content,
            'cover_image' => $coverPath,
            'author' => (string)($row[Post::schema_fields_AUTHOR] ?? 'Amayun Editorial'),
            'keywords' => (string)($row[Post::schema_fields_KEYWORDS] ?? ''),
            'category_id' => (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0),
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => (string)($row[Post::schema_fields_PUBLISHED_AT] ?? date('Y-m-d H:i:s')),
        ]);
        ++$updated;
        $bodyNote = $bodyFile ?? '-';
        echo "+ #{$postId} {$slug} cover={$coverFile} body={$bodyNote}\n";
    }
}

$max = $usageCheck ? max($usageCheck) : 0;
$min = $usageCheck ? min($usageCheck) : 0;
echo "updated={$updated} bases=" . count($plan) . " with_body={$withBody} pool=" . count($pool)
    . " max_usage={$max} min_usage={$min} cap=" . MAX_USAGE . "\n";

$afterRows = $postModel->clear()->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
    ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
    ->select()->fetchArray();
$afterCount = is_array($afterRows) ? count($afterRows) : 0;
if ($afterCount < $beforeCount) {
    fwrite(STDERR, "POST_COUNT_DROPPED before={$beforeCount} after={$afterCount}\n");
    exit(1);
}

foreach ($usageCheck as $f => $n) {
    if ($n > MAX_USAGE) {
        fwrite(STDERR, "CAP_FAIL {$f}={$n}\n");
        exit(1);
    }
}
