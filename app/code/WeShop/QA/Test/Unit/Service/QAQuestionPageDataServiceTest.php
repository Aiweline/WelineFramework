<?php

declare(strict_types=1);

namespace WeShop\QA\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductService;
use WeShop\QA\Service\QAQuestionPageDataService;
use WeShop\QA\Service\QAService;
use Weline\Framework\Http\Url;

class QAQuestionPageDataServiceTest extends TestCase
{
    public function testBuildReturnsStructuredQaPageData(): void
    {
        $qaService = $this->createMock(QAService::class);
        $productService = $this->createMock(ProductService::class);
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $url = $this->createMock(Url::class);

        $customerContext->expects($this->once())->method('getUserId')->willReturn(12);

        $qaService->expects($this->once())
            ->method('getProductQuestions')
            ->with(99)
            ->willReturn([
                [
                    'question_id' => 1,
                    'customer_id' => 12,
                    'question' => 'Is this waterproof?',
                    'answer' => 'Yes, IPX4 rated.',
                    'created_at' => '2026-03-24 10:00:00',
                ],
            ]);

        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturn(['product_id' => 99, 'name' => 'Sample Product']);
        $productService->expects($this->once())->method('getProduct')->with(99)->willReturn($product);

        $url->method('getUrl')->willReturnMap([
            ['qa/add', null, '/qa/add'],
            ['qa/remove', null, '/qa/remove'],
            ['customer/account/login', null, '/customer/account/login'],
        ]);

        $service = new QAQuestionPageDataService($qaService, $productService, $customerContext, $url);
        $result = $service->build(99);

        $this->assertSame(1, $result['question_count']);
        $this->assertTrue($result['can_ask']);
        $this->assertSame('/qa/add', $result['ask_action']);
        $this->assertSame('/qa/remove', $result['remove_action']);
        $this->assertSame('/customer/account/login', $result['login_url']);
        $this->assertSame('Sample Product', $result['product']['name']);
        $this->assertTrue($result['qa_list'][0]['is_owner']);
    }
}
