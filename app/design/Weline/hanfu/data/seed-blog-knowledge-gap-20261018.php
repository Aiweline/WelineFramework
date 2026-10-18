<?php

declare(strict_types=1);

/**
 * Idempotent seed: knowledge-gap MUST 3 articles (zh + en).
 *
 * Usage: php app/design/Weline/hanfu/data/seed-blog-knowledge-gap-20261018.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const AUTHOR = 'Amayun Editorial';

$articles = require __DIR__ . '/seed-blog-knowledge-gap-20261018-content.php';
if (!is_array($articles) || count($articles) !== 3) {
    throw new RuntimeException('Expected exactly 3 knowledge-gap articles');
}

$categoryAdmin = ObjectManager::getInstance(BlogCategoryAdminService::class);
$postAdmin = ObjectManager::getInstance(BlogPostAdminService::class);
$postModel = ObjectManager::getInstance(Post::class);

/**
 * @return array<string,int> slug/code => category_id
 */
function categoryIdMap(BlogCategoryAdminService $admin): array
{
    $map = [];
    foreach ($admin->tree(WEBSITE_ID, 'zh_Hans_CN') as $node) {
        $slug = trim((string)($node['code'] ?? $node['slug'] ?? ''));
        $id = (int)($node['category_id'] ?? 0);
        if ($slug !== '' && $id > 0) {
            $map[$slug] = $id;
        }
    }

    return $map;
}

$catMap = categoryIdMap($categoryAdmin);
$created = 0;
$updated = 0;
$now = date('Y-m-d H:i:s');

foreach ($articles as $article) {
    $slug = (string)$article['slug'];
    $cover = (string)$article['cover'];
    $catCode = (string)$article['category_code'];
    $categoryId = (int)($catMap[$catCode] ?? 0);
    if ($categoryId <= 0) {
        throw new RuntimeException('Missing category ' . $catCode . ' for ' . $slug);
    }

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
            echo "~ update {$pack['slug']} cat={$catCode}\n";
            ++$updated;
        } else {
            echo "+ create {$pack['slug']} cat={$catCode}\n";
            ++$created;
        }
    }
}

echo "done created={$created} updated={$updated}\n";
