<?php

declare(strict_types=1);

namespace WeShop\Review\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class ReviewWriteTemplateTest extends TestCase
{
    public function testReviewPageWriteFormIsLazyAndUsesAccountLoginModal(): void
    {
        $template = file_get_contents(BP . 'app/design/WeShop/default/frontend/pages/review/index.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('id="review-write-trigger"', $template);
        $this->assertStringContainsString('id="review-write-panel" class="hidden"', $template);
        $this->assertStringContainsString('data-review-form-token', $template);
        $this->assertStringContainsString('ReviewApi.formToken', $template);
        $this->assertStringContainsString('window.account || window.Account || window.WelineAccountModule', $template);
        $this->assertStringContainsString('function handleReviewWriteIntent(event)', $template);
        $this->assertStringContainsString('if (!isAccountLoggedInSync())', $template);
        $this->assertStringContainsString('openAuthModal(function () {', $template);
        $this->assertStringContainsString('showWritePanel();', $template);
        $this->assertStringContainsString("writeTrigger.addEventListener('click', handleReviewWriteIntent)", $template);
        $this->assertStringContainsString('review-auth-primary-label', $template);
        $this->assertStringContainsString('color: #fff !important', $template);
        $this->assertStringContainsString('data-review-translate', $template);
        $this->assertStringContainsString('self.Translator.create', $template);
        $this->assertStringContainsString('focusDeepLinkedReviewNode', $template);
        $this->assertStringContainsString('data-review-deep-link-target', $template);
        $this->assertStringContainsString('[data-review-deep-link-target="1"]', $template);
        $this->assertStringContainsString("window.addEventListener('hashchange', focusDeepLinkedReviewNode)", $template);
        $this->assertStringContainsString('review-reply-', $template);
        $this->assertStringContainsString('data-mention-customer-id', $template);
        $this->assertStringContainsString("'mentionHint' => (string) __('本次回复会通知 %{1}。', ['__REVIEW_MENTION__'])", $template);
        $this->assertStringContainsString("template.replace('__REVIEW_MENTION__', mentionLabel)", $template);
        $this->assertStringContainsString('mentioned_customer_ids: extractMentionedCustomerIds(content)', $template);
        $this->assertStringContainsString('delete payload.rating_scores', $template);
        $this->assertStringContainsString('delete payload.media_items', $template);
        $this->assertStringNotContainsString('$this->getFormKey($createUrl)', $template);
    }

    public function testProductReviewHookRendersInlineLazyWriteForm(): void
    {
        $template = file_get_contents(BP . 'app/code/WeShop/Review/view/hooks/WeShop_Review/frontend/layouts/product-reviews/content.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('data-review-write-trigger', $template);
        $this->assertStringContainsString('data-review-write-panel', $template);
        $this->assertStringContainsString('data-review-form-token', $template);
        $this->assertStringContainsString('ReviewApi.formToken', $template);
        $this->assertStringContainsString('ReviewApi.resolveMode', $template);
        $this->assertStringContainsString('function loadAccountModule()', $template);
        $this->assertStringContainsString('checkFrontendUserLogin', $template);
        $this->assertStringContainsString('function checkAccountLoginState()', $template);
        $this->assertStringContainsString('function handleReviewWriteIntent(event)', $template);
        $this->assertStringContainsString('function resolveLoginPayload(result)', $template);
        $this->assertStringContainsString('function resumePostLoginIntent(intent)', $template);
        $this->assertStringContainsString('window.WelineAccountModule', $template);
        $this->assertStringContainsString('openAuthModal({type: \'write\'});', $template);
        $this->assertStringContainsString('resumePostLoginIntent(intent);', $template);
        $this->assertStringContainsString('review_write', $template);
        $this->assertStringContainsString('focusWritePanel();', $template);
        $this->assertStringContainsString('resumeReviewWriteIntentFromUrl();', $template);
        $this->assertStringContainsString('showPanel({focus: true});', $template);
        $this->assertStringContainsString('$shouldOpenReviewWritePanel', $template);
        $this->assertStringContainsString("writeTrigger.addEventListener('click', handleReviewWriteIntent)", $template);
        $this->assertStringContainsString('review-auth-primary-label', $template);
        $this->assertStringContainsString('登录成功后会刷新当前页面，并自动打开评论表单。', $template);
        $this->assertStringContainsString('data-review-translate', $template);
        $this->assertStringContainsString('self.Translator.create', $template);
        $this->assertStringContainsString('id="product-reviews"', $template);
        $this->assertLessThan(
            strpos($template, 'data-review-summary'),
            strpos($template, 'data-review-write-panel'),
            'Write panel should render before the review summary for top-of-section UX.'
        );
        $this->assertStringContainsString('data-review-surface="product-detail"', $template);
        $this->assertStringContainsString('data-review-summary', $template);
        $this->assertStringContainsString('weshop.product.view.page_data', $template);
        $this->assertStringContainsString('getAverageRating($productId)', $template);
        $this->assertStringContainsString('getProductReviews($productId', $template);
        $this->assertStringContainsString('rating_distribution', $template);
        $this->assertStringContainsString('data-review-initial', $template);
        $this->assertStringContainsString('data-review-rating-badge', $template);
        $this->assertStringContainsString('data-review-score-pills', $template);
        $this->assertStringContainsString('review-reply-', $template);
        $this->assertStringContainsString('data-review-reply-initial', $template);
        $this->assertStringContainsString('data-review-reply-toggle', $template);
        $this->assertStringContainsString('data-mention-customer-id', $template);
        $this->assertStringContainsString("'mentionHint' => (string) __('本次回复会通知 %{1}。', ['__REVIEW_MENTION__'])", $template);
        $this->assertStringContainsString("template.replace('__REVIEW_MENTION__', mentionLabel)", $template);
        $this->assertStringContainsString('mentioned_customer_ids: extractMentionedCustomerIds(content)', $template);
        $this->assertStringContainsString('focusDeepLinkedReviewNode', $template);
        $this->assertStringContainsString('window.WeShopProductTabs', $template);
        $this->assertStringContainsString('activateProductDetailTab(\'reviews\')', $template);
        $this->assertStringContainsString('data-review-deep-link-target', $template);
        $this->assertStringNotContainsString('$this->getUrl(\'review\', [\'product_id\' => $productId])', $template);
        $this->assertStringNotContainsString('<style>', $template);
    }

    public function testProductReviewStylesLoadThroughProductHeadHook(): void
    {
        $headHook = file_get_contents(BP . 'app/code/WeShop/Review/view/hooks/Weline_Theme/frontend/layouts/base/head-after.phtml');
        $css = file_get_contents(BP . 'app/code/WeShop/Review/view/statics/css/product-reviews.css');

        $this->assertIsString($headHook);
        $this->assertIsString($css);
        $this->assertStringContainsString("fetchTagSource('statics', 'WeShop_Review::css/product-reviews.css'", $headHook);
        $this->assertStringContainsString('WeShop_Review::css/product-reviews.css', $headHook);
        $this->assertStringContainsString('product-review-responsive-stack', $headHook);
        $this->assertStringContainsString('<link rel="stylesheet"', $headHook);
        $this->assertStringContainsString('#product-reviews', $css);
        $this->assertStringContainsString('container-type: inline-size', $css);
        $this->assertStringContainsString('grid-template-columns: minmax(240px, 300px) minmax(0, 1fr)', $css);
        $this->assertStringContainsString('@media (max-width: 1023px)', $css);
        $this->assertStringContainsString('@container product-reviews (max-width: 1023px)', $css);
        $this->assertStringContainsString(':has([data-review-write-panel]:not([hidden]))', $css);
        $this->assertStringContainsString('[data-review-summary]', $css);
        $this->assertStringContainsString('.motor-review-summary__bar-row', $css);
        $this->assertStringContainsString('article[data-review-id]::before', $css);
        $this->assertStringContainsString('content: attr(data-review-initial)', $css);
        $this->assertStringContainsString('--review-thread-background', $css);
        $this->assertStringContainsString('--review-primary-dark', $css);
        $this->assertStringContainsString('content: "\\2605"', $css);
        $this->assertStringContainsString('[data-review-rating-badge]', $css);
        $this->assertStringContainsString('[data-review-score-pills]', $css);
        $this->assertStringContainsString('[data-review-reply-id]::before', $css);
        $this->assertStringContainsString('[data-review-deep-link-target="1"]', $css);
        $this->assertStringContainsString('[data-review-reply-toggle]', $css);
        $this->assertStringContainsString('.material-symbols-outlined', $css);
        $this->assertStringContainsString('.review-auth-primary-label', $css);
        $this->assertStringContainsString('color: #fff !important', $css);
        $this->assertStringContainsString('var(--review-primary-dark, #b45309)', $css);
    }
}
