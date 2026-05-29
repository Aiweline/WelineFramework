<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AffiliateAccountHookTemplateTest extends TestCase
{
    public function testAffiliateUsesAccountCenterHooksInsteadOfStandaloneCardLink(): void
    {
        $summaryCard = BP . 'app/code/WeShop/Affiliate/view/templates/frontend/account/affiliate-summary-card.phtml';
        $workbenchBody = BP . 'app/code/WeShop/Affiliate/view/templates/frontend/account/affiliate-workbench-body.phtml';
        $discoveryHook = BP . 'app/code/WeShop/Affiliate/view/hooks/WeShop_Customer/frontend/account/discovery/cards.phtml';
        $sectionHook = BP . 'app/code/WeShop/Affiliate/view/hooks/WeShop_Affiliate/frontend/account/index/affiliate.phtml';
        $sidebarHook = BP . 'app/code/WeShop/Affiliate/view/hooks/account.sidebar.phtml';
        $sidebarContentHook = BP . 'app/code/WeShop/Affiliate/view/hooks/account.sidebar.content.phtml';
        $englishTranslations = BP . 'app/code/WeShop/Affiliate/i18n/en_US.csv';
        $standaloneThemePage = BP . implode('/', [
            'app/design/WeShop/default/frontend/pages',
            'affiliate',
            'index.phtml',
        ]);

        foreach ([$summaryCard, $workbenchBody, $discoveryHook, $sectionHook, $sidebarHook, $sidebarContentHook, $englishTranslations] as $path) {
            $this->assertFileExists($path);
        }
        $this->assertFileDoesNotExist($standaloneThemePage);

        $summaryContent = (string) file_get_contents($summaryCard);
        $workbenchContent = (string) file_get_contents($workbenchBody);
        $discoveryContent = (string) file_get_contents($discoveryHook);
        $sectionContent = (string) file_get_contents($sectionHook);
        $sidebarContent = (string) file_get_contents($sidebarHook);
        $sidebarSectionContent = (string) file_get_contents($sidebarContentHook);
        $englishTranslationContent = (string) file_get_contents($englishTranslations);

        $this->assertStringContainsString("w_query('affiliate', 'getMySummary')", $summaryContent);
        $this->assertStringContainsString('data-affiliate-account-panel', $summaryContent);
        $this->assertStringContainsString('data-affiliate-referral-link', $summaryContent);
        $this->assertStringContainsString('affiliate-workbench-body.phtml', $summaryContent);
        $this->assertStringContainsString('Weline.Api.resource(\'affiliate\')', $summaryContent);
        $this->assertStringContainsString('getShareLink', $summaryContent);
        $this->assertStringContainsString('data-affiliate-generate-link', $summaryContent);
        $this->assertStringContainsString('data-affiliate-report-search', $summaryContent);
        $this->assertStringContainsString('data-affiliate-report-size', $summaryContent);
        $this->assertStringContainsString('data-affiliate-report-next', $summaryContent);
        $this->assertStringContainsString('data-affiliate-report-empty', $summaryContent);
        $this->assertStringContainsString('data-affiliate-default-share-link', $workbenchContent);
        $this->assertStringContainsString('data-affiliate-share-links-table', $workbenchContent);
        $this->assertStringContainsString('data-affiliate-referred-customers-table', $workbenchContent);
        $this->assertStringContainsString('data-affiliate-products-table', $workbenchContent);
        $this->assertStringContainsString('data-affiliate-orders-table', $workbenchContent);
        $this->assertStringContainsString('data-affiliate-commissions-table', $workbenchContent);
        $this->assertStringContainsString('data-affiliate-withdrawals-table', $workbenchContent);
        $this->assertStringContainsString('data-affiliate-withdrawal-submit', $workbenchContent);
        $this->assertStringNotContainsString('data-account-nav-link="true"', $summaryContent);
        $this->assertStringNotContainsString("getUrl('affiliate')", $discoveryContent);
        $this->assertStringContainsString('affiliate-summary-card.phtml', $discoveryContent);
        $this->assertStringContainsString('Hook: WeShop_Affiliate::frontend::account::index::affiliate', $sectionContent);
        $this->assertStringContainsString('RequestContext::get', $sidebarContent);
        $this->assertStringContainsString('RequestContext::get', $sidebarSectionContent);
        $this->assertStringNotContainsString('$GLOBALS', $sidebarContent);
        $this->assertStringNotContainsString('$GLOBALS', $sidebarSectionContent);
        $this->assertStringContainsString('data-account-nav-link="true"', $sidebarContent);
        $this->assertStringContainsString('data-section="affiliate"', $sidebarContent);
        $this->assertStringContainsString('data-account-section="affiliate"', $sidebarSectionContent);
        $this->assertStringContainsString("__('我的分销')", $sidebarContent);
        $this->assertStringContainsString("__('分享、转化与佣金')", $sidebarContent);
        $this->assertStringContainsString('我的分销,"My Affiliate"', $englishTranslationContent);
        $this->assertStringContainsString('分享、转化与佣金,"Shares, conversions, and commission"', $englishTranslationContent);
    }
}
