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
        $this->assertStringContainsString('data-review-translate', $template);
        $this->assertStringContainsString('self.Translator.create', $template);
        $this->assertStringContainsString('focusDeepLinkedReviewNode', $template);
        $this->assertStringContainsString('review-reply-', $template);
        $this->assertStringContainsString('data-mention-customer-id', $template);
        $this->assertStringContainsString('mentioned_customer_ids: extractMentionedCustomerIds(content)', $template);
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
        $this->assertStringContainsString('window.account || window.Account || window.WelineAccountModule', $template);
        $this->assertStringContainsString('data-review-translate', $template);
        $this->assertStringContainsString('self.Translator.create', $template);
    }
}
