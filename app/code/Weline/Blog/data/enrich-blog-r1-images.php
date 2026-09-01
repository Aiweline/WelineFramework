<?php

declare(strict_types=1);

/**
 * Enrich all website-0 published blog posts with real photo covers + inline figures.
 *
 * Usage: php app/code/Weline/Blog/data/enrich-blog-r1-images.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

// Hard-gated: this script used crc32/stock pools and reintroduced duplicate / non-product images.
// Use rewrite-blog-r1-substance.php + BLOG_IMAGE_NO_DUPLICATE.md instead.
fwrite(STDERR, "REFUSED: enrich-blog-r1-images.php is retired (duplicate/stock image risk).\n");
fwrite(STDERR, "Use: php app/code/Weline/Blog/data/rewrite-blog-r1-substance.php\n");
fwrite(STDERR, "Rules: app/code/Weline/Blog/data/BLOG_IMAGE_NO_DUPLICATE.md\n");
exit(2);

const WEBSITE_ID = 0;
const PHOTO_BASE = '/media/blog/hanfu/articles/photos';
const PHOTO_DIR = BP . 'pub/media/blog/hanfu/articles/photos';

/**
 * @return list<string> relative filenames that are valid images
 */
function photoFiles(): array
{
    $out = [];
    foreach (glob(PHOTO_DIR . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $path) {
        if (@getimagesize($path)) {
            $out[] = basename($path);
        }
    }
    sort($out);

    return $out;
}

/**
 * @param list<string> $all
 * @param list<string> $preferredPrefixes
 * @return list<string>
 */
function pickPool(array $all, array $preferredPrefixes, int $need = 8): array
{
    $preferred = [];
    foreach ($all as $name) {
        foreach ($preferredPrefixes as $prefix) {
            if (str_starts_with($name, $prefix) || str_contains($name, $prefix)) {
                $preferred[] = $name;
                break;
            }
        }
    }
    $preferred = array_values(array_unique($preferred));
    if (count($preferred) >= $need) {
        return array_slice($preferred, 0, $need);
    }
    foreach ($all as $name) {
        if (!in_array($name, $preferred, true)) {
            $preferred[] = $name;
        }
        if (count($preferred) >= $need) {
            break;
        }
    }

    return $preferred;
}

function figure(string $src, string $alt, string $caption): string
{
    $src = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $alt = htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $caption = htmlspecialchars($caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<figure class="blog-illust" style="margin:1.25rem 0;text-align:center">'
        . '<img src="' . $src . '" alt="' . $alt . '" loading="lazy" style="max-width:100%;height:auto;border-radius:8px" />'
        . '<figcaption style="margin-top:.5rem;color:#565959;font-size:.9rem">' . $caption . '</figcaption>'
        . '</figure>';
}

function stripOldIllust(string $html): string
{
    $html = preg_replace('#<figure class="blog-illust"[\s\S]*?</figure>#i', '', $html) ?? $html;
    $html = preg_replace('#<!--blog-illust-start-->[\s\S]*?<!--blog-illust-end-->#i', '', $html) ?? $html;

    return trim($html);
}

/**
 * Insert figures after 1st and 2nd h2 when possible.
 *
 * @param list<array{file:string,alt:string,caption:string}> $shots
 */
function injectFigures(string $html, array $shots): string
{
    $html = stripOldIllust($html);
    if ($shots === []) {
        return $html;
    }

    $blocks = [];
    foreach ($shots as $shot) {
        $blocks[] = figure(PHOTO_BASE . '/' . $shot['file'], $shot['alt'], $shot['caption']);
    }

    $parts = preg_split('/(?=<h2\b)/i', $html) ?: [$html];
    if (count($parts) <= 1) {
        return '<!--blog-illust-start-->' . implode('', $blocks) . '<!--blog-illust-end-->' . $html;
    }

    // parts[0] may be empty or preface; inject after first and second h2 sections
    $out = $parts[0];
    $injectIndexes = [1, 2];
    $bi = 0;
    for ($i = 1, $n = count($parts); $i < $n; $i++) {
        $out .= $parts[$i];
        if (in_array($i, $injectIndexes, true) && isset($blocks[$bi])) {
            $out .= $blocks[$bi];
            ++$bi;
        }
    }
    // leftover blocks append before end
    while (isset($blocks[$bi])) {
        $out .= $blocks[$bi];
        ++$bi;
    }

    return $out;
}

/**
 * @param list<string> $pool
 * @return array{cover:string,shots:list<array{file:string,alt:string,caption:string}>}
 */
function planForPost(array $row, array $allPhotos): array
{
    $slug = (string)($row[Post::schema_fields_SLUG] ?? '');
    $locale = (string)($row[Post::schema_fields_LOCALE] ?? 'zh_Hans_CN');
    $title = (string)($row[Post::schema_fields_TITLE] ?? '');
    $isEn = str_starts_with($locale, 'en');

    $theme = 'product';
    if (preg_match('/wedding|bridal|east-meets|festival|occasions|lantern/i', $slug . $title)) {
        $theme = 'festival';
    } elseif (preg_match('/fabric|manufactur|partner|workshop|sewing|factory|craft/i', $slug . $title)) {
        $theme = 'workshop';
    } elseif (preg_match('/dynasty|guide|style|ruqun|mamian|what-is|encycl|hanfu-/i', $slug)) {
        $theme = 'culture';
    } elseif (preg_match('/compare|amazon|tiktok|aliexpress|shein|temu|yesstyle|etsy|ebay|shopee|lazada|newmoon|nuwa|intervene|doresu|soulsfen|dawn|why-amayun|marketplace|vertical/i', $slug)) {
        $theme = 'market';
    }

    $prefixes = match ($theme) {
        'festival' => ['fest-', 'bridal-', 'pexels-', 'product-', 'unsplash-', 'u-'],
        'workshop' => ['workshop-', 'sewing-', 'silk-', 'product-', 'trad-'],
        'culture' => ['product-', 'trad-', 'unsplash-', 'u-', 'pexels-', 'fest-'],
        'market' => ['market-', 'pexels-', 'u-', 'unsplash-', 'product-', 'fest-'],
        default => ['product-', 'fest-', 'pexels-', 'unsplash-'],
    };

    $pool = pickPool($allPhotos, $prefixes, 10);
    // deterministic pick per slug
    $hash = crc32($slug);
    $cover = $pool[$hash % max(1, count($pool))];
    $i2 = ($hash >> 3) % max(1, count($pool));
    $i3 = ($hash >> 7) % max(1, count($pool));
    $files = array_values(array_unique([$cover, $pool[$i2], $pool[$i3]]));
    while (count($files) < 3 && count($pool) > count($files)) {
        foreach ($pool as $f) {
            if (!in_array($f, $files, true)) {
                $files[] = $f;
            }
            if (count($files) >= 3) {
                break;
            }
        }
        break;
    }

    $cap = static function (string $kind) use ($isEn, $theme): string {
        if ($isEn) {
            return match ($kind) {
                'cover' => 'Editorial photo · Hanfu / Chinese cultural atmosphere',
                'a' => $theme === 'workshop'
                    ? 'Craft & textile atmosphere from production-related visuals'
                    : ($theme === 'festival' ? 'Festival / ceremonial atmosphere reference' : 'Real garment & cultural scene reference'),
                default => 'Supporting visual for readers — style, fabric or occasion context',
            };
        }

        return match ($kind) {
            'cover' => '配图 · 汉服成衣实拍 / 国风活动氛围参考',
            'a' => $theme === 'workshop'
                ? '工艺与面料氛围参考（制造/纺织场景）'
                : ($theme === 'festival' ? '节令/婚礼等活动氛围参考' : '汉服成衣与文化场景实拍参考'),
            default => '正文配图：形制、面料或场合视觉参考，避免空文',
        };
    };

    $shots = [];
    if (isset($files[1])) {
        $shots[] = ['file' => $files[1], 'alt' => $title, 'caption' => $cap('a')];
    }
    if (isset($files[2])) {
        $shots[] = ['file' => $files[2], 'alt' => $title . ' detail', 'caption' => $cap('b')];
    }

    return ['cover' => $cover, 'shots' => $shots];
}

$all = photoFiles();
if (count($all) < 6) {
    fwrite(STDERR, "Need more photos in " . PHOTO_DIR . " (have " . count($all) . ")\n");
    exit(1);
}

$postModel = ObjectManager::getInstance(Post::class);
$admin = ObjectManager::getInstance(BlogPostAdminService::class);
$rows = $postModel->clear()->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)->select()->fetchArray();
$updated = 0;

foreach (is_array($rows) ? $rows : [] as $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = (int)($row[Post::schema_fields_ID] ?? 0);
    if ($id <= 0) {
        continue;
    }
    $plan = planForPost($row, $all);
    $content = (string)($row[Post::schema_fields_CONTENT] ?? '');
    $newContent = injectFigures($content, $plan['shots']);
    $coverPath = PHOTO_BASE . '/' . $plan['cover'];

    $admin->save([
        'post_id' => $id,
        'website_id' => WEBSITE_ID,
        'locale' => (string)($row[Post::schema_fields_LOCALE] ?? 'zh_Hans_CN'),
        'slug' => (string)($row[Post::schema_fields_SLUG] ?? ''),
        'title' => (string)($row[Post::schema_fields_TITLE] ?? ''),
        'excerpt' => (string)($row[Post::schema_fields_EXCERPT] ?? ''),
        'content' => $newContent,
        'cover_image' => $coverPath,
        'author' => (string)($row[Post::schema_fields_AUTHOR] ?? 'Amayun Editorial'),
        'keywords' => (string)($row[Post::schema_fields_KEYWORDS] ?? ''),
        'category_id' => (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0),
        'status' => (string)($row[Post::schema_fields_STATUS] ?? Post::STATUS_PUBLISHED),
        'published_at' => (string)($row[Post::schema_fields_PUBLISHED_AT] ?? date('Y-m-d H:i:s')),
    ]);

    echo "+ #{$id} cover={$plan['cover']} shots=" . count($plan['shots']) . ' ' . ($row[Post::schema_fields_SLUG] ?? '') . PHP_EOL;
    ++$updated;
}

echo "updated={$updated} photos_available=" . count($all) . PHP_EOL;
