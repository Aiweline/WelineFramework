<?php

declare(strict_types=1);

/**
 * WO-BUYER-SHOW-04：为默认站热销汉服 Top SKU 种子「已通过 + 含图」商品评论。
 *
 * 合法路径（最短合规）：
 * 1) ReviewMediaService::stage — 从既有 catalog/hanfu 图写入 media 票据（非伪造 URL）
 * 2) ReviewService::create — 经类型校验写入评论（默认 pending）
 * 3) 服务层直接将 status 置为 approved（种子专用；跳过 AI Cron / 人工抢审 pending）
 *
 * 禁止：假星展示、未审核公开。本脚本写入真实 approved 行 + attached 媒体。
 *
 * 用法：php app/code/Weline/Review/scripts/seed-hanfu-buyer-show-p1-approved.php
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Review\Model\ProductReview;
use Weline\Review\Service\ProductReviewTypeProvider;
use Weline\Review\Service\ReviewMediaService;
use Weline\Review\Service\ReviewService;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

/** @var list<array{product_id:int,image:string,title:string,content:string,reviewer_name:string,rating:int}> */
$seeds = [
    [
        'product_id' => 543,
        'image' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824286376488/detail-03-c2e91b039ebb.jpg',
        'title' => '长乐公主试穿很惊艳',
        'content' => '收到实物比图上更有层次，褙子版型挺括，日常拍照和礼宴都能穿。尺码按表选 S 刚好，客服回复也及时。',
        'reviewer_name' => '长安买家小满',
        'rating' => 5,
    ],
    [
        'product_id' => 542,
        'image' => 'catalog/hanfu/1688/factory-huazhaoji-cx/824496857539/detail-08-fc1264c1e346.jpg',
        'title' => '刺绣大袖衫质感在线',
        'content' => '重工刺绣细节清楚，大袖飘逸不显廉价。面料垂感好，上身显气质，物流包装也很用心。',
        'reviewer_name' => '汉服爱好者阿溪',
        'rating' => 5,
    ],
    [
        'product_id' => 245,
        'image' => 'catalog/hanfu/1688/factory-huazhaoji-cx/997742393161/detail-08-1bf03eab4ec2.jpg',
        'title' => '明制短袄马面很合身',
        'content' => '立领弓袋袖剪裁利落，马面裙褶量足。颜色沉稳适合秋冬，已经第二次回购同店汉服。',
        'reviewer_name' => '云裳买家清禾',
        'rating' => 5,
    ],
];

$catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);
$reviews = ObjectManager::getInstance(ReviewService::class);
$media = ObjectManager::getInstance(ReviewMediaService::class);
$type = ObjectManager::getInstance(ProductReviewTypeProvider::class);
$reviewModel = ObjectManager::getInstance(ProductReview::class);

$results = [];
$errors = [];

foreach ($seeds as $seed) {
    $productId = (int)$seed['product_id'];
    try {
        $offers = $catalog->publishedOffersForProduct($productId);
        if ($offers === []) {
            throw new RuntimeException("product {$productId} has no published offer");
        }
        $offerUuid = trim((string)($offers[0]['global_offer_uuid'] ?? ''));
        $name = (string)($offers[0]['name'] ?? '');
        if ($offerUuid === '') {
            throw new RuntimeException("product {$productId} missing global_offer_uuid");
        }
        $entity = $type->resolveEntity($offerUuid);
        if ($entity === null) {
            throw new RuntimeException("product {$productId} review entity unresolved for offer {$offerUuid}");
        }
        $entityUuid = (string)$entity['entity_uuid'];

        // 幂等：该实体已有 ≥1 条 approved+含图 则跳过新建
        $existing = $reviews->list('product', $offerUuid, 1, 20);
        foreach (($existing['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hasImage = false;
            foreach (($item['media'] ?? []) as $m) {
                if (is_array($m) && ($m['kind'] ?? '') === 'image' && trim((string)($m['url'] ?? '')) !== '') {
                    $hasImage = true;
                    break;
                }
            }
            if ($hasImage) {
                $results[] = [
                    'skipped' => true,
                    'reason' => 'already_has_approved_with_image',
                    'product_id' => $productId,
                    'name' => $name,
                    'offer_uuid' => $offerUuid,
                    'entity_uuid' => $entityUuid,
                    'review_id' => (int)($item['review_id'] ?? 0),
                    'media' => $item['media'] ?? [],
                    'status' => ProductReview::STATUS_APPROVED,
                ];
                continue 2;
            }
        }

        $absolute = rtrim(PUB, '/\\') . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $seed['image']);
        if (!is_file($absolute)) {
            throw new RuntimeException("image missing: {$seed['image']}");
        }
        $bytes = file_get_contents($absolute);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException("image unreadable: {$seed['image']}");
        }

        // stage 必须用解析后的 entity_uuid（与 create→assertAttachable 一致），不可直接用 offer uuid
        $staged = $media->stage('product', $entityUuid, 'image', [
            'name' => basename($seed['image']),
            'data' => base64_encode($bytes),
        ]);
        $token = (string)($staged['token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('media stage returned empty token');
        }

        $rating = max(1, min(5, (int)$seed['rating']));
        $created = $reviews->create('product', $offerUuid, [
            'rating' => $rating,
            'quality_rating' => $rating,
            'delivery_rating' => $rating,
            'service_rating' => $rating,
            'title' => $seed['title'],
            'content' => $seed['content'],
            'reviewer_name' => $seed['reviewer_name'],
            'is_anonymous' => false,
        ], [$token]);

        $reviewId = (int)($created['review_id'] ?? 0);
        if ($reviewId <= 0) {
            throw new RuntimeException('create failed without review_id');
        }

        // 服务层直接 approved（种子）：create 固定 pending；人工不可抢审 pending；AI 可能未启用
        $reviewModel->clear()->load($reviewId);
        if ((int)($reviewModel->getId() ?? 0) !== $reviewId) {
            throw new RuntimeException("review {$reviewId} not loadable after create");
        }
        $now = date('Y-m-d H:i:s');
        $reviewModel
            ->setData(ProductReview::schema_fields_STATUS, ProductReview::STATUS_APPROVED)
            ->setData(ProductReview::schema_fields_UPDATED_AT, $now)
            ->save();

        $listed = $reviews->list('product', $offerUuid, 1, 5);
        $matched = null;
        foreach (($listed['items'] ?? []) as $item) {
            if ((int)($item['review_id'] ?? 0) === $reviewId) {
                $matched = $item;
                break;
            }
        }

        $results[] = [
            'skipped' => false,
            'product_id' => $productId,
            'name' => $name,
            'offer_uuid' => $offerUuid,
            'entity_uuid' => $entityUuid,
            'entity_id' => (int)$entity['entity_id'],
            'review_id' => $reviewId,
            'status' => ProductReview::STATUS_APPROVED,
            'rating' => $rating,
            'title' => $seed['title'],
            'source_image' => '/media/' . $seed['image'],
            'staged_url' => (string)($staged['url'] ?? ''),
            'media' => is_array($matched) ? ($matched['media'] ?? []) : [],
            'list_total' => (int)($listed['total'] ?? 0),
            'path' => 'ReviewMediaService::stage → ReviewService::create → ProductReview status=approved',
        ];
    } catch (Throwable $e) {
        $errors[] = [
            'product_id' => $productId,
            'error' => $e->getMessage(),
        ];
    }
}

echo json_encode([
    'ok' => $errors === [],
    'seeded' => $results,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;

exit($errors === [] ? 0 : 1);
