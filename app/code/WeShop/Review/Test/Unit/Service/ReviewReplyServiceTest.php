<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Notification\Model\Notification;
use WeShop\Notification\Service\NotificationService;
use WeShop\Review\Model\Review;
use WeShop\Review\Model\ReviewReply;
use WeShop\Review\Service\ReviewReplyService;
use Weline\Framework\Http\Url;

class ReviewReplyServiceTest extends TestCase
{
    public function testExtractMentionedCustomerIdsFromExplicitIdsAndText(): void
    {
        $service = new ReviewReplyService();

        $result = $service->extractMentionedCustomerIds(
            "@customer:12 @#13 @\u{5ba2}\u{6237}14 @\u{7528}\u{6237}15 @42",
            ['16', '12', '0'],
            42
        );

        $this->assertSame([16, 12, 13, 14, 15], $result);
    }

    public function testBuildClientReplyPayloadDecodesMentionedCustomerIds(): void
    {
        $reply = $this->createMock(ReviewReply::class);
        $reply->method('getData')->willReturn([
            ReviewReply::schema_fields_ID => 501,
            ReviewReply::schema_fields_REVIEW_ID => 101,
            ReviewReply::schema_fields_PARENT_REPLY_ID => 7,
            ReviewReply::schema_fields_PRODUCT_ID => 88,
            ReviewReply::schema_fields_CUSTOMER_ID => 42,
            ReviewReply::schema_fields_DISPLAY_NAME => 'Ada',
            ReviewReply::schema_fields_CONTENT => 'Thanks @customer:77',
            ReviewReply::schema_fields_MENTIONED_CUSTOMER_IDS => '[77,88]',
            ReviewReply::schema_fields_STATUS => ReviewReply::STATUS_APPROVED,
            ReviewReply::schema_fields_CREATED_AT => '2026-05-24 12:00:00',
        ]);

        $payload = (new ReviewReplyService())->buildClientReplyPayload($reply);

        $this->assertSame(501, $payload['reply_id']);
        $this->assertSame(101, $payload['review_id']);
        $this->assertSame(7, $payload['parent_reply_id']);
        $this->assertSame('Ada', $payload['customer_name']);
        $this->assertSame([77, 88], $payload['mentioned_customer_ids']);
    }

    public function testNotifyRelatedCustomersSendsToReviewParentAndMentionedCustomers(): void
    {
        $sent = [];
        $notification = $this->createMock(Notification::class);
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->exactly(3))
            ->method('sendNotification')
            ->willReturnCallback(function (array $payload) use (&$sent, $notification): Notification {
                $sent[] = $payload;
                return $notification;
            });

        $url = $this->createMock(Url::class);
        $url->method('getUrl')->willReturn('/review?product_id=661&review_id=101&reply_id=555');

        $review = $this->createMock(Review::class);
        $review->method('getId')->willReturn(101);
        $review->method('getData')->willReturnCallback(static fn (?string $key = null): mixed => match ($key) {
            Review::schema_fields_PRODUCT_ID => 661,
            Review::schema_fields_CUSTOMER_ID => 9,
            default => null,
        });

        $reply = $this->createMock(ReviewReply::class);
        $reply->method('getId')->willReturn(555);
        $reply->method('getData')->willReturnCallback(static fn (?string $key = null): mixed => match ($key) {
            ReviewReply::schema_fields_CUSTOMER_ID => 42,
            default => null,
        });

        $parentReply = $this->createMock(ReviewReply::class);
        $parentReply->method('getData')->willReturnCallback(static fn (?string $key = null): mixed => match ($key) {
            ReviewReply::schema_fields_CUSTOMER_ID => 10,
            default => null,
        });

        $method = new \ReflectionMethod(ReviewReplyService::class, 'notifyRelatedCustomers');
        $method->setAccessible(true);
        $method->invoke(
            new ReviewReplyService($notificationService, $url),
            $review,
            $reply,
            $parentReply,
            [11, 9, 42],
            'Ada'
        );

        $this->assertSame([9, 10, 11], array_column($sent, 'customer_id'));
        $this->assertSame(['review_reply', 'review_reply', 'review_reply'], array_column($sent, 'type'));
        $this->assertSame('/review?product_id=661&review_id=101&reply_id=555#review-reply-555', $sent[0]['target_url']);
    }
}
