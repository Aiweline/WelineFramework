<?php

declare(strict_types=1);

/**
 * Repair photo capacity after china-ethnic seed:
 * - Ethnic / china-56 hub posts → category SVG cover, strip product figures
 * - Remaining Hanfu posts → strict redistribute (max 2 uses / product file)
 *
 * Usage: php app/code/Weline/Blog/data/repair-china-ethnic-photo-capacity.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const PHOTO_BASE = '/media/blog/hanfu/articles/photos';
const PHOTO_DIR = BP . 'pub/media/blog/hanfu/articles/photos';
const CAPTIONS_FILE = __DIR__ . '/hanfu-photo-captions.json';
const ICON_BASE = '/media/blog/hanfu/categories/icons';
const MAX_USAGE = 2;

function isEthnicBase(string $base): bool
{
    return str_starts_with($base, 'ethnic-')
        || $base === 'china-56-ethnic-dress-hub';
}

function baseSlug(string $slug, string $locale): string
{
    if ((str_starts_with($locale, 'en') || str_ends_with($slug, '-en')) && str_ends_with($slug, '-en')) {
        return substr($slug, 0, -3);
    }

    return $slug;
}

function stripProductFigures(string $html): string
{
    $html = preg_replace(
        '#<figure\b[^>]*class=("|\')[^"\']*blog-illust[^"\']*\1[^>]*>.*?</figure>#is',
        '',
        $html
    ) ?? $html;
    $html = preg_replace('#<img\b[^>]*product-\d{2}\.jpg[^>]*>#i', '', $html) ?? $html;

    return trim(preg_replace("#\n{3,}#", "\n\n", $html) ?? $html);
}

function figureHtml(string $src, string $alt, string $caption): string
{
    return '<figure class="blog-illust">'
        . '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="'
        . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '" loading="lazy" />'
        . '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</figcaption>'
        . '</figure>';
}

function injectAfterFirstH2(string $html, string $figure): string
{
    if ($figure === '' || !preg_match('#</h2>#i', $html)) {
        return $figure === '' ? $html : ($figure . $html);
    }

    return preg_replace('#</h2>#i', '</h2>' . $figure, $html, 1) ?? ($figure . $html);
}

/** @return array<string,int> */
function categoryCodeToId(): array
{
    $admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
    $map = [];
    foreach ($admin->tree(WEBSITE_ID, 'zh_Hans_CN') as $node) {
        $code = trim((string)($node['code'] ?? ''));
        $id = (int)($node['category_id'] ?? 0);
        if ($code !== '' && $id > 0) {
            $map[$code] = $id;
        }
    }

    return $map;
}

/** @return array<int,string> */
function categoryIdToCode(array $codeToId): array
{
    $out = [];
    foreach ($codeToId as $code => $id) {
        $out[$id] = $code;
    }

    return $out;
}

function coverForEthnic(string $base, int $categoryId, array $idToCode): string
{
    if ($base === 'china-56-ethnic-dress-hub') {
        return ICON_BASE . '/china-ethnic-dress.svg';
    }
    if (preg_match('#^ethnic-([a-z]+)-(dress-overview|occasion-craft)$#', $base, $m)) {
        return ICON_BASE . '/ethnic-cn-' . $m[1] . '.svg';
    }
    $code = $idToCode[$categoryId] ?? '';
    if ($code !== '') {
        return ICON_BASE . '/' . $code . '.svg';
    }

    return ICON_BASE . '/china-ethnic-dress.svg';
}

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
        usort($candidates, static fn (int $a, int $b): int => ($usage[$a] <=> $usage[$b]) ?: ($a <=> $b));

        return $candidates[0];
    };

    foreach ($baseSlugs as $slug) {
        $cover = $pick($usage, $pool, $maxUsage, null);
        if ($cover === null) {
            throw new RuntimeException('No cover capacity left for ' . $slug);
        }
        ++$usage[$cover];
        $assign[$slug] = ['cover' => $cover, 'body' => null];
    }
    foreach ($baseSlugs as $slug) {
        $body = $pick($usage, $pool, $maxUsage, $assign[$slug]['cover']);
        if ($body === null) {
            continue;
        }
        ++$usage[$body];
        $assign[$slug]['body'] = $body;
    }

    return $assign;
}

$admin = ObjectManager::getInstance(BlogPostAdminService::class);
$postModel = ObjectManager::getInstance(Post::class);
$idToCode = categoryIdToCode(categoryCodeToId());
$captions = loadCaptions();
$pool = poolNumbers();

$rows = $postModel->clear()->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
    ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
    ->select()->fetchArray();
if (!is_array($rows)) {
    $rows = [];
}

/** @var array<string, list<array<string,mixed>>> $ethnicByBase */
$ethnicByBase = [];
/** @var array<string, list<array<string,mixed>>> $hanfuByBase */
$hanfuByBase = [];

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
    if (isEthnicBase($base)) {
        $ethnicByBase[$base][] = $row;
    } else {
        $hanfuByBase[$base][] = $row;
    }
}

$ethnicUpdated = 0;
foreach ($ethnicByBase as $base => $list) {
    foreach ($list as $row) {
        $postId = (int)($row[Post::schema_fields_ID] ?? 0);
        $slug = (string)$row[Post::schema_fields_SLUG];
        $locale = (string)($row[Post::schema_fields_LOCALE] ?? 'zh_Hans_CN');
        $title = (string)($row[Post::schema_fields_TITLE] ?? $slug);
        $categoryId = (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0);
        $cover = coverForEthnic($base, $categoryId, $idToCode);
        $content = stripProductFigures((string)($row[Post::schema_fields_CONTENT] ?? ''));
        $cap = str_starts_with($locale, 'en')
            ? 'Category icon — cultural navigation cue, not a garment SKU photo.'
            : '分类图标：用于民族服饰导航与主题识别，非成衣 SKU 实拍。';
        $content = injectAfterFirstH2($content, figureHtml($cover, $title, $cap));
        $admin->save([
            'post_id' => $postId,
            'website_id' => WEBSITE_ID,
            'locale' => $locale,
            'slug' => $slug,
            'title' => $title,
            'excerpt' => (string)($row[Post::schema_fields_EXCERPT] ?? ''),
            'content' => $content,
            'cover_image' => $cover,
            'author' => (string)($row[Post::schema_fields_AUTHOR] ?? 'Amayun Editorial'),
            'keywords' => (string)($row[Post::schema_fields_KEYWORDS] ?? ''),
            'category_id' => $categoryId,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => (string)($row[Post::schema_fields_PUBLISHED_AT] ?? date('Y-m-d H:i:s')),
        ]);
        echo "+ ethnic #{$postId} {$slug} cover={$cover}\n";
        ++$ethnicUpdated;
    }
}

$hanfuBases = array_keys($hanfuByBase);
sort($hanfuBases);
if (count($hanfuBases) > count($pool) * MAX_USAGE) {
    throw new RuntimeException(
        'Hanfu bases ' . count($hanfuBases) . ' exceed cover capacity ' . (count($pool) * MAX_USAGE)
    );
}
$plan = allocateStrict($hanfuBases, $pool, MAX_USAGE);
$hanfuUpdated = 0;
$withBody = 0;
$usage = [];

foreach ($plan as $base => $pair) {
    $coverFile = sprintf('product-%02d.jpg', $pair['cover']);
    $bodyFile = $pair['body'] !== null ? sprintf('product-%02d.jpg', $pair['body']) : null;
    $coverPath = PHOTO_BASE . '/' . $coverFile;
    $usage[$coverFile] = ($usage[$coverFile] ?? 0) + 1;
    if ($bodyFile !== null) {
        $usage[$bodyFile] = ($usage[$bodyFile] ?? 0) + 1;
        ++$withBody;
    }
    foreach ($hanfuByBase[$base] as $row) {
        $postId = (int)($row[Post::schema_fields_ID] ?? 0);
        $slug = (string)$row[Post::schema_fields_SLUG];
        $locale = (string)($row[Post::schema_fields_LOCALE] ?? 'zh_Hans_CN');
        $title = (string)($row[Post::schema_fields_TITLE] ?? $slug);
        $content = stripProductFigures((string)($row[Post::schema_fields_CONTENT] ?? ''));
        if ($bodyFile !== null) {
            $cap = $captions[$bodyFile][str_starts_with($locale, 'en') ? 'en' : 'zh'] ?? $bodyFile;
            $content = injectAfterFirstH2($content, figureHtml(PHOTO_BASE . '/' . $bodyFile, $title, $cap));
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
        echo "+ hanfu #{$postId} {$slug} cover={$coverFile} body=" . ($bodyFile ?? '-') . "\n";
        ++$hanfuUpdated;
    }
}

$max = $usage ? max($usage) : 0;
echo "ethnic_updated={$ethnicUpdated} hanfu_updated={$hanfuUpdated} hanfu_bases=" . count($plan)
    . " with_body={$withBody} max_cross={$max}\n";
if ($max > MAX_USAGE) {
    throw new RuntimeException('max_cross exceeded');
}
