<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Review\Extends\Module\Weline_Framework\Query\ReviewQueryProvider;
use WeShop\Review\Model\ReviewReply;
use WeShop\Review\Service\ReviewConfigService;
use WeShop\Review\Service\ReviewPurchaseEligibilityService;
use WeShop\Review\Service\ReviewReplyService;
use WeShop\Review\Service\ReviewService;
use Weline\Framework\Http\Url;
use Weline\Framework\Ui\FormKey;

class ReviewQueryProviderTest extends TestCase
{
    public function testFormTokenIsPreparedLazilyThroughApi(): void
    {
        $formKey = $this->createMock(FormKey::class);
        $formKey->expects($this->once())
            ->method('getKey')
            ->with('/review/create')
            ->willReturn('token-123');

        $provider = new ReviewQueryProvider(
            $this->createMock(CustomerContextInterface::class),
            $this->createMock(ReviewService::class),
            $this->createMock(Url::class),
            null,
            null,
            null,
            $formKey
        );

        $result = $provider->execute('formToken', ['path' => '/review/create']);

        $this->assertTrue($result['success']);
        $this->assertSame('form_key', $result['data']['name']);
        $this->assertSame('token-123', $result['data']['value']);
    }

    public function testResolveModeSelectsAnonymousForGuestWithoutPurchaseLookup(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $purchaseEligibilityService = $this->createMock(ReviewPurchaseEligibilityService::class);
        $purchaseEligibilityService->expects($this->never())->method('customerCanReviewProduct');

        $provider = new ReviewQueryProvider(
            $customerContext,
            $this->createMock(ReviewService::class),
            $this->createMock(Url::class),
            $this->createMock(ReviewConfigService::class),
            $purchaseEligibilityService
        );

        $result = $provider->execute('resolveMode', ['product_id' => 88]);

        $this->assertTrue($result['success']);
        $this->assertSame(ReviewConfigService::MODE_ANONYMOUS, $result['data']['selected_mode']);
        $this->assertFalse($result['data']['is_logged_in']);
        $this->assertFalse($result['data']['can_order_review']);
    }

    public function testResolveModeSelectsOrderWhenLoggedCustomerPurchasedProduct(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(42);

        $purchaseEligibilityService = $this->createMock(ReviewPurchaseEligibilityService::class);
        $purchaseEligibilityService->expects($this->once())
            ->method('customerCanReviewProduct')
            ->with(42, 88)
            ->willReturn(true);

        $provider = new ReviewQueryProvider(
            $customerContext,
            $this->createMock(ReviewService::class),
            $this->createMock(Url::class),
            $this->createMock(ReviewConfigService::class),
            $purchaseEligibilityService
        );

        $result = $provider->execute('resolveMode', ['product_id' => 88]);

        $this->assertTrue($result['success']);
        $this->assertSame(ReviewConfigService::MODE_ORDER, $result['data']['selected_mode']);
        $this->assertTrue($result['data']['is_logged_in']);
        $this->assertTrue($result['data']['can_order_review']);
    }

    public function testReplyRequiresLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(null);

        $url = $this->createMock(Url::class);
        $url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/login')
            ->willReturn('/customer/account/login');

        $replyService = $this->createMock(ReviewReplyService::class);
        $replyService->expects($this->never())->method('createReply');

        $provider = new ReviewQueryProvider(
            $customerContext,
            $this->createMock(ReviewService::class),
            $url,
            null,
            null,
            $replyService
        );

        $result = $provider->execute('reply', [
            'review_id' => 101,
            'content' => 'Thanks for sharing.',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('/customer/account/login', $result['data']['redirect_url']);
    }

    public function testReplyCreatesReplyForLoggedInCustomer(): void
    {
        $customerContext = $this->createMock(CustomerContextInterface::class);
        $customerContext->expects($this->once())->method('getUserId')->willReturn(42);

        $reply = $this->createMock(ReviewReply::class);
        $reply->method('getId')->willReturn(501);

        $replyService = $this->createMock(ReviewReplyService::class);
        $replyService->expects($this->once())
            ->method('createReply')
            ->with($this->callback(static function (array $payload): bool {
                return $payload['review_id'] === 101
                    && $payload['parent_reply_id'] === 7
                    && $payload['customer_id'] === 42
                    && $payload['content'] === '@customer:77 Thanks for sharing.';
            }))
            ->willReturn($reply);
        $replyService->expects($this->once())
            ->method('buildClientReplyPayload')
            ->with($reply)
            ->willReturn([
                'reply_id' => 501,
                'review_id' => 101,
                'parent_reply_id' => 7,
                'customer_id' => 42,
                'customer_name' => 'Ada',
                'content' => '@customer:77 Thanks for sharing.',
            ]);

        $provider = new ReviewQueryProvider(
            $customerContext,
            $this->createMock(ReviewService::class),
            $this->createMock(Url::class),
            null,
            null,
            $replyService
        );

        $result = $provider->execute('reply', [
            'review_id' => 101,
            'parent_reply_id' => 7,
            'content' => '@customer:77 Thanks for sharing.',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(501, $result['data']['reply_id']);
        $this->assertSame('Ada', $result['data']['reply']['customer_name']);
    }
}
