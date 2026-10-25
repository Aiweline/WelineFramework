<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllMarketingViewContractsPass().
 */
final class MarketingViewContractsTest extends TestCase
{
    public function testAllMarketingViewContractsPass(): void
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
            'CheckoutCouponScript::testCheckoutCouponScriptUsesAsyncMarketingApiResource' => function () {
            $this->assert_CheckoutCouponScript_testCheckoutCouponScriptUsesAsyncMarketingApiResource();
        },
            'CheckoutCouponWidget::testWidgetRegistrationPinsThreeDefaultInjections' => function () {
            $this->assert_CheckoutCouponWidget_testWidgetRegistrationPinsThreeDefaultInjections();
        },
            'CheckoutCouponWidget::testCheckoutCouponWidgetUsesCommerceLayoutClasses' => function () {
            $this->assert_CheckoutCouponWidget_testCheckoutCouponWidgetUsesCommerceLayoutClasses();
        },
            'FooterCampaignLinkWidget::testFooterCampaignLinkRegistersDefaultInjection' => function () {
            $this->assert_FooterCampaignLinkWidget_testFooterCampaignLinkRegistersDefaultInjection();
        },
            'FooterCampaignLinkWidget::testFooterCampaignLinkTemplatePointsToPromotionDeals' => function () {
            $this->assert_FooterCampaignLinkWidget_testFooterCampaignLinkTemplatePointsToPromotionDeals();
        },
            'ScheduleWindowFormTemplate::testCampaignFormDocumentsWebsiteTimezoneUtcStorage' => function () {
            $this->assert_ScheduleWindowFormTemplate_testCampaignFormDocumentsWebsiteTimezoneUtcStorage();
        },
            'ScheduleWindowFormTemplate::testRuleAndCouponFormsExposeWindows' => function () {
            $this->assert_ScheduleWindowFormTemplate_testRuleAndCouponFormsExposeWindows();
        },
        ];
    }

private function assert_CheckoutCouponScript_testCheckoutCouponScriptUsesAsyncMarketingApiResource(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/statics/js/widgets/checkout-coupon.js';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('async function waitForMarketingApi()', $source);
    self::assertStringContainsString("resource('marketing')", $source);
    self::assertStringContainsString('await waitForMarketingApi()', $source);
    self::assertStringContainsString('weshop:mini-cart:extras-ready', $source);
    self::assertStringContainsString('getCoupon', $source);
    self::assertStringContainsString('data-marketing-coupon-tag', $source);
    self::assertStringContainsString('renderTags', $source);
    self::assertStringContainsString('setApplyLoading', $source);
    self::assertStringContainsString('miniCartBusyDelta', $source);
    self::assertStringContainsString('weshop:mini-cart:busy', $source);
    self::assertStringContainsString('is-loading', $source);
    self::assertStringContainsString('notifyCartDiscountChanged', $source);
    self::assertStringContainsString('buildQuotePayload', $source);
    self::assertStringContainsString('discountPreviewFromQuote', $source);
    self::assertStringContainsString('discount_preview', $source);
    self::assertStringContainsString("sku: String(item.sku || '').trim()", $source);
    self::assertStringContainsString('product_id', $source);
    self::assertStringContainsString('amount_minor', $source);
    self::assertStringContainsString('client.applyCoupon(payload', $source);
    self::assertStringContainsString('resolveCartType', $source);
    self::assertStringContainsString('hydrateForCurrentType', $source);
    self::assertStringContainsString('restoreOnly', $source);
    self::assertStringContainsString('refresh: false', $source);
    self::assertStringContainsString('Zero-amount ghost coupons', $source);
    self::assertStringContainsString('cartResponse.data.items', $source);
    self::assertStringContainsString('payload.guest_token = token', $source);
    self::assertStringContainsString('forcedType', $source);
    self::assertStringContainsString('weline:selling-mode-changed', $source);
    self::assertStringContainsString("function i18n(attr, fallback)", $source);
    self::assertStringContainsString("i18n('data-i18n-invalid-limit'", $source);
    self::assertStringContainsString("i18n('data-i18n-enter-code'", $source);
    self::assertStringContainsString("return '-' + code + ' ' + formatAmount(", $source);
    self::assertStringContainsString('syncAppliedState(paintCode', $source);
    self::assertStringContainsString('detail.refresh === false', $source);
}

private function assert_CheckoutCouponWidget_testWidgetRegistrationPinsThreeDefaultInjections(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
    $tpl = 'Weline_Marketing::templates/frontend/widgets/checkout-coupon.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    self::assertFalse(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Marketing::templates/frontend/widgets/cart-coupon.phtml'));
    self::assertFalse(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, 'Weline_Marketing::templates/frontend/widgets/mini-cart-coupon.phtml'));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/frontend/widgets/checkout-coupon.phtml');
    self::assertStringContainsString('@widget.code {checkout-coupon}', $src);
    self::assertStringContainsString('@widget.type {content}', $src);
    self::assertStringContainsString('@widget.cache {3600}', $src);
    self::assertStringContainsString('@widget.share {true}', $src);
    self::assertStringContainsString('@widget.page_layouts {["checkout","cart","mini-cart"]}', $src);
    self::assertStringContainsString('"layout_type":"checkout"', $src);
    self::assertStringContainsString('"slot":"checkout-summary-discount"', $src);
    self::assertStringContainsString('"layout_type":"cart"', $src);
    self::assertStringContainsString('"slot":"cart-summary-discount"', $src);
    self::assertStringContainsString('"layout_type":"mini-cart"', $src);
    self::assertStringContainsString('"slot":"footer-extras"', $src);
    self::assertStringContainsString('"compact":true', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_CheckoutCouponWidget_testCheckoutCouponWidgetUsesCommerceLayoutClasses(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/frontend/widgets/checkout-coupon.phtml');
    self::assertStringContainsString('data-testid="checkout-coupon"', $template);
    self::assertStringContainsString('data-widget-code="checkout-coupon"', $template);
    self::assertStringContainsString('data-marketing-checkout-coupon', $template);
    self::assertStringContainsString('w-marketing-checkout-coupon__controls', $template);
    self::assertStringContainsString('w-marketing-checkout-coupon--mini-cart', $template);
    self::assertStringContainsString('@widget.default_injections', $template);
    self::assertStringContainsString('checkout-summary-discount', $template);
    self::assertStringNotContainsString('w-marketing-checkout-coupon__header', $template);
    self::assertStringContainsString('data-marketing-coupon-tags', $template);
    self::assertStringContainsString('data-marketing-coupon-entry', $template);
    self::assertStringContainsString('checkout-coupon.css', $template);
    self::assertStringContainsString('mini-cart-coupon.css', $template);
    self::assertStringContainsString('data-weline-load="checkoutCoupon"', $template);
    self::assertStringContainsString('data-i18n-invalid-limit=', $template);
    self::assertStringContainsString('data-i18n-enter-code=', $template);
    self::assertStringContainsString('data-i18n-loading=', $template);
    self::assertStringContainsString('data-mini-cart-tab-label-source', $template);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/frontend/widgets/cart-coupon.phtml');
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/frontend/widgets/mini-cart-coupon.phtml');
}

private function assert_FooterCampaignLinkWidget_testFooterCampaignLinkRegistersDefaultInjection(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/extends/module/Weline_Widget/Weline_Marketing/widget.php';
    $tpl = 'Weline_Marketing::templates/frontend/widgets/footer-campaign-link.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/frontend/widgets/footer-campaign-link.phtml');
    self::assertStringContainsString('@widget.code {footer-campaign-link}', $src);
    self::assertStringContainsString('@widget.slot {footer-payment-account-links}', $src);
    self::assertStringContainsString('"slot":"footer-payment-account-links"', $src);
    self::assertStringContainsString('"required":true', $src);
}

private function assert_FooterCampaignLinkWidget_testFooterCampaignLinkTemplatePointsToPromotionDeals(): void
{
    $template = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/frontend/widgets/footer-campaign-link.phtml');
    self::assertStringContainsString('data-testid="footer-campaign-link"', $template);
    self::assertStringContainsString("@url{'promotion/deals'}", $template);
    self::assertStringNotContainsString("@url{'marketing/campaign'}", $template);
    self::assertStringContainsString('活动', $template);
    self::assertStringContainsString('WidgetI18n::label($labelSource', $template);
}

private function assert_ScheduleWindowFormTemplate_testCampaignFormDocumentsWebsiteTimezoneUtcStorage(): void
{
    $src = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/backend/campaign/form.phtml');
    self::assertIsString($src);
    self::assertStringContainsString('datetime-local', $src);
    self::assertStringContainsString('按站点时区', $src);
    self::assertStringContainsString('utcSqlToLocalInput', $src);
    self::assertStringContainsString('data-testid="marketing-campaign-start"', $src);
}

private function assert_ScheduleWindowFormTemplate_testRuleAndCouponFormsExposeWindows(): void
{
    $rule = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/backend/rule/form.phtml');
    $coupon = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . '/view/templates/backend/coupon/form.phtml');
    self::assertIsString($rule);
    self::assertIsString($coupon);
    self::assertStringContainsString('marketing-rule-start', $rule);
    self::assertStringContainsString('按站点时区', $rule);
    self::assertStringContainsString('marketing-coupon-start', $coupon);
    self::assertStringContainsString('按站点时区', $coupon);
}
}
