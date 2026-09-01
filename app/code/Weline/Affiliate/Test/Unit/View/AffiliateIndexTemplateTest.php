<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class AffiliateIndexTemplateTest extends TestCase
{
    public function testAffiliateIndexTemplateContainsKeyElements(): void
    {
        $path = BP . 'app/code/Weline/Affiliate/view/templates/Backend/Affiliate/Index/index.phtml';
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
        $this->assertStringContainsString('affiliate-admin-page', $content);
    }

    public function testAffiliateIndexTemplateUsesI18n(): void
    {
        $path = BP . 'app/code/Weline/Affiliate/view/templates/Backend/Affiliate/Index/index.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('__(\'Affiliate Management\')', $content);
        $this->assertStringContainsString('__(\'Customer\')', $content);
        $this->assertStringContainsString('customer:admin:select', $content);
        $this->assertStringContainsString('customerSelectValue', $content);
        $this->assertStringContainsString('customerSelectDisplay', $content);
        $this->assertStringContainsString('filterCustomerSelectValue', $content);
        $this->assertStringContainsString('filterCustomerSelectDisplay', $content);
        $this->assertStringContainsString('affiliate-filter-customer-id', $content);
        $this->assertStringContainsString('allow-empty="true"', $content);
        $this->assertStringContainsString('websites:website:select', $content);
        $this->assertStringContainsString('websites:store:select', $content);
        $this->assertStringContainsString('websites:channel:select', $content);
        $this->assertStringContainsString('websiteSelectValue', $content);
        $this->assertStringContainsString('filterWebsiteSelectValue', $content);
        $this->assertStringContainsString('createScopeReady', $content);
        $this->assertStringContainsString('filterScopeReady', $content);
        $this->assertStringContainsString('Select a Website first to confirm scope', $content);
        $this->assertStringContainsString('Customer search unlocks after Website scope is selected.', $content);
        $this->assertStringContainsString('Select Website scope before searching customers.', $content);
        $this->assertStringContainsString('__(\'Scope\')', $content);
        $this->assertStringContainsString('__(\'Referral Code\')', $content);
        $this->assertStringContainsString('__(\'Commission Rate\')', $content);
        $this->assertStringContainsString('__(\'Status\')', $content);
        $this->assertStringContainsString('__(\'Apply Filters\')', $content);
        $this->assertStringContainsString('__(\'Reset\')', $content);
        $this->assertStringContainsString('__(\'Edit\')', $content);
        $this->assertStringContainsString('__(\'View\')', $content);
        $this->assertStringContainsString('__(\'decimal\')', $content);
    }

    public function testAffiliateIndexTemplateHasSummaryCards(): void
    {
        $path = BP . 'app/code/Weline/Affiliate/view/templates/Backend/Affiliate/Index/index.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('summary', $content);
        $this->assertStringContainsString('Total Affiliates', $content);
        $this->assertStringContainsString('Active', $content);
        $this->assertStringContainsString('Disabled', $content);
        $this->assertStringContainsString('Total Commission', $content);
    }

    public function testAffiliateIndexTemplateHasFormAndTableStructure(): void
    {
        $path = BP . 'app/code/Weline/Affiliate/view/templates/Backend/Affiliate/Index/index.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('<form', $content);
        $this->assertStringContainsString('</form>', $content);
        $this->assertStringContainsString('<table', $content);
        $this->assertStringContainsString('</table>', $content);
        $this->assertStringContainsString('affiliate_id', $content);
        $this->assertStringContainsString('customer_id', $content);
        $this->assertStringNotContainsString('<span class="input-group-text">%</span>', $content);
    }

    public function testAffiliateIndexTemplateHasPagination(): void
    {
        $path = BP . 'app/code/Weline/Affiliate/view/templates/Backend/Affiliate/Index/index.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('pageCount', $content);
        $this->assertStringContainsString('currentPage', $content);
        $this->assertStringContainsString('Previous', $content);
        $this->assertStringContainsString('Next', $content);
    }
}
