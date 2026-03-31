<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class AffiliateViewTemplateTest extends TestCase
{
    public function testAffiliateViewTemplateContainsKeyElements(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/view.phtml';
        $content = (string) file_get_contents($path);

        $this->assertIsString($content);
        $this->assertStringContainsString('Affiliate Details', $content);
        $this->assertStringContainsString('affiliate', $content);
        $this->assertStringContainsString('statusOptions', $content);
        $this->assertStringContainsString('affiliateIndexUrl', $content);
        $this->assertStringContainsString('affiliateSaveUrl', $content);
    }

    public function testAffiliateViewTemplateUsesI18n(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/view.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('__(\'Affiliate Details\')', $content);
        $this->assertStringContainsString('__(\'Back to List\')', $content);
        $this->assertStringContainsString('__(\'Edit\')', $content);
        $this->assertStringContainsString('__(\'Affiliate Information\')', $content);
        $this->assertStringContainsString('__(\'Referral Code\')', $content);
        $this->assertStringContainsString('__(\'Commission Rate\')', $content);
        $this->assertStringContainsString('__(\'Account Status\')', $content);
        $this->assertStringContainsString('__(\'Commission Summary\')', $content);
    }

    public function testAffiliateViewTemplateHasCommissionSummary(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/view.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('total_commission', $content);
        $this->assertStringContainsString('paid_commission', $content);
        $this->assertStringContainsString('pending_commission', $content);
        $this->assertStringContainsString('Total Commission', $content);
        $this->assertStringContainsString('Paid', $content);
        $this->assertStringContainsString('Pending', $content);
        $this->assertStringContainsString('Commission Rate', $content);
    }

    public function testAffiliateViewTemplateHasCopyFunctionality(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/view.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('copyReferralCode', $content);
        $this->assertStringContainsString('copyReferralLink', $content);
        $this->assertStringContainsString('referral_link', $content);
        $this->assertStringContainsString('navigator.clipboard', $content);
    }

    public function testAffiliateViewTemplateHasProgressBar(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/view.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('progress', $content);
        $this->assertStringContainsString('Payment Progress', $content);
    }

    public function testAffiliateViewTemplateEnablesEditModeForValidAffiliate(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/view.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('$isEditMode = $affiliateId > 0;', $content);
        $this->assertStringContainsString('name="status" class="form-select" <?= $isEditMode ? \'\' : \'disabled\' ?>', $content);
        $this->assertStringContainsString('<?= $isEditMode ? \'required\' : \'readonly\' ?>', $content);
    }
}
