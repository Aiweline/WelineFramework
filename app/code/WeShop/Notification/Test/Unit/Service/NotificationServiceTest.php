<?php

declare(strict_types=1);

namespace WeShop\Notification\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Notification\Service\NotificationService;

final class NotificationServiceTest extends TestCase
{
    public function testNormalizeTargetUrlKeepsSafeReviewReplyRelativeUrl(): void
    {
        $service = new NotificationService();

        $this->assertSame(
            '/product/view?id=958&review_id=742&reply_id=3#review-reply-3',
            $this->normalizeTargetUrl($service, '/product/view?id=958&review_id=742&reply_id=3#review-reply-3')
        );
    }

    public function testNormalizeTargetUrlRejectsUnsafeSchemesAndProtocolRelativeUrls(): void
    {
        $service = new NotificationService();

        $this->assertSame('', $this->normalizeTargetUrl($service, 'javascript:alert(1)'));
        $this->assertSame('', $this->normalizeTargetUrl($service, '//example.com/review'));
    }

    private function normalizeTargetUrl(NotificationService $service, mixed $targetUrl): string
    {
        $reflection = new \ReflectionMethod(NotificationService::class, 'normalizeTargetUrl');
        $reflection->setAccessible(true);

        return (string) $reflection->invoke($service, $targetUrl);
    }
}
