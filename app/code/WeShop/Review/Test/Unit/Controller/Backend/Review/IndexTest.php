<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Controller\Backend\Review;

use PHPUnit\Framework\TestCase;
use WeShop\Review\Controller\Backend\Review\Index;
use WeShop\Review\Controller\Backend\Review\View;
use WeShop\Review\Controller\Backend\Review\Actions;
use WeShop\Review\Service\ReviewAdminPageDataService;
use WeShop\Review\Service\ReviewService;

/**
 * 后台评价控制器单元测试
 */
class IndexTest extends TestCase
{
    /**
     * 测试：Index控制器类存在
     */
    public function testIndexControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    /**
     * 测试：Index控制器有index方法
     */
    public function testIndexControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }

    /**
     * 测试：Index控制器index方法签名正确
     */
    public function testIndexControllerIndexMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(Index::class, 'index');
        $this->assertEquals('string', $reflection->getReturnType()->getName());
    }

    /**
     * 测试：View控制器类存在
     */
    public function testViewControllerClassExists(): void
    {
        $this->assertTrue(class_exists(View::class));
    }

    /**
     * 测试：View控制器有index方法
     */
    public function testViewControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }

    /**
     * 测试：View控制器index方法签名正确
     */
    public function testViewControllerIndexMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(View::class, 'index');
        $this->assertEquals('string', $reflection->getReturnType()->getName());
    }

    /**
     * 测试：Actions控制器类存在
     */
    public function testActionsControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Actions::class));
    }

    /**
     * 测试：Actions控制器有approve方法
     */
    public function testActionsControllerHasApproveMethod(): void
    {
        $reflection = new \ReflectionClass(Actions::class);
        $this->assertTrue($reflection->hasMethod('approve'));
    }

    /**
     * 测试：Actions控制器有reject方法
     */
    public function testActionsControllerHasRejectMethod(): void
    {
        $reflection = new \ReflectionClass(Actions::class);
        $this->assertTrue($reflection->hasMethod('reject'));
    }

    /**
     * 测试：Actions控制器有delete方法
     */
    public function testActionsControllerHasDeleteMethod(): void
    {
        $reflection = new \ReflectionClass(Actions::class);
        $this->assertTrue($reflection->hasMethod('delete'));
    }

    /**
     * 测试：Actions控制器approve方法签名正确
     */
    public function testActionsControllerApproveMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(Actions::class, 'approve');
        $returnType = $reflection->getReturnType();
        $this->assertEquals('Weline\Framework\Http\Response', $returnType->getName());
    }

    /**
     * 测试：Actions控制器reject方法签名正确
     */
    public function testActionsControllerRejectMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(Actions::class, 'reject');
        $returnType = $reflection->getReturnType();
        $this->assertEquals('Weline\Framework\Http\Response', $returnType->getName());
    }

    /**
     * 测试：Actions控制器delete方法签名正确
     */
    public function testActionsControllerDeleteMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(Actions::class, 'delete');
        $returnType = $reflection->getReturnType();
        $this->assertEquals('Weline\Framework\Http\Response', $returnType->getName());
    }

    /**
     * 测试：ReviewAdminPageDataService类存在
     */
    public function testReviewAdminPageDataServiceClassExists(): void
    {
        $this->assertTrue(class_exists(ReviewAdminPageDataService::class));
    }

    /**
     * 测试：ReviewAdminPageDataService有getListData方法
     */
    public function testReviewAdminPageDataServiceHasGetListDataMethod(): void
    {
        $this->assertTrue(method_exists(ReviewAdminPageDataService::class, 'getListData'));
    }

    /**
     * 测试：ReviewAdminPageDataService有getDetailData方法
     */
    public function testReviewAdminPageDataServiceHasGetDetailDataMethod(): void
    {
        $this->assertTrue(method_exists(ReviewAdminPageDataService::class, 'getDetailData'));
    }

    /**
     * 测试：ReviewAdminPageDataService有getReviewSummary方法
     */
    public function testReviewAdminPageDataServiceHasGetReviewSummaryMethod(): void
    {
        $this->assertTrue(method_exists(ReviewAdminPageDataService::class, 'getReviewSummary'));
    }

    /**
     * 测试：ReviewAdminPageDataService有getStatusOptions方法
     */
    public function testReviewAdminPageDataServiceHasGetStatusOptionsMethod(): void
    {
        $this->assertTrue(method_exists(ReviewAdminPageDataService::class, 'getStatusOptions'));
    }

    /**
     * 测试：ReviewAdminPageDataService有getRatingOptions方法
     */
    public function testReviewAdminPageDataServiceHasGetRatingOptionsMethod(): void
    {
        $this->assertTrue(method_exists(ReviewAdminPageDataService::class, 'getRatingOptions'));
    }

    /**
     * 测试：ReviewAdminPageDataService的getListData方法签名正确
     */
    public function testReviewAdminPageDataServiceGetListDataMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(ReviewAdminPageDataService::class, 'getListData');
        $params = $reflection->getParameters();

        $this->assertCount(3, $params);
        $this->assertEquals('page', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());
        $this->assertEquals('pageSize', $params[1]->getName());
        $this->assertEquals('int', $params[1]->getType()->getName());
        $this->assertEquals('filters', $params[2]->getName());
        $this->assertEquals('array', $params[2]->getType()->getName());
        $this->assertEquals('array', $reflection->getReturnType()->getName());
    }

    /**
     * 测试：ReviewAdminPageDataService的getDetailData方法签名正确
     */
    public function testReviewAdminPageDataServiceGetDetailDataMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(ReviewAdminPageDataService::class, 'getDetailData');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('reviewId', $params[0]->getName());
        $this->assertEquals('int', $params[0]->getType()->getName());
        $this->assertEquals('array', $reflection->getReturnType()->getName());
    }
}
