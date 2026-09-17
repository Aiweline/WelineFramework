<?php

declare(strict_types=1);

/**
 * Idempotent seed: Textile Heritage professional articles (6 × zh/en).
 *
 * Usage: php app/code/Weline/Blog/data/seed-textile-heritage-articles.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const CATEGORY_CODE = 'textile-heritage';
const AUTHOR = 'Amayun Editorial';

$articles = require __DIR__ . '/textile-heritage-articles-content.php';
if (!is_array($articles) || count($articles) !== 6) {
    throw new RuntimeException('Expected 6 textile heritage articles');
}

$categoryAdmin = ObjectManager::getInstance(BlogCategoryAdminService::class);
$postAdmin = ObjectManager::getInstance(BlogPostAdminService::class);
$postModel = ObjectManager::getInstance(Post::class);

function findCategoryIdByCode(BlogCategoryAdminService $admin, string $code): int
{
    foreach ($admin->tree(WEBSITE_ID, 'zh_Hans_CN') as $node) {
        $slug = trim((string)($node['code'] ?? ''));
        $id = (int)($node['category_id'] ?? 0);
        if ($slug === $code && $id > 0) {
            return $id;
        }
    }

    return 0;
}

function slugExists(Post $postModel, string $slug): bool
{
    $row = $postModel->clearData()->reset()
        ->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
        ->where(Post::schema_fields_SLUG, $slug)
        ->find()
        ->fetchArray();

    return is_array($row) && (int)($row[Post::schema_fields_ID] ?? 0) > 0;
}

$categoryId = findCategoryIdByCode($categoryAdmin, CATEGORY_CODE);
if ($categoryId <= 0) {
    $saved = $categoryAdmin->save(
        WEBSITE_ID,
        0,
        '织艺谱系',
        CATEGORY_CODE,
        'zh_Hans_CN',
        40,
        null,
        null,
        '云锦、宋锦、蜀锦、苏绣、妆花、花罗等传统织绣技艺专业导读。',
        'Textile Heritage：锦、绣与罗的工艺谱系与馆藏证据。',
        0,
    );
    $categoryId = (int)($saved['category_id'] ?? 0);
    $categoryAdmin->save(
        WEBSITE_ID,
        $categoryId,
        'Textile Heritage',
        CATEGORY_CODE,
        'en_US',
        40,
        null,
        null,
        'Professional guides to Yunjin, Songjin, Shujin, Suxiu, Zhuanghua and Hualuo.',
        'Brocade, embroidery and gauze in the Chinese textile heritage lineage.',
        0,
    );
    echo "+ category {$categoryId} " . CATEGORY_CODE . PHP_EOL;
} else {
    echo "= category {$categoryId} " . CATEGORY_CODE . PHP_EOL;
}

$created = 0;
$updated = 0;
$skipped = 0;
$now = date('Y-m-d H:i:s');

foreach ($articles as $article) {
    $slug = (string)$article['slug'];
    $cover = (string)$article['cover'];
    foreach ([
        'zh_Hans_CN' => [
            'slug' => $slug,
            'title' => (string)$article['zh']['title'],
            'excerpt' => (string)$article['zh']['excerpt'],
            'content' => (string)$article['zh']['content'],
            'keywords' => (string)($article['keywords_zh'] ?? ''),
        ],
        'en_US' => [
            'slug' => $slug . '-en',
            'title' => (string)$article['en']['title'],
            'excerpt' => (string)$article['en']['excerpt'],
            'content' => (string)$article['en']['content'],
            'keywords' => (string)($article['keywords_en'] ?? ''),
        ],
    ] as $locale => $pack) {
        $existing = $postModel->clearData()->reset()
            ->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
            ->where(Post::schema_fields_LOCALE, $locale)
            ->where(Post::schema_fields_SLUG, $pack['slug'])
            ->find()
            ->fetchArray();
        $postId = is_array($existing) ? (int)($existing[Post::schema_fields_ID] ?? 0) : 0;
        $payload = [
            'post_id' => $postId,
            'website_id' => WEBSITE_ID,
            'locale' => $locale,
            'slug' => $pack['slug'],
            'title' => $pack['title'],
            'excerpt' => $pack['excerpt'],
            'content' => $pack['content'],
            'cover_image' => $cover,
            'author' => AUTHOR,
            'author_url' => '',
            'author_bio' => '',
            'author_job_title' => '',
            'author_same_as' => '',
            'keywords' => $pack['keywords'],
            'category_id' => $categoryId,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => $postId > 0
                ? (string)($existing[Post::schema_fields_PUBLISHED_AT] ?? $now)
                : $now,
        ];
        $postAdmin->save($payload);
        if ($postId > 0) {
            echo "~ update {$pack['slug']}\n";
            ++$updated;
        } else {
            echo "+ create {$pack['slug']}\n";
            ++$created;
        }
    }
}

echo "done created={$created} updated={$updated} skipped={$skipped}\n";
