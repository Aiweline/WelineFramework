<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class AffiliateIndexTemplateTest extends TestCase
{
    public function testAffiliateIndexTemplateContainsKeyElements(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/index.phtml';
        $content = (string) file_get_contents($path);

        $this->assertIsString($content);
        $this->assertStringContainsString('Affiliate Management', $content);
        $this->assertStringContainsString('affiliateRecords', $content);
        $this->assertStringContainsString('affiliateIndexUrl', $content);
        $this->assertStringContainsString('affiliateSaveUrl', $content);
        $this->assertStringContainsString('statusOptions', $content);
        $this->assertStringContainsString('commission_rate', $content);
        $this->assertStringContainsString('referral_code', $content);
        $this->assertStringContainsString('filters', $content);
        $this->assertStringContainsString('pagination', $content);
    }

    public function testAffiliateIndexTemplateUsesI18n(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/index.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('__(\'Affiliate Management\')', $content);
        $this->assertStringContainsString('__(\'Customer ID\')', $content);
        $this->assertStringContainsString('__(\'Referral Code\')', $content);
        $this->assertStringContainsString('__(\'Commission Rate\')', $content);
        $this->assertStringContainsString('__(\'Status\')', $content);
        $this->assertStringContainsString('__(\'Apply Filters\')', $content);
        $this->assertStringContainsString('__(\'Reset\')', $content);
        $this->assertStringContainsString('__(\'Edit\')', $content);
        $this->assertStringContainsString('__(\'View\')', $content);
    }

    public function testAffiliateIndexTemplateHasSummaryCards(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/index.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('summary', $content);
        $this->assertStringContainsString('Total Affiliates', $content);
        $this->assertStringContainsString('Active', $content);
        $this->assertStringContainsString('Disabled', $content);
        $this->assertStringContainsString('Total Commission', $content);
    }

    public function testAffiliateIndexTemplateHasFormAndTableStructure(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/index.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('<form', $content);
        $this->assertStringContainsString('</form>', $content);
        $this->assertStringContainsString('<table', $content);
        $this->assertStringContainsString('</table>', $content);
        $this->assertStringContainsString('affiliate_id', $content);
        $this->assertStringContainsString('customer_id', $content);
    }

    public function testAffiliateIndexTemplateHasPagination(): void
    {
        $path = BP . 'app/code/WeShop/Affiliate/view/backend/templates/affiliate/index.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('pageCount', $content);
        $this->assertStringContainsString('currentPage', $content);
        $this->assertStringContainsString('Previous', $content);
        $this->assertStringContainsString('Next', $content);
    }
}
