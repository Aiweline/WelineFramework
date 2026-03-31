<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Review\Model\Review;
use WeShop\Review\Service\ReviewService;

/**
 * 商品评价服务单元测试
 */
class ReviewServiceTest extends TestCase
{
    /**
     * 测试：服务类存在
     */
    public function testServiceClassExists(): void
    {
        $this->assertTrue(class_exists(ReviewService::class));
    }

    /**
     * 测试：createReview 方法存在
     */
    public function testCreateReviewMethodExists(): void
    {
        $this->assertTrue(method_exists(ReviewService::class, 'createReview'));
    }

    /**
     * 测试：createReview 方法签名正确
     */
    public function testCreateReviewMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(ReviewService::class, 'createReview');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('reviewData', $params[0]->getName());
        $this->assertEquals('array', $params[0]->getType()->getName());
        $this->assertEquals(Review::class, $reflection->getReturnType()->getName());
    }

    /**
     * 测试：getProductReviews 方法存在
     */
    public function testGetProductReviewsMethodExists(): void
    {
        $this->assertTrue(method_exists(ReviewService::class, 'getProductReviews'));
    }

    /**
     * 测试：getProductReviews 方法签名正确
     */
    public function testGetProductReviewsMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(ReviewService::class, 'getProductReviews');
        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('productId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());
        $this->assertEquals('page', $params[1]->getName());
        $this->assertEquals('int', $params[1]->getType()->getName());
        $this->assertEquals('pageSize', $params[2]->getName());
        $this->assertEquals('int', $params[2]->getType()->getName());
        $this->assertEquals('array', $reflection->getReturnType()->getName());
    }

    /**
     * 测试：approveReview 方法存在
     */
    public function testApproveReviewMethodExists(): void
    {
        $this->assertTrue(method_exists(ReviewService::class, 'approveReview'));
    }

    /**
     * 测试：approveReview 方法签名正确
     */
    public function testApproveReviewMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(ReviewService::class, 'approveReview');
        $params = $reflection->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('reviewId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());
        $this->assertEquals('status', $params[1]->getName());
        $this->assertEquals('string', $params[1]->getType()->getName());
        $this->assertEquals(Review::class, $reflection->getReturnType()->getName());
    }

    /**
     * 测试：getAverageRating 方法存在
     */
    public function testGetAverageRatingMethodExists(): void
    {
        $this->assertTrue(method_exists(ReviewService::class, 'getAverageRating'));
    }

    /**
     * 测试：getAverageRating 方法签名正确
     */
    public function testGetAverageRatingMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(ReviewService::class, 'getAverageRating');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('productId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());
        $this->assertEquals('float', $reflection->getReturnType()->getName());
    }

    /**
     * 测试：Review 模型状态常量正确
     */
    public function testReviewStatusConstants(): void
    {
        $this->assertEquals('pending', Review::STATUS_PENDING);
        $this->assertEquals('approved', Review::STATUS_APPROVED);
        $this->assertEquals('rejected', Review::STATUS_REJECTED);
    }

    /**
     * 测试：Review 模型字段常量正确
     */
    public function testReviewFieldConstants(): void
    {
        $this->assertEquals('review_id', Review::schema_fields_ID);
        $this->assertEquals('product_id', Review::schema_fields_PRODUCT_ID);
        $this->assertEquals('customer_id', Review::schema_fields_CUSTOMER_ID);
        $this->assertEquals('rating', Review::schema_fields_RATING);
        $this->assertEquals('title', Review::schema_fields_TITLE);
        $this->assertEquals('content', Review::schema_fields_CONTENT);
        $this->assertEquals('status', Review::schema_fields_STATUS);
        $this->assertEquals('created_at', Review::schema_fields_CREATED_AT);
        $this->assertEquals('updated_at', Review::schema_fields_UPDATED_AT);
    }

    /**
     * 测试：Review 模型 schema 定义正确
     */
    public function testReviewModelSchema(): void
    {
        $this->assertEquals('weshop_review', Review::schema_table);
        $this->assertEquals('review_id', Review::schema_primary_key);
    }

    /**
     * 测试：Review 模型主键字段存在
     */
    public function testReviewModelPrimaryKeys(): void
    {
        // schema_fields_ID 应该等于 'review_id'
        $this->assertEquals('review_id', Review::schema_fields_ID);
        // schema_primary_key 应该等于 'review_id'
        $this->assertEquals('review_id', Review::schema_primary_key);
    }
}
