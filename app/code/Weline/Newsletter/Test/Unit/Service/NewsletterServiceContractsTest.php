<?php

declare(strict_types=1);

namespace Weline\Newsletter\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Newsletter\Api\NewsletterCheckoutCouponAutoApplyInterface;
use Weline\Newsletter\Observer\CheckoutEmailMatchCouponObserver;
use Weline\Newsletter\Service\CheckoutAutoApplyService;
use Weline\Newsletter\Service\NewsletterMailSender;
use Weline\Newsletter\Service\SubscribeGiftCampaignSyncService;
use Weline\Newsletter\Service\SubscribeGiftConfig;
use Weline\Newsletter\Service\SubscribeService;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllNewsletterServiceContractsPass().
 */
final class NewsletterServiceContractsTest extends TestCase
{
    public function testAllNewsletterServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CheckoutAutoApply::testServiceImplementsInterfaceAndFixesApplySignature' => function () {
            $this->assert_CheckoutAutoApply_testServiceImplementsInterfaceAndFixesApplySignature();
        },
            'CheckoutAutoApply::testSubscribeServiceWiresT1AfterIssue' => function () {
            $this->assert_CheckoutAutoApply_testSubscribeServiceWiresT1AfterIssue();
        },
            'CheckoutAutoApply::testObserverExtractsEmailWithoutCheckoutModel' => function () {
            $this->assert_CheckoutAutoApply_testObserverExtractsEmailWithoutCheckoutModel();
        },
            'CheckoutAutoApply::testEventXmlRegistersCheckoutObservers' => function () {
            $this->assert_CheckoutAutoApply_testEventXmlRegistersCheckoutObservers();
        },
            'CheckoutAutoApply::testEventDocsExist' => function () {
            $this->assert_CheckoutAutoApply_testEventDocsExist();
        },
            'CheckoutAutoApply::testModuleProvidesInterface' => function () {
            $this->assert_CheckoutAutoApply_testModuleProvidesInterface();
        },
            'SubscribeService::testNormalizeAndValidateEmail' => function () {
            $this->assert_SubscribeService_testNormalizeAndValidateEmail();
        },
            'SubscribeService::testInvalidEmailReturnsContractFailureShapeWithoutSideEffects' => function () {
            $this->assert_SubscribeService_testInvalidEmailReturnsContractFailureShapeWithoutSideEffects();
        },
            'SubscribeService::testGiftAttributionConstantsMatchContracts' => function () {
            $this->assert_SubscribeService_testGiftAttributionConstantsMatchContracts();
        },
            'SubscribeService::testIssuerPassesValidDaysInIssueContext' => function () {
            $this->assert_SubscribeService_testIssuerPassesValidDaysInIssueContext();
        },
            'SubscribeService::testMailChannelsAndOrchestration' => function () {
            $this->assert_SubscribeService_testMailChannelsAndOrchestration();
        },
            'SubscribeService::testQueryProviderSubscribeOperation' => function () {
            $this->assert_SubscribeService_testQueryProviderSubscribeOperation();
        },
        ];
    }

private function assert_CheckoutAutoApply_testServiceImplementsInterfaceAndFixesApplySignature(): void
{
    self::assertTrue(\is_a(CheckoutAutoApplyService::class, NewsletterCheckoutCouponAutoApplyInterface::class, true));
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/Service/CheckoutAutoApplyService.php');
    self::assertStringContainsString('DiscountQuoteServiceInterface', $src);
    self::assertStringContainsString('applyCoupon($code, $quotes, $params)', $src);
    self::assertStringContainsString('applyForRecognizedEmail', $src);
    self::assertStringContainsString('GIFT_ISSUED', $src);
    self::assertStringContainsString('couponsAllowedForCartType', $src);
    self::assertStringContainsString('audience_tob', $src);
}

private function assert_CheckoutAutoApply_testSubscribeServiceWiresT1AfterIssue(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/Service/SubscribeService.php');
    self::assertStringContainsString('applyIssuedCoupon', $src);
    self::assertStringContainsString('CheckoutAutoApplyService', $src);
}

private function assert_CheckoutAutoApply_testObserverExtractsEmailWithoutCheckoutModel(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/Observer/CheckoutEmailMatchCouponObserver.php');
    self::assertStringContainsString('applyForRecognizedEmail', $src);
    self::assertStringContainsString('guest_email', $src);
    self::assertStringNotContainsString('Weline\Checkout\Model', $src);
    self::assertStringNotContainsString('Weline\Cart\Model', $src);
    self::assertStringNotContainsString('Weline\Marketing\Model\Coupon', $src);
}

private function assert_CheckoutAutoApply_testEventXmlRegistersCheckoutObservers(): void
{
    $xml = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/etc/event.xml');
    self::assertStringContainsString('Weline_Checkout::checkout::identity::resolve::after', $xml);
    self::assertStringContainsString('Weline_Checkout::checkout::guest::validate::after', $xml);
    self::assertStringContainsString(CheckoutEmailMatchCouponObserver::class, $xml);
}

private function assert_CheckoutAutoApply_testEventDocsExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/doc/event';
    self::assertFileExists($root . '/checkout-email-match-coupon.md');
    self::assertFileExists($root . '/guest-validate-after-coupon.md');
    $doc = (string) \file_get_contents($root . '/checkout-email-match-coupon.md');
    self::assertStringContainsString('identity::resolve::after', $doc);
    self::assertStringContainsString('NewsletterCheckoutCouponAutoApplyInterface', $doc);
}

private function assert_CheckoutAutoApply_testModuleProvidesInterface(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/etc/module.php';
    self::assertIsArray($module);
    self::assertSame('1.0.11', $module['version'] ?? '');
    $provides = $module['provides'] ?? [];
    self::assertSame(CheckoutAutoApplyService::class, $provides[NewsletterCheckoutCouponAutoApplyInterface::class] ?? null);
}

private function assert_SubscribeService_testNormalizeAndValidateEmail(): void
{
    $service = new SubscribeService();
    self::assertSame('a@b.com', $service->normalizeEmail('  A@B.COM  '));
    self::assertTrue($service->isValidEmail('user@example.com'));
    self::assertFalse($service->isValidEmail('not-an-email'));
    self::assertFalse($service->isValidEmail(''));
}

private function assert_SubscribeService_testInvalidEmailReturnsContractFailureShapeWithoutSideEffects(): void
{
    $service = new SubscribeService();
    self::assertFalse($service->isValidEmail('bad'));
    self::assertSame('', $service->normalizeEmail(str_repeat('a', 300) . '@x.com'));
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/Service/SubscribeService.php');
    self::assertStringContainsString("'ok' => false", $src);
    self::assertStringContainsString("'errors'", $src);
    self::assertStringContainsString('isValidEmail', $src);
    self::assertStringContainsString('preference_updated', $src);
    self::assertStringContainsString('subscriber_id', $src);
    self::assertStringContainsString('coupon_code', $src);
}

private function assert_SubscribeService_testGiftAttributionConstantsMatchContracts(): void
{
    self::assertSame('Weline_Newsletter', SubscribeGiftCampaignSyncService::SOURCE_MODULE);
    self::assertSame('newsletter_subscribe_gift', SubscribeGiftCampaignSyncService::SOURCE_TYPE);
    self::assertSame('default', SubscribeGiftCampaignSyncService::SOURCE_ID);
    self::assertSame('subscribe_gift', SubscribeGiftCampaignSyncService::SOURCE_KEY);
    self::assertSame(14, SubscribeGiftConfig::DEFAULT_VALID_DAYS);
    self::assertSame(10.0, SubscribeGiftConfig::DEFAULT_DISCOUNT_VALUE);
    self::assertTrue(SubscribeGiftConfig::DEFAULT_ENABLED);
}

private function assert_SubscribeService_testIssuerPassesValidDaysInIssueContext(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/Service/SubscribeGiftIssuer.php');
    self::assertStringContainsString("'valid_days'", $src);
    self::assertStringContainsString('issueRandomCoupon', $src);
    self::assertStringContainsString('GIFT_ISSUED', $src);
    self::assertStringContainsString('GIFT_REDEEMED', $src);
}

private function assert_SubscribeService_testMailChannelsAndOrchestration(): void
{
    self::assertSame('Weline_Newsletter::subscribe_welcome', NewsletterMailSender::CHANNEL_WELCOME);
    self::assertSame('Weline_Newsletter::subscribe_gift', NewsletterMailSender::CHANNEL_GIFT);
    $mailSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/Service/NewsletterMailSender.php');
    self::assertStringContainsString("result['success']", $mailSrc);
    $serviceSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/Service/SubscribeService.php');
    self::assertStringContainsString('sendGift', $serviceSrc);
    self::assertStringContainsString('sendWelcome', $serviceSrc);
    self::assertStringContainsString('preference_updated', $serviceSrc);
    self::assertStringContainsString('applyIssuedCoupon', $serviceSrc);
    $autoSrc = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/Service/CheckoutAutoApplyService.php');
    self::assertStringContainsString('MarketingCheckoutCouponSession', $autoSrc);
    self::assertStringContainsString('applyCoupon', $autoSrc);
    $provider = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/extends/MailChannelProvider.php');
    self::assertStringContainsString('Weline_Newsletter::subscribe_welcome', $provider);
    self::assertStringContainsString('Weline_Newsletter::subscribe_gift', $provider);
    self::assertStringContainsString("MailTemplateDefaultLocales::fileEntries('subscribe_welcome')", $provider);
    self::assertStringContainsString("MailTemplateDefaultLocales::fileEntries('subscribe_gift')", $provider);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/view/email/subscribe_welcome/zh_Hans_CN.html');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/view/email/subscribe_welcome/en_US.html');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/view/email/subscribe_gift/zh_Hans_CN.html');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/view/email/subscribe_gift/en_US.html');
    $giftSubject = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/view/email/subscribe_gift/zh_Hans_CN.subject.txt');
    $giftBody = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/view/email/subscribe_gift/zh_Hans_CN.html');
    self::assertStringContainsString('{{var.brand_display_name}}', $giftSubject);
    self::assertStringContainsString('{{var.brand_display_name}}', $giftBody);
}

private function assert_SubscribeService_testQueryProviderSubscribeOperation(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Newsletter' . '/extends/module/Weline_Framework/Query/NewsletterQueryProvider.php');
    self::assertStringContainsString("return 'newsletter'", $src);
    self::assertStringContainsString("'name' => 'subscribe'", $src);
    self::assertStringContainsString('SubscribeService', $src);
}
}
