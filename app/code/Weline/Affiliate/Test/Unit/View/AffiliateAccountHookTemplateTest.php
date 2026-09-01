<?php

declare(strict_types=1);

namespace Weline\Affiliate\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AffiliateAccountHookTemplateTest extends TestCase
{
    public function testAffiliateUsesAccountCenterHooksInsteadOfStandaloneCardLink(): void
    {
        $summaryCard = BP . 'app/code/Weline/Affiliate/view/templates/frontend/account/affiliate-summary-card.phtml';
        $workbenchBody = BP . 'app/code/Weline/Affiliate/view/templates/frontend/account/affiliate-workbench-body.phtml';
        $discoveryHook = BP . 'app/code/Weline/Affiliate/view/hooks/Weline_Customer/frontend/account/discovery/cards.phtml';
        $sectionHook = BP . 'app/code/Weline/Affiliate/view/hooks/Weline_Affiliate/frontend/account/index/affiliate.phtml';
        $sidebarHook = BP . 'app/code/Weline/Affiliate/view/hooks/account.sidebar.group.commerce.phtml';
        $sidebarContentHook = BP . 'app/code/Weline/Affiliate/view/hooks/account.sidebar.content.phtml';
        $englishTranslations = BP . 'app/code/Weline/Affiliate/i18n/en_US.csv';
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

        $this->assertStringContainsString("w_query('affiliate', 'getMySummary', [], 'frontend')", $summaryContent);
        $this->assertStringContainsString("\$catalogUrl = \$this->getUrl('products')", $summaryContent);
        $this->assertStringContainsString("\$homeUrl = \$this->getUrl('')", $summaryContent);
        $this->assertStringContainsString('data-affiliate-account-panel', $summaryContent);
        $this->assertStringContainsString('data-weline-load="affiliateAccount"', $summaryContent);
        $this->assertStringContainsString('data-affiliate-i18n=', $summaryContent);
        $this->assertStringContainsString('data-affiliate-referral-link', $summaryContent);
        $this->assertStringContainsString('affiliate-workbench-body.phtml', $summaryContent);
        $this->assertStringNotContainsString('<script>', $summaryContent);
        $this->assertStringContainsString('data-affiliate-generate-link', $workbenchContent);
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
        $this->assertStringContainsString('Hook: Weline_Affiliate::frontend::account::index::affiliate', $sectionContent);
        $this->assertStringContainsString('RequestContext::get', $sidebarContent);
        $this->assertStringContainsString('RequestContext::get', $sidebarSectionContent);
        $this->assertStringNotContainsString('$GLOBALS', $sidebarContent);
        $this->assertStringNotContainsString('$GLOBALS', $sidebarSectionContent);
        $this->assertStringContainsString('data-account-nav-link="true"', $sidebarContent);
        $this->assertStringContainsString('data-section="affiliate"', $sidebarContent);
        $this->assertStringContainsString('data-account-nav-parent="commerce"', $sidebarContent);
        $this->assertStringContainsString('data-account-section="affiliate"', $sidebarSectionContent);
        $this->assertStringContainsString('<lang>我的分销</lang>', $sidebarContent);
        $this->assertStringContainsString('<lang>分享、转化与佣金</lang>', $sidebarContent);
        $this->assertStringContainsString('我的分销,"My Affiliate"', $englishTranslationContent);
        $this->assertStringContainsString('分享、转化与佣金,"Shares, conversions, and commission"', $englishTranslationContent);

        $accountJs = BP . 'app/code/Weline/Affiliate/view/statics/js/affiliate-account.js';
        $modulesJs = BP . 'app/code/Weline/Affiliate/view/statics/frontend/weline.modules.js';
        $this->assertFileExists($accountJs);
        $this->assertFileExists($modulesJs);
        $accountJsContent = (string) file_get_contents($accountJs);
        $modulesJsContent = (string) file_get_contents($modulesJs);
        $this->assertStringContainsString('affiliateAccount', $modulesJsContent);
        $this->assertStringContainsString('Weline_Affiliate::js/affiliate-account.js', $modulesJsContent);
        $this->assertStringContainsString('data-affiliate-report-search', $accountJsContent);
        $this->assertStringContainsString('data-affiliate-report-size', $accountJsContent);
        $this->assertStringContainsString('data-affiliate-report-next', $accountJsContent);
        $this->assertStringContainsString('data-affiliate-report-empty', $accountJsContent);
        $this->assertStringContainsString("Weline.Api.resource('affiliate')", $accountJsContent);
        $this->assertStringContainsString('getShareLink', $accountJsContent);
        $this->assertStringContainsString('weline:account-sidebar-content-loaded', $accountJsContent);
    }
}
