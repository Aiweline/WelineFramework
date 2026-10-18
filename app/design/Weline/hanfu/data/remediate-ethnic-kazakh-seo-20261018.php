<?php

declare(strict_types=1);

/**
 * OPC A4 (theme-only): ethnic-kazakh SERP disambiguation — NOT noindex.
 * Updates title/excerpt/content lead for ethnic-kazakh* posts (all locales).
 *
 * Usage (local or production app root):
 *   php app/design/Weline/hanfu/data/remediate-ethnic-kazakh-seo-20261018.php
 *   php app/design/Weline/hanfu/data/remediate-ethnic-kazakh-seo-20261018.php --apply
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
$marker = 'data-testid="ethnic-not-hanfu-notice"';
$prefixZh = '【文化介绍·非汉服商品】';
$prefixEn = '[Cultural overview · Not a Hanfu product] ';
$excerptLeadZh = '本文为民族文化服饰介绍，不是汉服商品页。';
$excerptLeadEn = 'Cultural dress overview — not a Hanfu product page. ';

$noticeHtml = '<aside class="hanfu-ethnic-disambiguation" data-testid="ethnic-not-hanfu-notice" role="note">'
    . '<p><strong>说明：本文为民族文化服饰介绍，不是汉服商品页，亦非可购买的汉服 SKU。</strong></p>'
    . '<p lang="en"><strong>Note: This is a cultural dress overview — not a Hanfu product page and not a shoppable Hanfu SKU.</strong></p>'
    . '<p><a href="/products/">浏览汉服商品 / Shop Hanfu</a></p>'
    . '</aside>';

/** @var Post $postModel */
$postModel = ObjectManager::getInstance(Post::class);
/** @var BlogPostAdminService $admin */
$admin = ObjectManager::getInstance(BlogPostAdminService::class);

$rows = $postModel->clear()->reset()
    ->where(Post::schema_fields_SLUG, '%ethnic-kazakh%', 'like')
    ->fetchArray();
if (!is_array($rows)) {
    $rows = [];
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . ' ethnic-kazakh posts=' . count($rows) . PHP_EOL;

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $postId = (int)($row[Post::schema_fields_ID] ?? $row['post_id'] ?? 0);
    $slug = (string)($row[Post::schema_fields_SLUG] ?? $row['slug'] ?? '');
    $locale = (string)($row[Post::schema_fields_LOCALE] ?? $row['locale'] ?? '');
    $title = trim((string)($row[Post::schema_fields_TITLE] ?? $row['title'] ?? ''));
    $excerpt = trim((string)($row[Post::schema_fields_EXCERPT] ?? $row['excerpt'] ?? ''));
    $content = (string)($row[Post::schema_fields_CONTENT] ?? $row['content'] ?? '');
    $isZh = str_starts_with(strtolower(str_replace('_', '-', $locale)), 'zh') || $locale === '';

    $newTitle = $title;
    if ($isZh) {
        if ($prefixZh !== '' && !str_contains($title, '非汉服商品')) {
            $newTitle = $prefixZh . $title;
        }
    } elseif (!str_contains($title, 'Not a Hanfu product') && !str_contains($title, 'non-Hanfu')) {
        $newTitle = $prefixEn . $title;
    }

    $newExcerpt = $excerpt;
    if ($isZh) {
        if (!str_contains($excerpt, '不是汉服商品')) {
            $newExcerpt = $excerptLeadZh . ($excerpt !== '' ? ' ' . $excerpt : '');
        }
    } elseif (!str_contains($excerpt, 'not a Hanfu product')) {
        $newExcerpt = $excerptLeadEn . ($excerpt !== '' ? ' ' . $excerpt : '');
    }

    $newContent = $content;
    if ($content !== '' && !str_contains($content, $marker)) {
        $newContent = $noticeHtml . "\n" . $content;
    }

    echo "- #{$postId} [{$locale}] {$slug}\n";
    echo "  title: {$title} → {$newTitle}\n";
    if (!$apply) {
        continue;
    }

    $payload = $row;
    $payload['post_id'] = $postId;
    $payload['website_id'] = (int)($row[Post::schema_fields_WEBSITE_ID] ?? $row['website_id'] ?? 0);
    $payload['slug'] = $slug;
    $payload['locale'] = $locale;
    $payload['title'] = $newTitle;
    $payload['excerpt'] = $newExcerpt;
    $payload['content'] = $newContent;
    $payload['status'] = (string)($row[Post::schema_fields_STATUS] ?? $row['status'] ?? Post::STATUS_PUBLISHED);
    $admin->save($payload);
    echo "  saved\n";
}

echo "done\n";
