<?php

declare(strict_types=1);

namespace Weline\Newsletter\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Newsletter\Service\SubscribeGiftCampaignSyncService;
use Weline\Newsletter\Service\SubscribeGiftConfig;
use Weline\Newsletter\Service\SubscribeService;
use Weline\Newsletter\Service\NewsletterMailSender;

/**
 * Contract: SubscribeService API shape + gift attribution constants + mail channels.
 */
final class SubscribeServiceContractTest extends TestCase
{
    public function testNormalizeAndValidateEmail(): void
    {
        $service = new SubscribeService();
        self::assertSame('a@b.com', $service->normalizeEmail('  A@B.COM  '));
        self::assertTrue($service->isValidEmail('user@example.com'));
        self::assertFalse($service->isValidEmail('not-an-email'));
        self::assertFalse($service->isValidEmail(''));
    }

    public function testInvalidEmailReturnsContractFailureShapeWithoutSideEffects(): void
    {
        $service = new SubscribeService();
        self::assertFalse($service->isValidEmail('bad'));
        self::assertSame('', $service->normalizeEmail(str_repeat('a', 300) . '@x.com'));

        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/SubscribeService.php');
        self::assertStringContainsString("'ok' => false", $src);
        self::assertStringContainsString("'errors'", $src);
        self::assertStringContainsString('isValidEmail', $src);
        self::assertStringContainsString('preference_updated', $src);
        self::assertStringContainsString('subscriber_id', $src);
        self::assertStringContainsString('coupon_code', $src);
    }

    public function testGiftAttributionConstantsMatchContracts(): void
    {
        self::assertSame('Weline_Newsletter', SubscribeGiftCampaignSyncService::SOURCE_MODULE);
        self::assertSame('newsletter_subscribe_gift', SubscribeGiftCampaignSyncService::SOURCE_TYPE);
        self::assertSame('default', SubscribeGiftCampaignSyncService::SOURCE_ID);
        self::assertSame('subscribe_gift', SubscribeGiftCampaignSyncService::SOURCE_KEY);
        self::assertSame(14, SubscribeGiftConfig::DEFAULT_VALID_DAYS);
        self::assertSame(10.0, SubscribeGiftConfig::DEFAULT_DISCOUNT_VALUE);
        self::assertTrue(SubscribeGiftConfig::DEFAULT_ENABLED);
    }

    public function testIssuerPassesValidDaysInIssueContext(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/SubscribeGiftIssuer.php');
        self::assertStringContainsString("'valid_days'", $src);
        self::assertStringContainsString('issueRandomCoupon', $src);
        self::assertStringContainsString('GIFT_ISSUED', $src);
        self::assertStringContainsString('GIFT_REDEEMED', $src);
    }

    public function testMailChannelsAndOrchestration(): void
    {
        self::assertSame('Weline_Newsletter::subscribe_welcome', NewsletterMailSender::CHANNEL_WELCOME);
        self::assertSame('Weline_Newsletter::subscribe_gift', NewsletterMailSender::CHANNEL_GIFT);

        $mailSrc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/NewsletterMailSender.php');
        self::assertStringContainsString("result['success']", $mailSrc);

        $serviceSrc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/SubscribeService.php');
        self::assertStringContainsString('sendGift', $serviceSrc);
        self::assertStringContainsString('sendWelcome', $serviceSrc);
        self::assertStringContainsString('preference_updated', $serviceSrc);
        self::assertStringContainsString('applyIssuedCoupon', $serviceSrc);

        $autoSrc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/CheckoutAutoApplyService.php');
        self::assertStringContainsString('MarketingCheckoutCouponSession', $autoSrc);
        self::assertStringContainsString('applyCoupon', $autoSrc);

        $provider = (string)\file_get_contents(\dirname(__DIR__, 3) . '/extends/MailChannelProvider.php');
        self::assertStringContainsString('Weline_Newsletter::subscribe_welcome', $provider);
        self::assertStringContainsString('Weline_Newsletter::subscribe_gift', $provider);
        self::assertStringContainsString("MailTemplateDefaultLocales::fileEntries('subscribe_welcome')", $provider);
        self::assertStringContainsString("MailTemplateDefaultLocales::fileEntries('subscribe_gift')", $provider);

        self::assertFileExists(\dirname(__DIR__, 3) . '/view/email/subscribe_welcome/zh_Hans_CN.html');
        self::assertFileExists(\dirname(__DIR__, 3) . '/view/email/subscribe_welcome/en_US.html');
        self::assertFileExists(\dirname(__DIR__, 3) . '/view/email/subscribe_gift/zh_Hans_CN.html');
        self::assertFileExists(\dirname(__DIR__, 3) . '/view/email/subscribe_gift/en_US.html');

        $giftSubject = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/email/subscribe_gift/zh_Hans_CN.subject.txt');
        $giftBody = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/email/subscribe_gift/zh_Hans_CN.html');
        self::assertStringContainsString('{{var.brand_display_name}}', $giftSubject);
        self::assertStringContainsString('{{var.brand_display_name}}', $giftBody);
    }

    public function testQueryProviderSubscribeOperation(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/NewsletterQueryProvider.php'
        );
        self::assertStringContainsString("return 'newsletter'", $src);
        self::assertStringContainsString("'name' => 'subscribe'", $src);
        self::assertStringContainsString('SubscribeService', $src);
    }
}
