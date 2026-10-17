<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Service\MailBrandContextService;
use Weline\Smtp\Service\MailShellHanfuDefaultsService;
use Weline\Smtp\Service\MailShellRegionStore;
use Weline\Smtp\Service\MailTemplateDefaultLocales;
use Weline\Smtp\Service\MailTemplateRenderer;
use Weline\Smtp\Service\MailTemplateResolver;
use Weline\Smtp\Service\MailTemplateSeedCopyCatalog;
use Weline\Smtp\Service\MailTemplateSeedLocaleResolver;
use Weline\Smtp\Service\MailTemplateShellComposer;
use Weline\Smtp\Test\Support\MailTemplateLocaleMatrixRunner;

/**
 * Aggregated contract suite (merged from 21 *ContractTest files).
 * Observe one overall pass/fail via testAllSmtpUnitContractsPass().
 */
final class SmtpUnitContractsTest extends TestCase
{
    public function testAllSmtpUnitContractsPass(): void
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
            'MailBrandContext::testVariableCatalogAndMergePreferCaller' => function () {
            $this->assert_MailBrandContext_testVariableCatalogAndMergePreferCaller();
        },
            'MailBrandContext::testBuildPreviewSamplesLocksUrlsToScopeSite' => function () {
            $this->assert_MailBrandContext_testBuildPreviewSamplesLocksUrlsToScopeSite();
        },
            'MailBrandContext::testBuildPreviewSamplesLocalizesChannelCopyByMailLocale' => function () {
            $this->assert_MailBrandContext_testBuildPreviewSamplesLocalizesChannelCopyByMailLocale();
        },
            'MailBrandContext::testPaletteResolvesThemeHexTokens' => function () {
            $this->assert_MailBrandContext_testPaletteResolvesThemeHexTokens();
        },
            'MailBrandContext::testThemeFrontendRootPrefersActiveDesignTheme' => function () {
            $this->assert_MailBrandContext_testThemeFrontendRootPrefersActiveDesignTheme();
        },
            'MailBrandContext::testRendererStripsScriptAndEvents' => function () {
            $this->assert_MailBrandContext_testRendererStripsScriptAndEvents();
        },
            'MailBrandContext::testResolvePrefersPublicWebsiteDomain' => function () {
            $this->assert_MailBrandContext_testResolvePrefersPublicWebsiteDomain();
        },
            'MailBrandContext::testResolveLogoWalksWebsiteBrandNotThemeDefault' => function () {
            $this->assert_MailBrandContext_testResolveLogoWalksWebsiteBrandNotThemeDefault();
        },
            'MailBrandContext::testResolveInjectsMailShellBackgroundAbsoluteUrls' => function () {
            $this->assert_MailBrandContext_testResolveInjectsMailShellBackgroundAbsoluteUrls();
        },
            'MailBrandContext::testSendPathMergesBrandCodes' => function () {
            $this->assert_MailBrandContext_testSendPathMergesBrandCodes();
        },
            'MailBrandContext::testResolveUsesWebsiteLocalDescriptionForMailLocale' => function () {
            $this->assert_MailBrandContext_testResolveUsesWebsiteLocalDescriptionForMailLocale();
        },
            'MailBrandContext::testArchitectureBrandFromAndSubjectPolicy' => function () {
            $this->assert_MailBrandContext_testArchitectureBrandFromAndSubjectPolicy();
        },
            'MailChannelDefaultTemplates::testSeedCatalogCoversDefaultWebsiteLanguagesInline' => function () {
            $this->assert_MailChannelDefaultTemplates_testSeedCatalogCoversDefaultWebsiteLanguagesInline();
        },
            'MailChannelDefaultTemplates::testMaterializeFilesIsNoOp' => function () {
            $this->assert_MailChannelDefaultTemplates_testMaterializeFilesIsNoOp();
        },
            'MailShellHanfuDefaults::testDefaultsServiceExposesHanfuShellMarkup' => function () {
            $this->assert_MailShellHanfuDefaults_testDefaultsServiceExposesHanfuShellMarkup();
        },
            'MailShellHanfuDefaults::testEnsureDoesNotWriteBackOnEmptyHeader' => function () {
            $this->assert_MailShellHanfuDefaults_testEnsureDoesNotWriteBackOnEmptyHeader();
        },
            'MailShellRegionStore::testMergeRegionHtmlReplacesHeaderAndFooterRows' => function () {
            $this->assert_MailShellRegionStore_testMergeRegionHtmlReplacesHeaderAndFooterRows();
        },
            'MailShellRegionStore::testMergeRegionHtmlKeepsNestedHeaderTableIntact' => function () {
            $this->assert_MailShellRegionStore_testMergeRegionHtmlKeepsNestedHeaderTableIntact();
        },
            'MailShellRegionStore::testMergeRegionHtmlJoinsLegacyFooterRowsIntoSingleSlot' => function () {
            $this->assert_MailShellRegionStore_testMergeRegionHtmlJoinsLegacyFooterRowsIntoSingleSlot();
        },
            'MailShellRegionStore::testClearMethodPersistsEmptyRegionsViaSave' => function () {
            $this->assert_MailShellRegionStore_testClearMethodPersistsEmptyRegionsViaSave();
        },
            'MailSmtpBootstrapDefaultRelay::testBootstrapNoLongerEnsuresMailDefaultSmtp' => function () {
            $this->assert_MailSmtpBootstrapDefaultRelay_testBootstrapNoLongerEnsuresMailDefaultSmtp();
        },
            'MailSmtpBootstrapDefaultRelay::testEnableServiceDnsBeforePoolContract' => function () {
            $this->assert_MailSmtpBootstrapDefaultRelay_testEnableServiceDnsBeforePoolContract();
        },
            'MailTemplateBeauty::testCustomerFacingTemplatesHaveBrandShellAndCta' => function () {
            $this->assert_MailTemplateBeauty_testCustomerFacingTemplatesHaveBrandShellAndCta();
        },
            'MailTemplate::testCollectorPassesVariablesAndDefaultTemplates' => function () {
            $this->assert_MailTemplate_testCollectorPassesVariablesAndDefaultTemplates();
        },
            'MailTemplate::testModelHasUniqueChannelScopeLocale' => function () {
            $this->assert_MailTemplate_testModelHasUniqueChannelScopeLocale();
        },
            'MailTemplate::testServicesExist' => function () {
            $this->assert_MailTemplate_testServicesExist();
        },
            'MailTemplate::testModuleDoesNotRequireCkEditor' => function () {
            $this->assert_MailTemplate_testModuleDoesNotRequireCkEditor();
        },
            'MailTemplateCtaNormalize::testNormalizeRestoresCtaButtonStylesFromCkEditorMarkup' => function () {
            $this->assert_MailTemplateCtaNormalize_testNormalizeRestoresCtaButtonStylesFromCkEditorMarkup();
        },
            'MailTemplateLocaleMatrix::testScriptAndSpecExist' => function () {
            $this->assert_MailTemplateLocaleMatrix_testScriptAndSpecExist();
        },
            'MailTemplateLocaleMatrix::testEnMarkerGateDetectsWelcomeGiftBaseline' => function () {
            $this->assert_MailTemplateLocaleMatrix_testEnMarkerGateDetectsWelcomeGiftBaseline();
        },
            'MailTemplateLocaleMatrix::testShellEnGateDetectsPhoneHoursAddressAndWeekdays' => function () {
            $this->assert_MailTemplateLocaleMatrix_testShellEnGateDetectsPhoneHoursAddressAndWeekdays();
        },
            'MailTemplateLocaleMatrix::testLocalizedTopicsLabelForRuNotEnglishOffers' => function () {
            $this->assert_MailTemplateLocaleMatrix_testLocalizedTopicsLabelForRuNotEnglishOffers();
        },
            'MailTemplateLocaleMatrix::testSampleNonEnLocaleFailsEnPlaceholderUntilTranslated' => function () {
            $this->assert_MailTemplateLocaleMatrix_testSampleNonEnLocaleFailsEnPlaceholderUntilTranslated();
        },
            'MailTemplateLocaleMatrix::testFullMatrixStructureWhenRequested' => function () {
            $this->assert_MailTemplateLocaleMatrix_testFullMatrixStructureWhenRequested();
        },
            'MailTemplateMigrate::testBackendProviderExpandsTopicsWithSharedDefaults' => function () {
            $this->assert_MailTemplateMigrate_testBackendProviderExpandsTopicsWithSharedDefaults();
        },
            'MailTemplateMigrate::testEmailAdapterKeepsChannelAndUsesVars' => function () {
            $this->assert_MailTemplateMigrate_testEmailAdapterKeepsChannelAndUsesVars();
        },
            'MailTemplateMigrate::testOrderMailNotifierAndObservers' => function () {
            $this->assert_MailTemplateMigrate_testOrderMailNotifierAndObservers();
        },
            'MailTemplateMigrate::testDebtCallersUseChannelAndVars' => function () {
            $this->assert_MailTemplateMigrate_testDebtCallersUseChannelAndVars();
        },
            'MailTemplateMigrate::testModuleDefaultTemplateFilesExist' => function () {
            $this->assert_MailTemplateMigrate_testModuleDefaultTemplateFilesExist();
        },
            'MailTemplateMigrate::testProvidersDeclareDefaultTemplates' => function () {
            $this->assert_MailTemplateMigrate_testProvidersDeclareDefaultTemplates();
        },
            'MailTemplateMigrate::testUpgradeSeedsMailTemplates' => function () {
            $this->assert_MailTemplateMigrate_testUpgradeSeedsMailTemplates();
        },
            'MailTemplateResolverFallback::testNonChineseChainInsertsEnUsBeforeSiteDefaultZh' => function () {
            $this->assert_MailTemplateResolverFallback_testNonChineseChainInsertsEnUsBeforeSiteDefaultZh();
        },
            'MailTemplateResolverFallback::testEnGbChainDoesNotSkipEnUs' => function () {
            $this->assert_MailTemplateResolverFallback_testEnGbChainDoesNotSkipEnUs();
        },
            'MailTemplateResolverFallback::testEnUsTargetDoesNotDuplicateEnUs' => function () {
            $this->assert_MailTemplateResolverFallback_testEnUsTargetDoesNotDuplicateEnUs();
        },
            'MailTemplateResolverFallback::testChineseTargetDoesNotForceEnUs' => function () {
            $this->assert_MailTemplateResolverFallback_testChineseTargetDoesNotForceEnUs();
        },
            'MailTemplateResolverFallback::testSiteDefaultOtherThanZhStillEndsWithZhForNonChinese' => function () {
            $this->assert_MailTemplateResolverFallback_testSiteDefaultOtherThanZhStillEndsWithZhForNonChinese();
        },
            'MailTemplateResolverFallback::testResolveUsesLocaleFallbackChainHelper' => function () {
            $this->assert_MailTemplateResolverFallback_testResolveUsesLocaleFallbackChainHelper();
        },
            'MailTemplateShell::testShellPhtmlUsesLangTagsAndWrapsBody' => function () {
            $this->assert_MailTemplateShell_testShellPhtmlUsesLangTagsAndWrapsBody();
        },
            'MailTemplateShell::testShellCsvCoversDefaultWebsiteLocalesWithoutChineseDisclaimer' => function () {
            $this->assert_MailTemplateShell_testShellCsvCoversDefaultWebsiteLocalesWithoutChineseDisclaimer();
        },
            'MailTemplateShell::testNonEnglishShellLabelsPreferSeedNotEnglishFallback' => function () {
            $this->assert_MailTemplateShell_testNonEnglishShellLabelsPreferSeedNotEnglishFallback();
        },
            'MailTemplateUiSend::testBackendUiAndMenuExist' => function () {
            $this->assert_MailTemplateUiSend_testBackendUiAndMenuExist();
        },
            'MailTemplateUiSend::testSendPathUsesTemplateServices' => function () {
            $this->assert_MailTemplateUiSend_testSendPathUsesTemplateServices();
        },
            'MailTemplateUiSend::testRendererEscapesAndRaw' => function () {
            $this->assert_MailTemplateUiSend_testRendererEscapesAndRaw();
        },
            'MailTemplateUiSend::testRendererEvaluatesIfBlocks' => function () {
            $this->assert_MailTemplateUiSend_testRendererEvaluatesIfBlocks();
        },
            'MailTemplateUiSend::testUnpaidOrderReminderTemplateIfCleared' => function () {
            $this->assert_MailTemplateUiSend_testUnpaidOrderReminderTemplateIfCleared();
        },
            'SmtpConfigTabs::testConfigTemplateUsesThemeTabsInsteadOfPagination' => function () {
            $this->assert_SmtpConfigTabs_testConfigTemplateUsesThemeTabsInsteadOfPagination();
        },
            'SmtpMailConfigPage::testMenuAndPageEmbedShellBackgroundGroup' => function () {
            $this->assert_SmtpMailConfigPage_testMenuAndPageEmbedShellBackgroundGroup();
        },
            'SmtpSendLogChannel::testModelAndListingCarryChannel' => function () {
            $this->assert_SmtpSendLogChannel_testModelAndListingCarryChannel();
        },
            'SmtpServiceCatalog::testSmtpServiceModelHasSelfAndExternalModes' => function () {
            $this->assert_SmtpServiceCatalog_testSmtpServiceModelHasSelfAndExternalModes();
        },
            'SmtpServiceCatalog::testCatalogServiceCreatesBothModes' => function () {
            $this->assert_SmtpServiceCatalog_testCatalogServiceCreatesBothModes();
        },
            'SmtpServiceCatalog::testSmtpAccountBelongsToService' => function () {
            $this->assert_SmtpServiceCatalog_testSmtpAccountBelongsToService();
        },
            'SmtpServiceSplit::testSmtpAccountModelExists' => function () {
            $this->assert_SmtpServiceSplit_testSmtpAccountModelExists();
        },
            'SmtpServiceSplit::testConfigUiHasEnableCard' => function () {
            $this->assert_SmtpServiceSplit_testConfigUiHasEnableCard();
        },
            'SmtpServiceSplit::testControllerHasEnableActions' => function () {
            $this->assert_SmtpServiceSplit_testControllerHasEnableActions();
        },
            'SmtpServiceSplit::testBootstrapNeverWaitsOnMailDomain' => function () {
            $this->assert_SmtpServiceSplit_testBootstrapNeverWaitsOnMailDomain();
        },
            'SmtpSiteSetupTask::testSmtpSetupTaskProviderRegistersViaExtends' => function () {
            $this->assert_SmtpSiteSetupTask_testSmtpSetupTaskProviderRegistersViaExtends();
        },
            'SmtpTestSendFeedback::testPostTestReturnsJsonForAjaxAndFrontShowsFeedback' => function () {
            $this->assert_SmtpTestSendFeedback_testPostTestReturnsJsonForAjaxAndFrontShowsFeedback();
        },
            'SmtpWebsiteScopeSettings::testSystemConfigDeclarationUsesWebsiteScope' => function () {
            $this->assert_SmtpWebsiteScopeSettings_testSystemConfigDeclarationUsesWebsiteScope();
        },
            'SmtpWebsiteScopeSettings::testConfigPageUsesTargetScopeAndMenu' => function () {
            $this->assert_SmtpWebsiteScopeSettings_testConfigPageUsesTargetScopeAndMenu();
        },
        ];
    }

private function assert_MailBrandContext_testVariableCatalogAndMergePreferCaller(): void
{
    $codes = MailBrandContextService::variableCodes();
    self::assertContains('site_name', $codes);
    self::assertContains('site_logo_img', $codes);
    self::assertContains('contact_email', $codes);
    self::assertContains('brand_primary', $codes);
    self::assertContains('brand_header_bg', $codes);
    self::assertContains('brand_canvas', $codes);
    $defs = MailBrandContextService::variableDefinitions();
    self::assertNotEmpty($defs);
    self::assertSame('site_name', $defs[0]['code']);
    $svc = new class extends MailBrandContextService
    {
        public function resolve(string $storageScope, string $locale = ''): array
        {
            return ['site_name' => 'FromBrand', 'store_name' => 'StoreA', 'channel_name' => '', 'brand_display_name' => 'StoreA', 'site_url' => 'https://example.com', 'site_logo_url' => 'https://example.com/logo.png', 'site_logo_img' => '<img src="https://example.com/logo.png" alt="StoreA">', 'site_description' => 'desc', 'contact_email' => 'a@example.com', 'contact_phone' => '', 'contact_address' => '', 'service_hours' => '9-18'];
        }
    };
    $merged = $svc->mergeInto(['site_name' => 'CallerWins', 'reset_url' => 'https://x'], 'shop.default.default');
    self::assertSame('CallerWins', $merged['site_name']);
    self::assertSame('StoreA', $merged['store_name']);
    self::assertSame('https://x', $merged['reset_url']);
}

private function assert_MailBrandContext_testBuildPreviewSamplesLocksUrlsToScopeSite(): void
{
    $svc = new class extends MailBrandContextService
    {
        public function resolve(string $storageScope, string $locale = ''): array
        {
            return ['site_name' => '默认网站', 'store_name' => '默认店铺', 'channel_name' => '', 'brand_display_name' => '默认店铺', 'site_url' => 'https://p05113ef3.test.weline.com:9555', 'site_logo_url' => 'https://p05113ef3.test.weline.com:9555/logo.png', 'site_logo_img' => '<img src="https://p05113ef3.test.weline.com:9555/logo.png" alt="x">', 'site_description' => 'desc', 'contact_email' => 'support@shop.local', 'contact_phone' => '', 'contact_address' => '', 'service_hours' => '9-18'];
        }
    };
    $samples = $svc->buildPreviewSamples([['code' => 'reset_url', 'sample' => 'https://example.com/reset?token=...'], ['code' => 'customer_email', 'sample' => 'user@example.com'], ['code' => 'site_url', 'sample' => 'https://example.com'], ['code' => 'verification_url', 'sample' => 'https://example.com/bind/verify?token=...'], ['code' => 'relative_path', 'sample' => '/customer/account/forgot-password']], 'default.default.default');
    self::assertSame('https://p05113ef3.test.weline.com:9555/reset?token=preview', $samples['reset_url']);
    self::assertSame('support@shop.local', $samples['customer_email']);
    self::assertSame('https://p05113ef3.test.weline.com:9555', $samples['site_url']);
    self::assertSame('https://p05113ef3.test.weline.com:9555/bind/verify?token=preview', $samples['verification_url']);
    self::assertSame('https://p05113ef3.test.weline.com:9555/customer/account/forgot-password', $samples['relative_path']);
    self::assertStringNotContainsString('example.com', $samples['reset_url']);
}

private function assert_MailBrandContext_testBuildPreviewSamplesLocalizesChannelCopyByMailLocale(): void
{
    $svc = new class extends MailBrandContextService
    {
        public function resolve(string $storageScope, string $locale = ''): array
        {
            return ['site_name' => 'Store', 'store_name' => 'Store', 'channel_name' => '', 'brand_display_name' => 'Store', 'site_url' => 'https://p05113ef3.test.weline.com:9555', 'site_logo_url' => '', 'site_logo_img' => '', 'site_description' => '官方商城客户服务', 'contact_email' => 'support@shop.local', 'contact_phone' => '', 'contact_address' => '', 'service_hours' => '周一至周五 9:00 - 18:00'];
        }
    };
    $samples = $svc->buildPreviewSamples([['code' => 'title', 'sample' => '系统通知'], ['code' => 'type_label', 'sample' => '信息'], ['code' => 'content', 'sample' => '通知内容'], ['code' => 'topic_code', 'sample' => 'system_info']], 'default.default.default', 'en_US');
    self::assertSame('System notification', $samples['title']);
    self::assertSame('Information', $samples['type_label']);
    self::assertSame('Notification content', $samples['content']);
    self::assertSame('system_info', $samples['topic_code']);
    $brandSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailBrandContextService.php');
    self::assertStringContainsString('localizePreviewText', $brandSrc);
    self::assertStringContainsString('buildPreviewSamples(array $variables, string $storageScope, string $locale', $brandSrc);
    $controllerSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Controller/Backend/Template.php');
    self::assertStringContainsString('buildPreviewSamples($mergedVars, $editScope, $editLocale)', $controllerSrc);
}

private function assert_MailBrandContext_testPaletteResolvesThemeHexTokens(): void
{
    $svc = new MailBrandContextService();
    $palette = $svc->resolvePalette('default.default.default');
    self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['brand_primary']);
    self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['brand_header_bg']);
    self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['brand_canvas']);
    // 对齐 Theme ink/朱砂或激活 design（hanfu-paper），而非历史 Ink Harbor 蓝绿琥珀
    self::assertNotSame('#16333f', $palette['brand_header_bg']);
    self::assertNotSame('#e8a14a', $palette['brand_accent']);
    self::assertContains($palette['brand_primary'], ['#b84a3c', '#a44535']);
}

private function assert_MailBrandContext_testThemeFrontendRootPrefersActiveDesignTheme(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailBrandContextService.php');
    self::assertStringContainsString("app/design/", $src);
    self::assertStringContainsString('--mail-header-text', $src);
    self::assertStringContainsString('hanfu-paper', $src);
    $svc = new MailBrandContextService();
    $palette = $svc->resolvePalette('default.default.default');
    // 本机激活 hanfu 时页头字取 --mail-header-text 朱砂橙
    if (is_dir('/Users/weline/Project/Official/框架/app' . '/design/Weline/hanfu/frontend')) {
        $themePath = '';
        try {
            $theme = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
            $theme->clearData()->clearQuery()->getActiveTheme('frontend');
            $themePath = (string) $theme->getData('path');
        } catch (\Throwable) {
        }
        if (str_contains(strtolower($themePath), 'hanfu')) {
            self::assertSame('#ff5d05', $palette['brand_header_text']);
            self::assertSame('#e91b0c', $palette['brand_header_muted']);
            self::assertSame('#16181a', $palette['brand_header_bg']);
        }
    }
}

private function assert_MailBrandContext_testRendererStripsScriptAndEvents(): void
{
    $r = new MailTemplateRenderer();
    $out = $r->stripScripts('<p onclick="alert(1)">a</p><script>evil()</script><a href="javascript:alert(1)">x</a>');
    self::assertStringNotContainsString('<script', $out);
    self::assertStringNotContainsString('onclick', $out);
    self::assertStringNotContainsString('javascript:', strtolower($out));
}

private function assert_MailBrandContext_testResolvePrefersPublicWebsiteDomain(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailBrandContextService.php');
    self::assertStringContainsString('function resolvePublicSiteUrl', $src);
    self::assertStringContainsString('test.weline.com', $src);
    self::assertStringContainsString('WebsiteDomain', $src);
    self::assertStringContainsString('function normalizeContactEmail', $src);
    $svc = new MailBrandContextService();
    $ref = new \ReflectionClass($svc);
    $score = $ref->getMethod('scorePublicHost');
    $score->setAccessible(true);
    self::assertGreaterThan((int) $score->invoke($svc, 'p05113ef3.weline.test', true, true), (int) $score->invoke($svc, 'p05113ef3.test.weline.com', false, true));
    self::assertLessThan(0, (int) $score->invoke($svc, 'localhost', true, false));
    self::assertLessThan(0, (int) $score->invoke($svc, 'e2e-test-1783791034.weline.test', true, true));
    self::assertStringContainsString('isUndeliverableMailPublicHost', $src);
    self::assertStringContainsString('resolveManagedLocalPublicSiteUrl', $src);
    $resolved = $svc->resolve('default.default.default');
    self::assertNotSame('http://localhost', $resolved['site_url']);
    self::assertStringNotContainsString('example.com', (string) $resolved['contact_email']);
    self::assertStringNotContainsString('://localhost/', (string) $resolved['site_logo_url']);
    self::assertMatchesRegularExpression('#^https?://#i', (string) $resolved['site_logo_url']);
    self::assertStringNotContainsString('/theme/frontend/assets/images/theme/logo.', (string) $resolved['site_logo_url']);
    self::assertStringContainsString('/pub/media/', (string) $resolved['site_logo_url']);
    if (str_contains((string) $resolved['site_url'], 'test.weline.com') || str_contains((string) $resolved['site_url'], 'weline.test')) {
        self::assertStringContainsString('p05113ef3', (string) $resolved['site_url']);
        // CLI/Cron 无 HTTP_HOST 时也必须带本机 HTTPS 端口，否则邮件里 logo 裂图
        self::assertMatchesRegularExpression('#:(?:[1-9][0-9]{2,4})(/|$)#', (string) $resolved['site_url']);
        self::assertMatchesRegularExpression('#:(?:[1-9][0-9]{2,4})/#', (string) $resolved['site_logo_url']);
    }
}

private function assert_MailBrandContext_testResolveLogoWalksWebsiteBrandNotThemeDefault(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailBrandContextService.php');
    self::assertStringContainsString('function resolvePublishedBrandLogoPath', $src);
    self::assertStringContainsString('fallbackStorageScopes', $src);
    self::assertStringContainsString('isThemePackageDefaultLogo', $src);
    self::assertStringContainsString('WEBSITE_DEFAULT_SENTINEL', $src);
    $resolved = (new MailBrandContextService())->resolve('default.default.default');
    self::assertStringContainsString('/pub/media/websites/', (string) $resolved['site_logo_url']);
    self::assertStringNotContainsString('theme/logo.svg', (string) $resolved['site_logo_url']);
    self::assertStringContainsString('<img', (string) $resolved['site_logo_img']);
}

private function assert_MailBrandContext_testResolveInjectsMailShellBackgroundAbsoluteUrls(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailBrandContextService.php');
    self::assertStringContainsString('resolveMailShellBackgrounds', $src);
    self::assertStringContainsString('brand_header_bg_image', $src);
    self::assertStringContainsString('getMailShellBackgrounds', $src);
    $resolved = (new MailBrandContextService())->resolve('default.default.default');
    self::assertArrayHasKey('brand_header_bg_image', $resolved);
    self::assertArrayHasKey('brand_header_bg_css', $resolved);
    self::assertArrayHasKey('brand_body_bg_image', $resolved);
    self::assertArrayHasKey('brand_footer_bg_image', $resolved);
    self::assertStringContainsString('background-color:', (string) $resolved['brand_header_bg_css']);
    // 已配置壳背景时须注入绝对 /pub/media URL（邮件客户端直链原图）
    if (trim((string) $resolved['brand_header_bg_image']) !== '') {
        self::assertStringContainsString('/pub/media/', (string) $resolved['brand_header_bg_image']);
        self::assertMatchesRegularExpression('#^https?://#i', (string) $resolved['brand_header_bg_image']);
        self::assertStringContainsString('background-image:url(', (string) $resolved['brand_header_bg_css']);
    }
}

private function assert_MailBrandContext_testSendPathMergesBrandCodes(): void
{
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
    self::assertStringContainsString('MailBrandContextService', $provider);
    self::assertStringContainsString('mergeInto', $provider);
    self::assertStringContainsString('variableCodes', $provider);
    self::assertStringContainsString('resolveFromDisplayName', $provider);
    self::assertStringContainsString('ensureBrandedSubject', $provider);
    $brandSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailBrandContextService.php');
    self::assertStringContainsString('brand_display_name', $brandSrc);
    self::assertStringContainsString('function ensureBrandedSubject', $brandSrc);
    self::assertStringContainsString('function resolveFromDisplayName', $brandSrc);
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Controller/Backend/Template.php');
    self::assertStringContainsString('variableDefinitions', $controller);
}

private function assert_MailBrandContext_testResolveUsesWebsiteLocalDescriptionForMailLocale(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailBrandContextService.php');
    self::assertStringContainsString('resolveWebsiteLocalFields', $src);
    self::assertStringContainsString('WebsiteLocalDescription', $src);
    self::assertStringContainsString('isChineseLocale', $src);
    $dataSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Websites/Data/WebsiteData.php');
    self::assertStringContainsString('function getDescriptionForLocale', $dataSrc);
    $seedSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Websites/Service/WebsiteBrandIdentitySeedService.php');
    self::assertStringContainsString('ensureDefaultWebsiteLocalBrandCopy', $seedSrc);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline' . '/Websites/Service/data/website-brand-local-copy.v1.php');
}

private function assert_MailBrandContext_testArchitectureBrandFromAndSubjectPolicy(): void
{
    $svc = new class extends MailBrandContextService
    {
        public function resolve(string $storageScope, string $locale = ''): array
        {
            return ['site_name' => '长安汉服', 'store_name' => '长安汉服 · Hanfu Atelier', 'channel_name' => '', 'brand_display_name' => '长安汉服 · Hanfu Atelier', 'site_url' => 'https://example.com', 'site_logo_url' => '', 'site_logo_img' => '', 'site_description' => '', 'contact_email' => '', 'contact_phone' => '', 'contact_address' => '', 'service_hours' => ''];
        }
    };
    self::assertSame('长安汉服 · Hanfu Atelier', $svc->resolveFromDisplayName('default', 'default', 'aiweline@qq.com', 'default.default.default'));
    self::assertSame('自定义店', $svc->resolveFromDisplayName('自定义店', 'default', 'aiweline@qq.com', 'default.default.default'));
    $brand = ['brand_display_name' => '长安汉服 · Hanfu Atelier'];
    self::assertSame('长安汉服 · Hanfu Atelier · 订单已支付', $svc->ensureBrandedSubject('订单已支付', $brand));
    self::assertSame('长安汉服 · Hanfu Atelier · 订阅礼遇', $svc->ensureBrandedSubject('长安汉服 · Hanfu Atelier · 订阅礼遇', $brand));
    self::assertSame('', $svc->ensureBrandedSubject('', $brand));
}

/**
 * @return list<array{0:string,1:string,2:list<string>}>
 */
private function providerModules_MailChannelDefaultTemplates(): array
{
    $zhOnly = static function (string $dir): array {
        return ["view/email/{$dir}/zh_Hans_CN.html", "view/email/{$dir}/zh_Hans_CN.subject.txt"];
    };
    return [['Customer', 'extends/MailChannelProvider.php', $zhOnly('password_reset')], ['CustomerService', 'extends/MailChannelProvider.php', $zhOnly('email_binding')], ['Dropship', 'extends/MailChannelProvider.php', $zhOnly('fulfillment_consolation')], ['Order', 'extends/MailChannelProvider.php', array_merge($zhOnly('order_created'), $zhOnly('order_paid'), $zhOnly('order_status_changed'), $zhOnly('order_shipped'), $zhOnly('order_refund'))], ['Marketing', 'extends/MailChannelProvider.php', array_merge($zhOnly('unpaid_order_reminder'), $zhOnly('checkout_abandon_reminder'))], ['Backend', 'Extends/MailChannelProvider.php', $zhOnly('notification')], ['Product', 'extends/MailChannelProvider.php', array_merge($zhOnly('product_update'), $zhOnly('quote_reply'))], ['Websites', 'extends/MailChannelProvider.php', $zhOnly('notification')], ['Visitor', 'extends/MailChannelProvider.php', $zhOnly('notification')]];
}

private function assert_MailChannelDefaultTemplates_testSeedCatalogCoversDefaultWebsiteLanguagesInline(): void
{
    $maintained = MailTemplateSeedCopyCatalog::maintainedLocales();
    self::assertContains('zh_Hans_CN', $maintained);
    self::assertContains('en_US', $maintained);
    foreach (['ar_SA', 'bn_BD', 'es_ES', 'fr_FR', 'hi_IN', 'id_ID', 'pt_BR', 'ur_PK', 'en_US', 'de_DE'] as $locale) {
        self::assertContains($locale, $maintained);
        $copy = MailTemplateSeedCopyCatalog::forSlug('order_created', $locale);
        self::assertNotNull($copy, $locale);
        self::assertNotSame('', $copy['subject'] ?? '');
        self::assertStringContainsString('{{var.order_number}}', $copy['subject']);
        self::assertStringContainsString('{{var.customer_name}}', $copy['body']);
    }
    self::assertNull(MailTemplateSeedCopyCatalog::forSlug('order_created', 'zh_Hans_CN'));
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/view/email/shell.phtml');
    $shellPhtml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/view/email/shell.phtml');
    self::assertStringContainsString('<lang>需要帮助？</lang>', $shellPhtml);
    $resolved = MailTemplateSeedLocaleResolver::forDefaultWebsite();
    foreach (MailTemplateSeedCopyCatalog::baselineLocales() as $baseline) {
        self::assertContains($baseline, $resolved);
    }
    foreach ($resolved as $locale) {
        self::assertContains($locale, $maintained, 'maintainedLocales must cover default-website locale ' . $locale);
    }
    $entries = MailTemplateDefaultLocales::fileEntries('password_reset');
    self::assertNotEmpty($entries);
    self::assertSame('zh_Hans_CN', $entries[0]['locale'] ?? '');
    self::assertArrayHasKey('subject_file', $entries[0]);
    $en = null;
    foreach ($entries as $row) {
        if (($row['locale'] ?? '') === 'en_US') {
            $en = $row;
            break;
        }
    }
    self::assertNotNull($en);
    self::assertArrayHasKey('subject', $en);
    self::assertArrayHasKey('body', $en);
    self::assertArrayNotHasKey('subject_file', $en);
}

private function assert_MailChannelDefaultTemplates_testMaterializeFilesIsNoOp(): void
{
    $stats = MailTemplateSeedCopyCatalog::materializeFiles();
    self::assertSame(0, $stats['written']);
    self::assertSame(0, $stats['skipped']);
}

private function assert_MailShellHanfuDefaults_testDefaultsServiceExposesHanfuShellMarkup(): void
{
    $svc = new MailShellHanfuDefaultsService();
    $header = $svc->defaultHeaderHtml();
    $footer = $svc->defaultFooterHtml();
    self::assertStringContainsString('{{var.brand_header_text}}', $header);
    self::assertStringContainsString('{{var.brand_display_name}}', $header);
    self::assertStringContainsString('{{var.site_description}}', $header);
    self::assertStringNotContainsString('!important', $header);
    self::assertStringContainsString('{{var.brand_footer_heading}}', $footer);
    self::assertStringContainsString('{{var.site_url}}', $footer);
    self::assertStringNotContainsString('p05113ef3', $footer);
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Setup/Upgrade.php');
    self::assertStringContainsString('MailShellHanfuDefaultsService', $src);
    self::assertStringContainsString('setMailShellBackgrounds', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Helper/Data.php'));
}

private function assert_MailShellHanfuDefaults_testEnsureDoesNotWriteBackOnEmptyHeader(): void
{
    $svcSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailShellHanfuDefaultsService.php');
    // 禁止「空 header 即写回」：needHeader 不得用 header === '' 触发
    self::assertDoesNotMatchRegularExpression('/\$needHeader\s*=\s*\$force\s*\|\|\s*trim\(\(string\)\(\$current\[\'header\'\]/s', $svcSrc);
    self::assertStringNotContainsString("trim((string)(\$current['header'] ?? '')) === ''", $svcSrc);
    self::assertStringContainsString("str_contains(\$headerHtml, '!important')", $svcSrc);
    self::assertStringContainsString("str_contains(\$headerHtml, '#ff5d05')", $svcSrc);
    self::assertStringContainsString('使用主题/模块 shell.phtml', $svcSrc);
}

private function assert_MailShellRegionStore_testMergeRegionHtmlReplacesHeaderAndFooterRows(): void
{
    $shell = '<table><tr><td data-weline-mail-region="header">OLD-H</td></tr>' . '<tr><td data-weline-mail-region="body">{{MAIL_BODY}}</td></tr>' . '<tr><td data-weline-mail-region="footer">OLD-F1</td></tr>' . '<tr><td data-weline-mail-region="footer">OLD-F2</td></tr></table>';
    $out = MailShellRegionStore::mergeRegionHtml($shell, ['header' => '<strong>NEW-H</strong>', 'footer' => ['<p>F1</p>', '<p>F2</p>']]);
    self::assertStringContainsString('<strong>NEW-H</strong>', $out);
    self::assertStringContainsString('<p>F1</p>', $out);
    self::assertStringContainsString('<p>F2</p>', $out);
    self::assertStringNotContainsString('OLD-H', $out);
    self::assertStringNotContainsString('OLD-F1', $out);
}

private function assert_MailShellRegionStore_testMergeRegionHtmlKeepsNestedHeaderTableIntact(): void
{
    $shell = '<table width="600"><tr>' . '<td bgcolor="#16181a" data-weline-mail-region="header">' . '<table width="100%"><tr>' . '<td align="left">LOGO-OLD</td>' . '<td align="right">TEXT-OLD</td>' . '</tr></table>' . '</td></tr></table>';
    $newInner = '<table width="100%"><tr>' . '<td align="left">LOGO-NEW</td>' . '<td align="right">TEXT-NEW</td>' . '</tr></table>';
    $out = MailShellRegionStore::mergeRegionHtml($shell, ['header' => $newInner]);
    self::assertStringContainsString('LOGO-NEW', $out);
    self::assertStringContainsString('TEXT-NEW', $out);
    self::assertStringNotContainsString('LOGO-OLD', $out);
    self::assertStringNotContainsString('TEXT-OLD', $out);
    // 壳行仅 1 个区域 TD + 内表 2 列；开闭平衡，无残留旧右栏挤成兄弟格
    self::assertSame(3, preg_match_all('/<td\b/i', $out));
    self::assertSame(3, preg_match_all('/<\/td>/i', $out));
    self::assertSame(1, substr_count(strtolower($out), 'data-weline-mail-region="header"'));
    self::assertSame('<table width="600"><tr><td bgcolor="#16181a" data-weline-mail-region="header">' . $newInner . '</td></tr></table>', $out);
}

private function assert_MailShellRegionStore_testMergeRegionHtmlJoinsLegacyFooterRowsIntoSingleSlot(): void
{
    $shell = '<table><tr><td data-weline-mail-region="footer">OLD</td></tr></table>';
    $out = MailShellRegionStore::mergeRegionHtml($shell, ['footer' => ['<p>HELP</p>', '<p>DISCLAIMER</p>']]);
    self::assertSame(1, substr_count(strtolower($out), 'data-weline-mail-region="footer"'));
    self::assertStringContainsString('<p>HELP</p>', $out);
    self::assertStringContainsString('<p>DISCLAIMER</p>', $out);
    self::assertStringNotContainsString('OLD', $out);
}

private function assert_MailShellRegionStore_testClearMethodPersistsEmptyRegionsViaSave(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailShellRegionStore.php');
    self::assertStringContainsString('function clear(string $storageScope)', $src);
    self::assertMatchesRegularExpression('/function clear\(string \$storageScope\): void\s*\{[^}]*\$this->save\(\$storageScope,\s*\'\',\s*\[\]\)/s', $src);
}

private function assert_MailSmtpBootstrapDefaultRelay_testBootstrapNoLongerEnsuresMailDefaultSmtp(): void
{
    $path = BP . '/app/code/Weline/Smtp/Service/MailSmtpBootstrapService.php';
    $src = (string) \file_get_contents($path);
    $body = $this->bootstrapEnsureBody_MailSmtpBootstrapDefaultRelay($src);
    self::assertStringNotContainsString("'ensureDefaultSmtpAccount'", $body);
    self::assertStringContainsString('邮局与 Smtp 已拆分', $body);
}

private function assert_MailSmtpBootstrapDefaultRelay_testEnableServiceDnsBeforePoolContract(): void
{
    $path = BP . '/app/code/Weline/Smtp/Service/SmtpServiceEnableService.php';
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('assertDnsResolved', $src);
    self::assertStringContainsString('ensurePublicDnsHostnamesInPool', $src);
    self::assertStringContainsString('request_certificate', $src);
    self::assertStringContainsString('normalizeDomainInput', $src);
}

private function bootstrapEnsureBody_MailSmtpBootstrapDefaultRelay(string $src): string
{
    $start = strpos($src, 'function ensureDefaultContactPath');
    self::assertNotFalse($start);
    $end = strpos($src, 'function ', $start + 10);
    if ($end === false) {
        $end = strlen($src);
    }
    return substr($src, $start, $end - $start);
}

private function assert_MailTemplateBeauty_testCustomerFacingTemplatesHaveBrandShellAndCta(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $shell = (string) file_get_contents($root . '/app/code/Weline/Smtp/view/email/shell.phtml');
    self::assertStringContainsString('{{var.brand_header_bg}}', $shell);
    self::assertStringContainsString('{{var.brand_accent}}', $shell);
    self::assertStringContainsString('{{var.brand_canvas}}', $shell);
    self::assertStringNotContainsString('#16333f', $shell);
    self::assertStringNotContainsString('#e8a14a', $shell);
    self::assertStringContainsString('{{var.site_logo_img|raw}}', $shell);
    self::assertStringContainsString('{{var.contact_email}}', $shell);
    self::assertStringContainsString('{{MAIL_BODY}}', $shell);
    self::assertStringContainsString('<lang>需要帮助？</lang>', $shell);
    $files = [$root . '/app/code/Weline/Customer/view/email/password_reset/zh_Hans_CN.html', $root . '/app/code/Weline/CustomerService/view/email/email_binding/zh_Hans_CN.html', $root . '/app/code/Weline/Dropship/view/email/fulfillment_consolation/zh_Hans_CN.html', $root . '/app/code/Weline/Order/view/email/order_created/zh_Hans_CN.html', $root . '/app/code/Weline/Backend/view/email/notification/zh_Hans_CN.html'];
    foreach ($files as $file) {
        self::assertFileExists($file, $file);
        $html = (string) file_get_contents($file);
        self::assertStringNotContainsString('<!DOCTYPE', $html, $file);
        self::assertStringNotContainsString('site_logo_img', $html, $file);
        self::assertStringNotContainsString('<script', strtolower($html), $file);
    }
    $reset = (string) file_get_contents($files[0]);
    self::assertStringContainsString('{{var.reset_url}}', $reset);
    self::assertStringContainsString('立即重置密码', $reset);
    self::assertStringContainsString('text-decoration:none', $reset);
}

private function assert_MailTemplate_testCollectorPassesVariablesAndDefaultTemplates(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $collector = (string) file_get_contents($moduleRoot . '/Service/MailChannelCollector.php');
    $interface = (string) file_get_contents($moduleRoot . '/Api/MailChannelProviderInterface.php');
    self::assertStringContainsString('variables', $interface);
    self::assertStringContainsString('default_templates', $interface);
    self::assertStringContainsString("'variables'", $collector);
    self::assertStringContainsString("'default_templates'", $collector);
}

private function assert_MailTemplate_testModelHasUniqueChannelScopeLocale(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $model = (string) file_get_contents($moduleRoot . '/Model/SmtpMailTemplate.php');
    self::assertStringContainsString("schema_fields_CHANNEL_CODE = 'channel_code'", $model);
    self::assertStringContainsString("schema_fields_STORAGE_SCOPE = 'storage_scope'", $model);
    self::assertStringContainsString("schema_fields_LOCALE = 'locale'", $model);
    self::assertStringContainsString("schema_fields_USE_DEFAULT = 'use_default'", $model);
    self::assertStringContainsString("schema_fields_SEED_HASH = 'seed_hash'", $model);
    self::assertStringContainsString('uk_smtp_mail_template_channel_scope_locale', $model);
    self::assertStringContainsString("type: 'UNIQUE'", $model);
}

private function assert_MailTemplate_testServicesExist(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    self::assertFileExists($moduleRoot . '/Service/MailTemplateRenderer.php');
    self::assertFileExists($moduleRoot . '/Service/MailTemplateResolver.php');
    self::assertFileExists($moduleRoot . '/Service/MailTemplateSeeder.php');
    $renderer = (string) file_get_contents($moduleRoot . '/Service/MailTemplateRenderer.php');
    self::assertStringContainsString('strip_tags', $renderer);
    self::assertStringContainsString('|raw', $renderer);
    self::assertStringContainsString('htmlspecialchars', $renderer);
}

private function assert_MailTemplate_testModuleDoesNotRequireCkEditor(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/etc/module.php';
    self::assertIsArray($module);
    self::assertArrayNotHasKey('Weline_EditorManager', $module['requires'] ?? []);
    self::assertArrayNotHasKey('Weline_CKEditorEditorManager', $module['requires'] ?? []);
}

private function assert_MailTemplateCtaNormalize_testNormalizeRestoresCtaButtonStylesFromCkEditorMarkup(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailTemplateRenderer.php');
    self::assertStringContainsString('function normalizeEmailHtml', $src);
    self::assertStringContainsString('ensureCtaAnchorStyle', $src);
    $edit = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/view/backend/Template/edit.phtml');
    self::assertStringContainsString('function normalizeEmailBody', $edit);
    self::assertStringContainsString('normalizeEmailBody(readBodyFromIframe())', $edit);
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Controller/Backend/Template.php');
    self::assertStringContainsString('normalizeEmailHtml', $controller);
    $ck = '<h1>重置您的账户密码</h1>' . '<figure class="table"><table><tbody><tr>' . '<td style="background-color:#e8a14a;border-color:#d48f3a;text-align:center;">' . '<a href="{{var.reset_url}}"><strong>立即重置密码</strong></a>' . '</td></tr></tbody></table></figure>';
    $out = (new MailTemplateRenderer())->normalizeEmailHtml($ck);
    self::assertStringNotContainsString('<figure', $out);
    self::assertMatchesRegularExpression('/bgcolor=["\']#e8a14a["\']/i', $out);
    self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*display:\s*inline-block/i', $out);
    self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*padding:\s*14px 26px/i', $out);
    self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*text-decoration:\s*none/i', $out);
    self::assertMatchesRegularExpression('/<a[^>]*style="[^"]*color:\s*#16333f/i', $out);
}

private function assert_MailTemplateLocaleMatrix_testScriptAndSpecExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    self::assertFileExists($root . '/scripts/mail-template-locale-matrix.php');
    self::assertFileExists($root . '/doc/开发/spec/mail-template-locale-matrix.md');
    self::assertFileExists($root . '/test/Support/MailTemplateLocaleMatrixRunner.php');
}

private function assert_MailTemplateLocaleMatrix_testEnMarkerGateDetectsWelcomeGiftBaseline(): void
{
    $visible = 'ChangAn Hanfu · Welcome gift Thanks for subscribing, guest@example.com!';
    $hits = MailTemplateLocaleMatrixRunner::findEnMarkers($visible, ['Welcome gift', 'Thanks for subscribing']);
    self::assertContains('Welcome gift', $hits);
    self::assertContains('Thanks for subscribing', $hits);
}

private function assert_MailTemplateLocaleMatrix_testShellEnGateDetectsPhoneHoursAddressAndWeekdays(): void
{
    $leaky = 'Нужна помощь? Phone: +1-555 Hours: Monday to Friday 9:00 AM - 6:00 PM Address: Street';
    $hits = MailTemplateLocaleMatrixRunner::findShellEnLabels($leaky);
    self::assertContains('Phone:', $hits);
    self::assertContains('Hours:', $hits);
    self::assertContains('Address:', $hits);
    self::assertContains('Monday to Friday', $hits);
    $cleanRu = 'Нужна помощь? Телефон: +7 Часы работы: Пн–Пт Адрес: Москва';
    self::assertSame([], MailTemplateLocaleMatrixRunner::findShellEnLabels($cleanRu));
    // brand URL 不得因 path 含 phone/hours 误杀（无冒号标签）
    $brandUrlOnly = 'Visit https://brand.example/support/phone/hours/address now';
    self::assertSame([], MailTemplateLocaleMatrixRunner::findShellEnLabels($brandUrlOnly));
}

private function assert_MailTemplateLocaleMatrix_testLocalizedTopicsLabelForRuNotEnglishOffers(): void
{
    $ru = MailTemplateLocaleMatrixRunner::localizedTopicsLabel('ru_RU');
    self::assertStringNotContainsString('Offers', $ru);
    self::assertStringNotContainsString('New arrivals', $ru);
    self::assertNotSame('Offers / New arrivals', $ru);
    $samples = MailTemplateLocaleMatrixRunner::latinSamplesFromChannel(['variables' => [['code' => 'topics_label', 'sample' => 'Offers / New arrivals']]], 'ru_RU');
    self::assertSame($ru, $samples['topics_label']);
}

private function assert_MailTemplateLocaleMatrix_testSampleNonEnLocaleFailsEnPlaceholderUntilTranslated(): void
{
    try {
        $probe = MailTemplateLocaleMatrixRunner::run(['channel' => 'Weline_Newsletter::subscribe_gift', 'quiet' => true]);
    } catch (\Throwable $e) {
        self::markTestSkipped('matrix runner unavailable in this phpunit DB: ' . $e->getMessage());
    }
    $available = is_array($probe['meta']['locales'] ?? null) ? $probe['meta']['locales'] : [];
    $sampleLocale = in_array('de_DE', $available, true) ? 'de_DE' : '';
    if ($sampleLocale === '') {
        foreach ($available as $code) {
            $code = (string) $code;
            if (!MailTemplateLocaleMatrixRunner::isChineseLocale($code) && !MailTemplateLocaleMatrixRunner::isEnglishLocale($code)) {
                $sampleLocale = $code;
                break;
            }
        }
    }
    $expectGreen = getenv('MATRIX_EXPECT_GREEN') === '1';
    if ($sampleLocale === '') {
        if ($expectGreen) {
            self::markTestSkipped('MATRIX_EXPECT_GREEN=1 but phpunit DB has no non-en locale; rely on CLI matrix-test-rerun.json');
        }
        self::markTestSkipped('no non-zh/non-en locale available for sample matrix cell');
    }
    $result = MailTemplateLocaleMatrixRunner::run(['channel' => 'Weline_Newsletter::subscribe_gift', 'locale' => $sampleLocale, 'quiet' => true]);
    self::assertSame(1, (int) $result['meta']['locale_count']);
    self::assertSame(1, (int) $result['meta']['channel_count']);
    self::assertSame(1, (int) $result['counts']['total']);
    self::assertTrue(!empty($result['meta']['shell_en_gate']));
    if ($expectGreen) {
        self::assertSame('pass', $result['verdict'], 'MATRIX_EXPECT_GREEN=1 but ' . $sampleLocale . '×subscribe_gift still red');
        return;
    }
    if ($result['verdict'] === 'pass') {
        self::markTestIncomplete($sampleLocale . '×subscribe_gift already green — set MATRIX_EXPECT_GREEN=1 when translation wave closes');
    }
    self::assertSame('fail', $result['verdict']);
    $reasons = (string) ($result['failures'][0]['reason'] ?? '');
    self::assertTrue(str_contains($reasons, 'en_placeholder') || str_contains($reasons, 'shell_en_leak') || str_contains($reasons, 'cjk') || str_contains($reasons, 'uc1_'), 'expected en_placeholder/shell_en_leak/cjk/uc1 fail, got: ' . $reasons);
}

private function assert_MailTemplateLocaleMatrix_testFullMatrixStructureWhenRequested(): void
{
    if (getenv('MATRIX_FULL') !== '1') {
        self::markTestSkipped('Set MATRIX_FULL=1 to run exhaustive 40×36 matrix in PHPUnit');
    }
    $result = MailTemplateLocaleMatrixRunner::run(['quiet' => true]);
    self::assertGreaterThanOrEqual(30, (int) $result['meta']['locale_count']);
    self::assertGreaterThanOrEqual(20, (int) $result['meta']['channel_count']);
    self::assertSame((int) $result['meta']['locale_count'] * (int) $result['meta']['channel_count'], (int) $result['counts']['total']);
}

private function repoRoot_MailTemplateMigrate(): string
{
    return '/Users/weline/Project/Official/框架';
}

private function assert_MailTemplateMigrate_testBackendProviderExpandsTopicsWithSharedDefaults(): void
{
    $root = $this->repoRoot_MailTemplateMigrate();
    $src = (string) file_get_contents($root . '/app/code/Weline/Backend/Extends/MailChannelProvider.php');
    self::assertStringContainsString('notification_email', $src);
    self::assertStringContainsString('default_templates', $src);
    self::assertStringContainsString('collectTopicRows', $src);
    self::assertStringContainsString('MailTemplateDefaultLocales', $src);
    self::assertFileExists($root . '/app/code/Weline/Backend/view/email/notification/zh_Hans_CN.html');
    self::assertFileExists($root . '/app/code/Weline/Backend/view/email/notification/fr_FR.html');
    self::assertFileExists($root . '/app/code/Weline/Backend/view/email/notification/ar_SA.html');
}

private function assert_MailTemplateMigrate_testEmailAdapterKeepsChannelAndUsesVars(): void
{
    $src = (string) file_get_contents($this->repoRoot_MailTemplateMigrate() . '/app/code/Weline/Backend/Adapter/Notification/EmailAdapter.php');
    self::assertStringContainsString("'channel' => \$channel", $src);
    self::assertStringContainsString("'vars' =>", $src);
    self::assertStringNotContainsString('unset($params[\'channel\'])', $src);
    self::assertStringContainsString('notify_', $src);
    self::assertStringContainsString('notification_email', $src);
}

private function assert_MailTemplateMigrate_testOrderMailNotifierAndObservers(): void
{
    $root = $this->repoRoot_MailTemplateMigrate();
    self::assertFileExists($root . '/app/code/Weline/Order/Service/OrderMailNotifier.php');
    $notifier = (string) file_get_contents($root . '/app/code/Weline/Order/Service/OrderMailNotifier.php');
    self::assertStringContainsString('website_code', $notifier);
    self::assertStringContainsString('locale', $notifier);
    self::assertStringContainsString('notify_customer', $notifier);
    self::assertStringContainsString('CHANNEL_SHIPPED', $notifier);
    self::assertStringContainsString('CHANNEL_REFUND', $notifier);
    foreach (['OrderCreatedObserver', 'OrderPaidObserver', 'OrderStatusChangedObserver', 'OrderShippedObserver', 'OrderRefundedObserver'] as $cls) {
        $path = $root . '/app/code/Weline/Order/Observer/' . $cls . '.php';
        self::assertFileExists($path);
        self::assertStringContainsString('OrderMailNotifier', (string) file_get_contents($path));
    }
}

private function assert_MailTemplateMigrate_testDebtCallersUseChannelAndVars(): void
{
    $root = $this->repoRoot_MailTemplateMigrate();
    $customer = (string) file_get_contents($root . '/app/code/Weline/Customer/Service/PasswordResetService.php');
    self::assertStringContainsString('Weline_Customer::password_reset', $customer);
    self::assertStringContainsString("'vars'", $customer);
    $binding = (string) file_get_contents($root . '/app/code/Weline/CustomerService/Service/EmailBindingService.php');
    self::assertStringContainsString('Weline_CustomerService::email_binding', $binding);
    self::assertStringContainsString("'vars'", $binding);
    $dropship = (string) file_get_contents($root . '/app/code/Weline/Dropship/Service/DropshipPushTerminalCompensationService.php');
    self::assertStringContainsString('Weline_Dropship::fulfillment_consolation', $dropship);
    self::assertStringContainsString('website_code', $dropship);
    self::assertStringContainsString("'vars'", $dropship);
}

private function assert_MailTemplateMigrate_testModuleDefaultTemplateFilesExist(): void
{
    $root = $this->repoRoot_MailTemplateMigrate();
    $files = ['/app/code/Weline/Order/view/email/order_created/zh_Hans_CN.html', '/app/code/Weline/Order/view/email/order_paid/zh_Hans_CN.html', '/app/code/Weline/Order/view/email/order_shipped/zh_Hans_CN.subject.txt', '/app/code/Weline/Order/view/email/order_refund/zh_Hans_CN.subject.txt', '/app/code/Weline/Product/view/email/product_update/zh_Hans_CN.html', '/app/code/Weline/Product/view/email/quote_reply/zh_Hans_CN.html', '/app/code/Weline/Websites/view/email/notification/zh_Hans_CN.html', '/app/code/Weline/Visitor/view/email/notification/zh_Hans_CN.html', '/app/code/Weline/Dropship/view/email/fulfillment_consolation/zh_Hans_CN.html', '/app/code/Weline/Customer/view/email/password_reset/zh_Hans_CN.html', '/app/code/Weline/CustomerService/view/email/email_binding/zh_Hans_CN.html'];
    foreach ($files as $rel) {
        self::assertFileExists($root . $rel, $rel);
    }
}

private function assert_MailTemplateMigrate_testProvidersDeclareDefaultTemplates(): void
{
    $root = $this->repoRoot_MailTemplateMigrate();
    foreach (['Order', 'Product', 'Websites', 'Visitor', 'Dropship', 'Customer', 'CustomerService'] as $mod) {
        $path = $root . '/app/code/Weline/' . $mod . '/extends/MailChannelProvider.php';
        if (!is_file($path)) {
            $path = $root . '/app/code/Weline/' . $mod . '/Extends/MailChannelProvider.php';
        }
        self::assertFileExists($path, $mod);
        self::assertStringContainsString('default_templates', (string) file_get_contents($path), $mod);
    }
}

private function assert_MailTemplateMigrate_testUpgradeSeedsMailTemplates(): void
{
    $root = $this->repoRoot_MailTemplateMigrate();
    self::assertFileExists($root . '/app/code/Weline/Smtp/Setup/Upgrade.php');
    $src = (string) file_get_contents($root . '/app/code/Weline/Smtp/Setup/Upgrade.php');
    self::assertStringContainsString('MailTemplateSeeder', $src);
    self::assertStringContainsString('syncAll', $src);
    self::assertStringNotContainsString('materializeFiles', $src);
    $module = include $root . '/app/code/Weline/Smtp/etc/module.php';
    self::assertIsArray($module);
    self::assertTrue(version_compare((string) ($module['version'] ?? '0'), '1.4.61', '>='), 'Smtp module must be >= 1.4.61 for zh-only disk mail template seed');
}

private function assert_MailTemplateResolverFallback_testNonChineseChainInsertsEnUsBeforeSiteDefaultZh(): void
{
    $chain = MailTemplateResolver::localeFallbackChain('de_DE', 'zh_Hans_CN');
    self::assertSame(['de_DE', 'en_US', 'zh_Hans_CN'], $chain, 'de_DE must try en_US before site-default zh');
    $enPos = array_search('en_US', $chain, true);
    $zhPos = array_search('zh_Hans_CN', $chain, true);
    self::assertIsInt($enPos);
    self::assertIsInt($zhPos);
    self::assertLessThan($zhPos, $enPos);
}

private function assert_MailTemplateResolverFallback_testEnGbChainDoesNotSkipEnUs(): void
{
    $chain = MailTemplateResolver::localeFallbackChain('en_GB', 'zh_Hans_CN');
    self::assertSame(['en_GB', 'en_US', 'zh_Hans_CN'], $chain);
}

private function assert_MailTemplateResolverFallback_testEnUsTargetDoesNotDuplicateEnUs(): void
{
    $chain = MailTemplateResolver::localeFallbackChain('en_US', 'zh_Hans_CN');
    self::assertSame(['en_US', 'zh_Hans_CN'], $chain);
}

private function assert_MailTemplateResolverFallback_testChineseTargetDoesNotForceEnUs(): void
{
    $chain = MailTemplateResolver::localeFallbackChain('zh_Hans_CN', 'zh_Hans_CN');
    self::assertSame(['zh_Hans_CN'], $chain);
    $chain2 = MailTemplateResolver::localeFallbackChain('zh_Hant_TW', 'zh_Hans_CN');
    self::assertSame(['zh_Hant_TW', 'zh_Hans_CN'], $chain2);
    self::assertNotContains(MailTemplateSeedCopyCatalog::LOCALE_EN, $chain2);
}

private function assert_MailTemplateResolverFallback_testSiteDefaultOtherThanZhStillEndsWithZhForNonChinese(): void
{
    $chain = MailTemplateResolver::localeFallbackChain('it_IT', 'fr_FR');
    self::assertSame(['it_IT', 'en_US', 'fr_FR', 'zh_Hans_CN'], $chain);
}

private function assert_MailTemplateResolverFallback_testResolveUsesLocaleFallbackChainHelper(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Smtp' . '/Service/MailTemplateResolver.php');
    self::assertStringContainsString('localeFallbackChain', $src);
    self::assertStringContainsString('isChineseLocale', $src);
    self::assertDoesNotMatchRegularExpression('/\$locales\s*=\s*\[\s*\$locale\s*\];\s*if\s*\(\s*\$siteDefault/', $src, 'old locale→siteDefault-only chain must be removed');
}

private function assert_MailTemplateShell_testShellPhtmlUsesLangTagsAndWrapsBody(): void
{
    $smtp = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $phtml = $smtp . '/view/email/shell.phtml';
    self::assertFileExists($phtml);
    $shellSrc = (string) file_get_contents($phtml);
    self::assertStringContainsString('data-weline-mail-shell', $shellSrc);
    self::assertStringContainsString('{{MAIL_BODY}}', $shellSrc);
    self::assertStringContainsString('{{var.site_logo_img|raw}}', $shellSrc);
    self::assertStringContainsString('{{var.brand_header_bg}}', $shellSrc);
    self::assertStringContainsString('{{var.brand_header_bg_css}}', $shellSrc);
    self::assertStringContainsString('{{var.brand_body_bg_css}}', $shellSrc);
    self::assertStringContainsString('{{var.brand_footer_bg_css}}', $shellSrc);
    self::assertStringContainsString('data-weline-mail-region="header"', $shellSrc);
    self::assertStringContainsString('data-weline-mail-region="body"', $shellSrc);
    self::assertStringContainsString('data-weline-mail-region="footer"', $shellSrc);
    self::assertSame(1, substr_count(strtolower($shellSrc), 'data-weline-mail-region="footer"'));
    self::assertStringContainsString('{{var.brand_display_name}}', $shellSrc);
    self::assertStringContainsString('<lang>需要帮助？</lang>', $shellSrc);
    self::assertStringContainsString('<lang>访问</lang>', $shellSrc);
    self::assertStringContainsString('<lang>此邮件由系统自动发送，请勿直接回复。如非本人操作，请忽略本邮件。</lang>', $shellSrc);
    self::assertStringContainsString('{{MAIL_HTML_DIR}}', $shellSrc);
    self::assertStringContainsString('{{MAIL_ALIGN_START}}', $shellSrc);
    self::assertStringNotContainsString('<script', strtolower($shellSrc));
    $composer = new MailTemplateShellComposer();
    $zh = $composer->wrap('<h1>Hello</h1><p>Body</p>', 'zh_Hans_CN', ['preheader' => 'Hello']);
    self::assertStringContainsString('data-weline-mail-shell', $zh);
    self::assertStringContainsString('lang="zh-Hans-CN"', $zh);
    self::assertStringContainsString('dir="ltr"', $zh);
    self::assertStringContainsString('align="left"', $zh);
    self::assertStringContainsString('{{var.brand_header_bg}}', $zh);
    self::assertStringContainsString('<h1>Hello</h1>', $zh);
    self::assertStringContainsString('需要帮助？', $zh);
    self::assertStringNotContainsString('<lang>', $zh);
    self::assertStringNotContainsString('{{MAIL_HTML_DIR}}', $zh);
    $en = $composer->wrap('<h1>Hello</h1><p>Body</p>', 'en_US', ['preheader' => 'Hello']);
    self::assertStringContainsString('lang="en-US"', $en);
    self::assertStringContainsString('dir="ltr"', $en);
    self::assertStringContainsString('Need help?', $en);
    self::assertStringContainsString('Visit', $en);
    self::assertStringNotContainsString('<lang>', $en);
    $ar = $composer->wrap('<h1>Hello</h1><p>Body</p>', 'ar_SA', ['preheader' => 'Hello']);
    self::assertSame('rtl', $composer->resolveTextDirection('ar_SA'));
    self::assertSame('rtl', $composer->resolveTextDirection('ur_PK'));
    self::assertSame('ltr', $composer->resolveTextDirection('bn_BD'));
    self::assertStringContainsString('lang="ar-SA"', $ar);
    self::assertStringContainsString('dir="rtl"', $ar);
    self::assertStringContainsString('direction:rtl', $ar);
    self::assertStringContainsString('align="right"', $ar);
    self::assertStringContainsString('text-align:right', $ar);
    self::assertStringContainsString('padding-right:16px', $ar);
    self::assertStringNotContainsString('{{MAIL_ALIGN_START}}', $ar);
    $root = '/Users/weline/Project/Official/框架';
    $samples = [$root . '/app/code/Weline/Customer/view/email/password_reset/zh_Hans_CN.html', $root . '/app/code/Weline/Order/view/email/order_created/zh_Hans_CN.html', $root . '/app/code/Weline/Backend/view/email/notification/zh_Hans_CN.html', $root . '/app/code/Weline/CustomerService/view/email/email_binding/zh_Hans_CN.html', $root . '/app/code/Weline/Dropship/view/email/fulfillment_consolation/zh_Hans_CN.html'];
    foreach ($samples as $file) {
        self::assertFileExists($file, $file);
        $html = (string) file_get_contents($file);
        self::assertStringNotContainsString('<!DOCTYPE', $html, $file);
        self::assertStringNotContainsString('<html', $html, $file);
        self::assertStringNotContainsString('site_logo_img', $html, $file);
        self::assertStringNotContainsString('<script', strtolower($html), $file);
    }
    $orderCreated = (string) file_get_contents($root . '/app/code/Weline/Order/view/email/order_created/zh_Hans_CN.html');
    self::assertStringNotContainsString('width:38%', $orderCreated);
    self::assertStringContainsString('width:1%', $orderCreated);
    self::assertStringContainsString('white-space:nowrap', $orderCreated);
    $catalog = (string) file_get_contents($smtp . '/Service/MailTemplateSeedCopyCatalog.php');
    self::assertStringContainsString('width:1%', $catalog);
    self::assertStringNotContainsString("width:38%;", $catalog);
    $provider = (string) file_get_contents($smtp . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
    self::assertStringContainsString('MailTemplateShellComposer', $provider);
    self::assertStringContainsString('->wrap(', $provider);
    $seeder = (string) file_get_contents($smtp . '/Service/MailTemplateSeeder.php');
    self::assertStringContainsString('extractBodyFragment', $seeder);
    $edit = (string) file_get_contents($smtp . '/view/backend/Template/edit.phtml');
    self::assertStringContainsString('smtp-template-shell-hint', $edit);
    $composerSrc = (string) file_get_contents($smtp . '/Service/MailTemplateShellComposer.php');
    self::assertStringContainsString('TranslationResolverInterface', $composerSrc);
    self::assertStringContainsString('shell.phtml', $composerSrc);
    self::assertStringContainsString('ThemeDirectoryResolver', $composerSrc);
    self::assertStringContainsString('resolveThemeTemplatePath', $composerSrc);
    self::assertStringContainsString('withMailLocaleEnvironment', $composerSrc);
    self::assertStringContainsString('Context::enter', $composerSrc);
    self::assertStringContainsString('setRequestLanguageOverride', $composerSrc);
    $hanfuShell = $root . '/app/design/Weline/hanfu/Weline_Smtp/email/shell.phtml';
    self::assertFileExists($hanfuShell);
    $hanfuSrc = (string) file_get_contents($hanfuShell);
    self::assertStringContainsString('data-weline-mail-theme="hanfu"', $hanfuSrc);
    self::assertStringContainsString('{{MAIL_BODY}}', $hanfuSrc);
    self::assertStringContainsString('data-weline-mail-region="header"', $hanfuSrc);
    // 激活 frontend=hanfu 时 wrap 须真正吃到设计主题壳（运行时，非仅文件存在）
    try {
        if (class_exists(\Weline\Theme\Model\WelineTheme::class) && class_exists(\Weline\Framework\Manager\ObjectManager::class)) {
            /** @var \Weline\Theme\Model\WelineTheme $active */
            $active = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Model\WelineTheme::class);
            $active->clearData()->clearQuery()->getActiveTheme('frontend');
            if ((int) $active->getId() === 3 || str_contains((string) $active->getPath(), 'hanfu')) {
                self::assertStringContainsString('data-weline-mail-theme="hanfu"', $zh);
            }
        }
    } catch (\Throwable) {
    }
}

private function assert_MailTemplateShell_testShellCsvCoversDefaultWebsiteLocalesWithoutChineseDisclaimer(): void
{
    $smtp = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $i18n = $smtp . '/i18n';
    $disclaimerZh = '此邮件由系统自动发送，请勿直接回复。如非本人操作，请忽略本邮件。';
    // 模块 CSV 仅 zh+en；其它 locale 壳文案走 seed shellCopy
    foreach (['zh_Hans_CN', 'en_US'] as $locale) {
        self::assertFileExists($i18n . '/' . $locale . '.csv', $locale);
        $csv = (string) file_get_contents($i18n . '/' . $locale . '.csv');
        self::assertStringContainsString($disclaimerZh, $csv, $locale);
        self::assertStringContainsString('客服邮箱：', $csv, $locale);
        self::assertStringContainsString('需要帮助？', $csv, $locale);
    }
    $composer = new MailTemplateShellComposer();
    $bn = $composer->wrap('<p>Body</p>', 'bn_BD', ['preheader' => 'x']);
    self::assertStringNotContainsString($disclaimerZh, $bn);
    self::assertStringNotContainsString('客服邮箱：', $bn);
    self::assertStringNotContainsString('服务时间：', $bn);
    self::assertStringNotContainsString('Phone:', $bn);
    self::assertStringContainsString('সাহায্য প্রয়োজন?', $bn);
    self::assertStringContainsString('সহায়তা:', $bn);
    $fr = $composer->wrap('<p>Body</p>', 'fr_FR', ['preheader' => 'x']);
    self::assertStringNotContainsString($disclaimerZh, $fr);
    self::assertStringNotContainsString('Phone:', $fr);
    self::assertStringContainsString('Ce message a été envoyé automatiquement', $fr);
    self::assertStringContainsString('Support :', $fr);
    self::assertStringContainsString('Besoin d', $fr);
}

/**
 * be-shell-prefer-seed：非 en 壳 UI 优先 seed shellCopy，禁止词典英回落 Phone:/Hours:/Address:。
 * 联系变量可空（仅验标签路径，不含品牌 service_hours 值）。
 */
private function assert_MailTemplateShell_testNonEnglishShellLabelsPreferSeedNotEnglishFallback(): void
{
    $composer = new MailTemplateShellComposer();
    foreach (['ru_RU', 'de_DE'] as $locale) {
        $shell = $composer->loadShell($locale);
        $wrapped = $composer->wrap('<p>Body</p>', $locale, ['preheader' => 'x']);
        foreach ([$shell, $wrapped] as $html) {
            self::assertStringNotContainsString('Phone:', $html, $locale);
            self::assertStringNotContainsString('Hours:', $html, $locale);
            self::assertStringNotContainsString('Address:', $html, $locale);
            self::assertStringNotContainsString('Need help?', $html, $locale);
        }
    }
    $ru = $composer->wrap('<p>Body</p>', 'ru_RU', ['preheader' => 'x']);
    self::assertStringContainsString('Телефон:', $ru);
    self::assertStringContainsString('Часы работы:', $ru);
    self::assertStringContainsString('Адрес:', $ru);
    self::assertStringContainsString('Нужна помощь?', $ru);
    $de = $composer->wrap('<p>Body</p>', 'de_DE', ['preheader' => 'x']);
    self::assertStringContainsString('Telefon:', $de);
    self::assertStringContainsString('Öffnungszeiten:', $de);
    self::assertStringContainsString('Adresse:', $de);
    self::assertStringContainsString('Brauchst du Hilfe?', $de);
}

private function assert_MailTemplateUiSend_testBackendUiAndMenuExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    self::assertFileExists($root . '/Controller/Backend/Template.php');
    self::assertFileExists($root . '/view/templates/backend/Template/listing.phtml');
    self::assertFileExists($root . '/view/templates/backend/Template/edit.phtml');
    self::assertFileExists($root . '/view/backend/Template/listing.phtml');
    self::assertFileExists($root . '/view/backend/Template/edit.phtml');
    $menu = (string) file_get_contents($root . '/etc/backend/menu.xml');
    self::assertStringContainsString('system_smtp_template', $menu);
    self::assertStringContainsString('渠道管理', $menu);
    self::assertStringContainsString('smtp/backend/template/listing', $menu);
    self::assertStringContainsString('order="3"', $menu);
    $controller = (string) file_get_contents($root . '/Controller/Backend/Template.php');
    self::assertStringContainsString('Weline_Smtp::system_smtp_template', $controller);
    self::assertStringContainsString('SystemConfigTargetScopeService', $controller);
    self::assertStringContainsString('MailTemplateSeeder', $controller);
    self::assertStringContainsString('findInheritFrom', $controller);
    self::assertStringContainsString('ActiveLocaleCodeProvider', $controller);
    self::assertStringContainsString('open_channel', $controller);
    self::assertStringContainsString('filter_q', $controller);
    self::assertStringContainsString('filter_locales', $controller);
    self::assertStringContainsString('listingReturnState', $controller);
    self::assertStringContainsString('withListingReturn', $controller);
    self::assertStringContainsString('listing_return', $controller);
    self::assertStringContainsString("'locales'", $controller);
    self::assertStringContainsString("'present_count'", $controller);
    self::assertStringContainsString('getInstalledActiveCodes', $controller);
    self::assertStringContainsString('function getEdit', $controller);
    self::assertStringContainsString('postSave', $controller);
    self::assertStringContainsString('postReset', $controller);
    // UC-1：postReset 须清空当前 storage_scope 壳区
    self::assertMatchesRegularExpression('/function postReset\(\): string\s*\{.*?->clear\(\$storageScope\)/s', $controller);
    self::assertStringContainsString('MailShellRegionStore', $controller);
    self::assertStringContainsString('shellRegionStore->clear', $controller);
    self::assertStringContainsString('postCopy', $controller);
    self::assertStringContainsString('SOURCE_CUSTOM', $controller);
    self::assertStringContainsString('normalizeEmailHtml', $controller);
    self::assertStringContainsString('array_key_exists($key, $post)', $controller);
    self::assertStringContainsString('website_code', $controller);
    $listingStub = (string) file_get_contents($root . '/view/templates/backend/Template/listing.phtml');
    self::assertStringContainsString('view/backend/Template/listing.phtml', $listingStub);
    self::assertStringNotContainsString('data-testid="smtp-channel-listing"', $listingStub);
    $listing = (string) file_get_contents($root . '/view/backend/Template/listing.phtml');
    self::assertStringContainsString('data-testid="smtp-channel-listing"', $listing);
    self::assertStringContainsString('data-testid="smtp-channel-listing-header"', $listing);
    self::assertStringContainsString('渠道管理', $listing);
    self::assertStringContainsString('<w:scope', $listing);
    self::assertStringContainsString('data-testid="smtp-channel-list"', $listing);
    self::assertStringContainsString('<details class="w-smtp-channel"', $listing);
    self::assertStringContainsString('smtp-channel-locale-table', $listing);
    self::assertStringContainsString('smtp-channel-inherit', $listing);
    self::assertStringContainsString('data-testid="smtp-channel-search"', $listing);
    self::assertStringContainsString('搜索渠道', $listing);
    self::assertStringContainsString('按范围筛选模板', $listing);
    self::assertStringContainsString('data-testid="smtp-locale-tag-filter"', $listing);
    self::assertStringContainsString('w:i18n:language:select', $listing);
    self::assertStringContainsString('multiple="true"', $listing);
    self::assertStringContainsString('id="smtp-locale-filter"', $listing);
    self::assertStringContainsString('语言标签过滤', $listing);
    self::assertStringContainsString('网站默认语', $listing);
    self::assertStringContainsString('data-initial-q', $listing);
    self::assertStringContainsString('data-initial-locales', $listing);
    self::assertStringContainsString('data-focus-locale', $listing);
    self::assertStringContainsString('focus_locale', $listing);
    self::assertStringContainsString('scrollIntoView', $listing);
    self::assertStringContainsString('writeListingParams', $listing);
    self::assertStringNotContainsString('data-smtp-locale-tag', $listing);
    self::assertStringNotContainsString('smtp-template-locale', $listing);
    self::assertStringNotContainsString('view/templates/backend/Template/listing.phtml', $listing);
    $resolver = (string) file_get_contents($root . '/Service/MailTemplateResolver.php');
    self::assertStringContainsString('default_LANGUAGE_CODE', $resolver);
    self::assertStringContainsString('resolveWebsiteDefaultLanguage', $resolver);
    $editStub = (string) file_get_contents($root . '/view/templates/backend/Template/edit.phtml');
    self::assertStringContainsString('view/backend/Template/edit.phtml', $editStub);
    self::assertStringNotContainsString('w:editor-manager', $editStub);
    $edit = (string) file_get_contents($root . '/view/backend/Template/edit.phtml');
    // 自研可视化：禁止再挂 CKEditor / editor-manager
    self::assertStringNotContainsString('w:editor-manager', $edit);
    self::assertStringNotContainsString('ckeditorInstance', $edit);
    self::assertStringNotContainsString('<w:editor', $edit);
    self::assertDoesNotMatchRegularExpression('/<textarea[^>]*\seditor\b/', $edit);
    self::assertStringContainsString('data-testid="smtp-template-body-source"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-editor-shell"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-edit-toolbar"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-back-listing"', $edit);
    self::assertStringContainsString('← 返回渠道管理', $edit);
    self::assertStringContainsString("'channel' => \$channel", $edit);
    self::assertStringContainsString('smtp/backend/template/listing', $edit);
    self::assertStringContainsString('listing_return', $edit);
    self::assertStringContainsString('data-testid="smtp-template-return-q"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-return-locales"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-return-focus-locale"', $edit);
    self::assertStringContainsString('name="focus_locale"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-edit-scope"', $edit);
    self::assertStringContainsString('<w:scope', $edit);
    self::assertStringContainsString('id="smtp-template-edit-scope"', $edit);
    self::assertStringContainsString('searchParams.set(\'target_scope\'', $edit);
    self::assertStringContainsString('searchParams.delete(\'template_id\')', $edit);
    self::assertStringContainsString('searchParams.delete(\'website_code\')', $edit);
    self::assertStringContainsString('smtp-tpl-locale_wrapper', $edit);
    self::assertStringContainsString('data-w-component="language-select"', $edit);
    self::assertStringContainsString('window.location.assign', $edit);
    self::assertStringContainsString('el.matches(\'input[name="target_scope"]\')', $edit);
    self::assertStringNotContainsString('<code class="w-text" data-size="sm"><?= $h($selectedScope) ?></code>', $edit);
    self::assertStringContainsString('data-testid="smtp-visual-editor"', $edit);
    self::assertStringContainsString('data-testid="smtp-ve-region-tabs"', $edit);
    self::assertStringContainsString('data-testid="smtp-ve-tab-all"', $edit);
    self::assertStringContainsString('data-region="all"', $edit);
    self::assertStringContainsString('isAllRegionView', $edit);
    self::assertStringContainsString('resolveRegionRoot', $edit);
    self::assertStringContainsString('data-testid="smtp-ve-style-toolbar"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-vars-content"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-vars-identity"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-vars-tone"', $edit);
    self::assertStringContainsString('w-smtp-visual-editor', $edit);
    self::assertStringContainsString('shell_regions_json', $edit);
    self::assertStringNotContainsString('ckeditorInstance', $edit);
    self::assertStringNotContainsString('allowSourceEditorSync', $edit);
    self::assertStringNotContainsString('bindSourceEditor', $edit);
    self::assertStringContainsString('data-testid="smtp-template-vars"', $edit);
    self::assertStringContainsString('data-var-code', $edit);
    self::assertStringContainsString("'{' + '{var.'", $edit);
    self::assertStringContainsString("{{var.' . \$code", $edit);
    self::assertStringContainsString('data-testid="smtp-template-live-preview"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-preview-iframe"', $edit);
    self::assertStringContainsString('sandbox="allow-same-origin"', $edit);
    $controllerSrc = (string) file_get_contents($root . '/Controller/Backend/Template.php');
    self::assertStringContainsString('preview_shell_html', $controllerSrc);
    self::assertStringContainsString('preview_samples', $controllerSrc);
    self::assertStringContainsString('buildPreviewSamples', $controllerSrc);
    self::assertStringContainsString('buildPreviewSamples($mergedVars, $editScope, $editLocale)', $controllerSrc);
    self::assertStringContainsString('MailShellRegionStore', $controllerSrc);
    self::assertStringContainsString('MailThemeFontCatalog', $controllerSrc);
    self::assertStringContainsString('shell_regions_json', $controllerSrc);
    self::assertStringContainsString('readMailChannelFromRequest', $controllerSrc);
    self::assertStringContainsString("str_contains(\$value, '::')", $controllerSrc);
    self::assertStringContainsString('name="mail_channel"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-mail-channel"', $edit);
    $brandSrc = (string) file_get_contents($root . '/Service/MailBrandContextService.php');
    self::assertStringContainsString('function buildPreviewSamples', $brandSrc);
    self::assertStringContainsString('localizePreviewText', $brandSrc);
    self::assertStringContainsString('scopeLockPreviewSample', $brandSrc);
    self::assertStringContainsString('data-weline-mail-var', $edit);
    self::assertStringContainsString('smtp-ve-var-hint', $edit);
    self::assertStringContainsString('applyTextColor', $edit);
    self::assertStringContainsString('pickStyleTarget', $edit);
    self::assertStringContainsString('w-smtp-ve-selected', $edit);
    self::assertStringContainsString('outline-offset:-6px', $edit);
    self::assertStringContainsString('is-floating', $edit);
    self::assertStringContainsString('positionFloatingToolbar', $edit);
    self::assertStringContainsString('hideFloatingToolbar', $edit);
    self::assertStringContainsString('clearActiveSelection', $edit);
    self::assertStringNotContainsString('allowSourceEditorSync', $edit);
    self::assertStringNotContainsString('bindSourceEditor', $edit);
    self::assertStringContainsString('smtp-template-advanced', $edit);
    self::assertStringContainsString('width:1%', $edit);
    self::assertStringContainsString('min-width:4.5em', $edit);
    self::assertStringContainsString('renderPreviewIfBlocks', $edit);
    self::assertStringContainsString('restoreMailIfBlocks', $edit);
    self::assertStringContainsString('scrubPreviewDebris', $edit);
    self::assertStringContainsString('data-weline-mail-if', $edit);
    self::assertStringContainsString('getViewportRectForEl', $edit);
    // 可视化须展示邮件配置三区背景图，禁止再强制 none
    self::assertStringNotContainsString('background-image:none', $edit);
    self::assertStringContainsString('background-size:cover', $edit);
    self::assertStringContainsString('overflow-wrap:break-word', $edit);
    self::assertStringNotContainsString('overflow-wrap:anywhere', $edit);
    self::assertStringContainsString('smtp-ve-text-color-field', $edit);
    self::assertStringContainsString('smtp-ve-color-hex', $edit);
    self::assertStringContainsString('文字颜色', $edit);
    self::assertStringContainsString('w-smtp-ve-color-input', $edit);
    self::assertStringContainsString("'{' + '{MAIL_BODY}}'", $edit);
    self::assertStringContainsString('form="smtp-template-save-form"', $edit);
    self::assertStringContainsString('form="smtp-template-reset-form"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-copy"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-reset"', $edit);
    self::assertStringContainsString('onsubmit="return confirm(', $edit);
    self::assertStringContainsString('确定重置为默认模板？将丢失自定义正文以及页头/页尾改动。', $edit);
    self::assertStringContainsString('data-testid="smtp-template-reset-form"', $edit);
    self::assertStringContainsString('data-testid="smtp-template-save"', $edit);
    self::assertStringNotContainsString('view/templates/backend/Template/edit.phtml', $edit);
    self::assertDoesNotMatchRegularExpression('/<textarea[^>]*\seditor\b/', $edit);
}

private function assert_MailTemplateUiSend_testSendPathUsesTemplateServices(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $provider = (string) file_get_contents($root . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
    self::assertStringContainsString('MailTemplateResolver', $provider);
    self::assertStringContainsString('MailTemplateRenderer', $provider);
    self::assertStringContainsString('MailTemplateSendContext', $provider);
    self::assertStringContainsString('use_template', $provider);
    self::assertStringContainsString("'vars'", $provider);
    self::assertStringContainsString('override_subject', $provider);
    self::assertStringContainsString('override_content', $provider);
    self::assertStringContainsString("'__template_id'", $provider);
    self::assertStringContainsString("'__locale'", $provider);
    self::assertStringContainsString('mergeInto($vars, $storageScope, $resolvedLocale', $provider);
    self::assertStringContainsString("'locale'", $provider);
    self::assertStringContainsString("'scope'", $provider);
    self::assertStringContainsString("'channel'", $provider);
    $log = (string) file_get_contents($root . '/Model/SmtpSendLog.php');
    self::assertStringContainsString("schema_fields_TEMPLATE_ID = 'template_id'", $log);
    self::assertStringContainsString("schema_fields_LOCALE = 'locale'", $log);
    $sender = (string) file_get_contents($root . '/Helper/SmtpSender.php');
    self::assertStringContainsString('__template_id', $sender);
    self::assertStringContainsString('__locale', $sender);
    self::assertStringContainsString('schema_fields_TEMPLATE_ID', $sender);
    self::assertStringContainsString('schema_fields_LOCALE', $sender);
    self::assertFileExists($root . '/Service/MailTemplateSendContext.php');
}

private function assert_MailTemplateUiSend_testRendererEscapesAndRaw(): void
{
    $renderer = new \Weline\Smtp\Service\MailTemplateRenderer();
    $out = $renderer->render('Hi {{var.name}}', ['name' => '<b>x</b>'], ['name']);
    self::assertSame('Hi &lt;b&gt;x&lt;/b&gt;', $out);
    $raw = $renderer->render('Hi {{var.name|raw}}', ['name' => '<b>x</b>'], ['name']);
    self::assertSame('Hi <b>x</b>', $raw);
    $stripped = $renderer->stripScripts('<p>a</p><script>alert(1)</script>');
    self::assertStringNotContainsString('<script', $stripped);
}

private function assert_MailTemplateUiSend_testRendererEvaluatesIfBlocks(): void
{
    $renderer = new \Weline\Smtp\Service\MailTemplateRenderer();
    $tpl = 'X{{#if var.continue_pay_url}}<a href="{{var.continue_pay_url}}">Pay</a>{{/if}}Y';
    $with = $renderer->render($tpl, ['continue_pay_url' => 'https://pay.test/x'], ['continue_pay_url']);
    self::assertSame('X<a href="https://pay.test/x">Pay</a>Y', $with);
    self::assertStringNotContainsString('{{#if', $with);
    self::assertStringNotContainsString('{{/if}}', $with);
    $without = $renderer->render($tpl, ['continue_pay_url' => ''], ['continue_pay_url']);
    self::assertSame('XY', $without);
    self::assertStringNotContainsString('Pay', $without);
}

private function assert_MailTemplateUiSend_testUnpaidOrderReminderTemplateIfCleared(): void
{
    $copy = \Weline\Smtp\Service\MailTemplateSeedCopyCatalog::forSlug('unpaid_order_reminder', 'en_US');
    self::assertNotNull($copy);
    $tpl = (string) ($copy['body'] ?? '');
    self::assertNotSame('', $tpl);
    $renderer = new \Weline\Smtp\Service\MailTemplateRenderer();
    $out = $renderer->render($tpl, ['customer_name' => 'Buyer', 'order_number' => 'WB-1', 'currency' => 'USD', 'grand_total' => '99.00', 'created_at' => '2026-09-13 10:00:00', 'continue_pay_url' => 'https://shop.test/pay'], ['customer_name', 'order_number', 'currency', 'grand_total', 'created_at', 'continue_pay_url']);
    self::assertStringContainsString('https://shop.test/pay', $out);
    self::assertStringContainsString('Continue payment', $out);
    self::assertStringNotContainsString('{{#if', $out);
    self::assertStringNotContainsString('{{/if}}', $out);
}

private function assert_SmtpConfigTabs_testConfigTemplateUsesThemeTabsInsteadOfPagination(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $template = (string) file_get_contents($moduleRoot . '/view/backend/Config.phtml');
    self::assertStringContainsString('data-w-component="tabs"', $template);
    self::assertStringContainsString('data-testid="smtp-config-tabs"', $template);
    self::assertStringContainsString('data-testid="smtp-tab-accounts"', $template);
    self::assertStringContainsString('data-testid="smtp-tab-channels"', $template);
    self::assertStringContainsString('id="smtp-panel-accounts"', $template);
    self::assertStringContainsString('id="smtp-panel-channels"', $template);
    self::assertStringContainsString('data-testid="smtp-account-management"', $template);
    self::assertStringContainsString('data-testid="smtp-channel-bindings"', $template);
    self::assertStringContainsString('btnSaveSenders', $template);
    self::assertStringNotContainsString('pagination', $template);
    self::assertStringNotContainsString('getPagination', $template);
    self::assertStringNotContainsString('w-pagination', $template);
}

private function assert_SmtpMailConfigPage_testMenuAndPageEmbedShellBackgroundGroup(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $menu = (string) file_get_contents($root . '/etc/backend/menu.xml');
    self::assertStringContainsString('system_smtp_mail_config', $menu);
    self::assertStringContainsString('title="邮件配置"', $menu);
    self::assertStringContainsString('action="smtp/backend/mail-config"', $menu);
    $controller = (string) file_get_contents($root . '/Controller/Backend/MailConfig.php');
    self::assertStringContainsString('Weline_Smtp::system_smtp_mail_config', $controller);
    self::assertStringContainsString('smtp/backend/mail-config', $controller);
    $view = (string) file_get_contents($root . '/view/backend/MailConfig.phtml');
    self::assertStringContainsString('data-testid="smtp-mail-config"', $view);
    self::assertStringContainsString('w-backend-page', $view);
    self::assertStringContainsString('w-card__body', $view);
    self::assertStringContainsString('<w:config:embed', $view);
    self::assertStringContainsString('group="smtp_mail_shell"', $view);
    self::assertStringContainsString('module="Weline_Smtp"', $view);
    self::assertStringContainsString('<w:scope', $view);
    self::assertStringContainsString('邮件壳背景图', $view);
    $declaration = (string) file_get_contents($root . '/extends/module/Weline_SystemConfig/Config/backend/smtp.phtml');
    self::assertStringContainsString('code="smtp_mail_shell"', $declaration);
    self::assertStringContainsString('key="smtp_mail_bg_header"', $declaration);
}

private function assert_SmtpSendLogChannel_testModelAndListingCarryChannel(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $model = (string) file_get_contents($moduleRoot . '/Model/SmtpSendLog.php');
    $listing = (string) file_get_contents($moduleRoot . '/view/templates/backend/Log/listing.phtml');
    $controller = (string) file_get_contents($moduleRoot . '/Controller/Backend/Log.php');
    $sender = (string) file_get_contents($moduleRoot . '/Helper/SmtpSender.php');
    $provider = (string) file_get_contents($moduleRoot . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
    self::assertStringContainsString("schema_fields_CHANNEL = 'channel'", $model);
    self::assertStringContainsString("schema_fields_SENDER_CODE = 'sender_code'", $model);
    self::assertStringContainsString("schema_fields_STORAGE_SCOPE = 'storage_scope'", $model);
    self::assertStringContainsString('CHANNEL', $model);
    self::assertStringContainsString('schema_fields_CHANNEL', $sender);
    self::assertStringContainsString('schema_fields_SENDER_CODE', $sender);
    self::assertStringContainsString('schema_fields_STORAGE_SCOPE', $sender);
    self::assertStringContainsString("__channel", $sender);
    self::assertStringContainsString("__storage_scope", $sender);
    self::assertStringContainsString("'__channel'", $provider);
    self::assertStringContainsString("'__sender_code'", $provider);
    self::assertStringContainsString("'__storage_scope'", $provider);
    self::assertStringContainsString('MailChannelCollector', $controller);
    self::assertStringContainsString('SmtpSendLogListPresenter', $controller);
    self::assertStringContainsString('channel', $controller);
    self::assertStringContainsString('SystemConfigTargetScopeService', $controller);
    self::assertStringContainsString('schema_fields_STORAGE_SCOPE', $controller);
    self::assertStringContainsString('data-testid="smtp-log-channel-select"', $listing);
    self::assertStringContainsString('data-testid="smtp-log-col-channel"', $listing);
    self::assertStringContainsString('data-testid="smtp-log-scope"', $listing);
    self::assertStringContainsString('<w:scope', $listing);
    self::assertStringContainsString('to_display', $listing);
    self::assertStringContainsString('locale_display', $listing);
    self::assertStringContainsString('content_excerpt', $listing);
    self::assertStringContainsString('data-testid="smtp-log-excerpt"', $listing);
    self::assertStringContainsString('data-smtp-log-preview', $listing);
    self::assertStringContainsString('data-testid="smtp-log-preview-dialog"', $listing);
    self::assertStringContainsString('data-testid="smtp-log-preview-iframe"', $listing);
    self::assertStringContainsString('channel', $listing);
    self::assertStringContainsString("embed' => '1'", $controller);
    self::assertStringContainsString('Backend/Log/embed', $controller);
    self::assertStringContainsString('respondEmbedDocument', $controller);
    self::assertStringContainsString('ResponseTerminateException', $controller);
    self::assertStringContainsString('content_excerpt', (string) file_get_contents($moduleRoot . '/Service/SmtpSendLogListPresenter.php'));
    self::assertStringNotContainsString('smtp-log-channel-tabs', $listing);
    self::assertStringNotContainsString('{{log.content}}', $listing);
    self::assertFileExists($moduleRoot . '/view/templates/backend/Log/embed.phtml');
    self::assertStringContainsString('data-smtp-embed-standalone', (string) file_get_contents($moduleRoot . '/view/templates/backend/Log/embed.phtml'));
}

private function assert_SmtpServiceCatalog_testSmtpServiceModelHasSelfAndExternalModes(): void
{
    $path = BP . '/app/code/Weline/Smtp/Model/SmtpService.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('weline_smtp_service', $src);
    self::assertStringContainsString("MODE_SELF = 'self'", $src);
    self::assertStringContainsString("MODE_EXTERNAL = 'external'", $src);
}

private function assert_SmtpServiceCatalog_testCatalogServiceCreatesBothModes(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/Service/SmtpServiceCatalogService.php');
    self::assertStringContainsString('function createSelfService', $src);
    self::assertStringContainsString('function createExternalService', $src);
    self::assertStringContainsString('ensurePublicDnsHostnamesInPool', $src);
    self::assertStringContainsString('resolveExistingPoolId', $src);
    self::assertStringContainsString('getDomainPoolList', $src);
    self::assertStringContainsString('域名已在池中', $src);
    self::assertStringContainsString("createAccountOnService(\$id, 'smtp'", $src);
    self::assertStringContainsString('已默认创建发信账号', $src);
    self::assertStringContainsString('MODE_EXTERNAL', $src);
}

private function assert_SmtpServiceCatalog_testSmtpAccountBelongsToService(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/Model/SmtpAccount.php');
    self::assertStringContainsString('smtp_service_id', $src);
}

private function assert_SmtpServiceSplit_testSmtpAccountModelExists(): void
{
    $path = BP . '/app/code/Weline/Smtp/Model/SmtpAccount.php';
    self::assertFileExists($path);
    $src = (string) \file_get_contents($path);
    self::assertStringContainsString('weline_smtp_account', $src);
    self::assertStringContainsString('ROLE_RELAY', $src);
}

private function assert_SmtpServiceSplit_testConfigUiHasEnableCard(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/view/backend/Config.phtml');
    self::assertStringContainsString('smtp-service-enable-card', $src);
    self::assertStringContainsString('启用并入池出证', $src);
    self::assertStringContainsString('domain_mode', $src);
    self::assertStringContainsString('与企业邮局是否启域无关', $src);
    self::assertStringNotContainsString('smtp-ensure-mail-btn', $src);
    self::assertStringNotContainsString('smtp-route-mailbox-card', $src);
    self::assertStringNotContainsString('用自建邮局一键配置', $src);
}

private function assert_SmtpServiceSplit_testControllerHasEnableActions(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/Controller/Backend/Config.php');
    self::assertStringContainsString('function postEnableService', $src);
    self::assertStringContainsString('function postCreateSmtpAccount', $src);
    self::assertStringContainsString('SmtpServiceEnableService', $src);
    self::assertStringContainsString("assign('mail_bootstrap_ready', false)", $src);
}

private function assert_SmtpServiceSplit_testBootstrapNeverWaitsOnMailDomain(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/Smtp/Service/MailSmtpBootstrapService.php');
    $start = strpos($src, 'function canEnsure');
    self::assertNotFalse($start);
    $end = strpos($src, 'function ', $start + 10);
    $body = substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
    self::assertStringContainsString('return false', $body);
    self::assertStringNotContainsString('resolveActiveMailDomain', $body);
}

private function assert_SmtpSiteSetupTask_testSmtpSetupTaskProviderRegistersViaExtends(): void
{
    $smtpRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $ssaRoot = dirname($smtpRoot) . '/SiteSetupAssistant';
    $interface = (string) file_get_contents($ssaRoot . '/Api/SetupTaskProviderInterface.php');
    $abstract = (string) file_get_contents($ssaRoot . '/Api/AbstractSetupTaskProvider.php');
    $collector = (string) file_get_contents($ssaRoot . '/Service/SetupTaskCollector.php');
    $float = (string) file_get_contents($ssaRoot . '/view/templates/backend/widgets/site-setup-assistant-float.phtml');
    $provider = (string) file_get_contents($smtpRoot . '/extends/module/Weline_SiteSetupAssistant/SetupTask/SmtpSetupTaskProvider.php');
    $provisioner = (string) file_get_contents($smtpRoot . '/Service/MailAccountTransportProvisioner.php');
    $bootstrap = (string) file_get_contents($smtpRoot . '/Service/MailSmtpBootstrapService.php');
    $config = (string) file_get_contents($smtpRoot . '/Controller/Backend/Config.php');
    $view = (string) file_get_contents($smtpRoot . '/view/backend/Config.phtml');
    $mailQuery = (string) file_get_contents(dirname($smtpRoot) . '/Mail/extends/module/Weline_Framework/Query/MailQueryProvider.php');
    $mailEnsure = (string) file_get_contents(dirname($smtpRoot) . '/Mail/Service/MailAccountEnsureService.php');
    $coverage = (string) file_get_contents($smtpRoot . '/Service/MailTemplateSetupCoverage.php');
    $smtpExtends = (string) file_get_contents($smtpRoot . '/extends.php');
    $command = (string) file_get_contents(dirname($smtpRoot, 4) . '/dev/ai-command/sitesetup/建站.md');
    self::assertFileDoesNotExist($smtpRoot . '/extends/module/Weline_SiteSetupAssistant/SetupTaskStatus/SmtpSetupTaskStatusProvider.php');
    self::assertStringContainsString('interface SetupTaskProviderInterface', $interface);
    self::assertStringContainsString('abstract class AbstractSetupTaskProvider', $abstract);
    self::assertStringContainsString('class SetupTaskCollector', $collector);
    self::assertStringContainsString('SetupTaskCollector', $float);
    self::assertStringContainsString('class SmtpSetupTaskProvider', $provider);
    self::assertStringContainsString('extends AbstractSetupTaskProvider', $provider);
    self::assertStringContainsString('smtp_send_path', $provider);
    self::assertStringContainsString('smtp_transport', $provider);
    self::assertStringContainsString('smtp_channel_bindings', $provider);
    self::assertStringContainsString('smtp_mail_template', $provider);
    self::assertStringContainsString('smtp_mail_template_i18n', $provider);
    self::assertStringContainsString('MailAccountTransportProvisioner', $provider);
    self::assertStringContainsString('MailSmtpBootstrapService', $provider);
    self::assertStringContainsString('canBootstrapMail', $provider);
    self::assertStringContainsString('contact@', $provider);
    self::assertStringContainsString('class MailAccountTransportProvisioner', $provisioner);
    self::assertStringContainsString('TRANSPORT_CODE', $provisioner);
    self::assertStringContainsString('bindAllChannels', $provisioner);
    self::assertStringContainsString('rebindAll', $provisioner);
    self::assertStringContainsString("'contact'", $provisioner);
    self::assertStringContainsString('class MailSmtpBootstrapService', $bootstrap);
    self::assertStringContainsString('ensureDefaultContactPath', $bootstrap);
    self::assertStringContainsString('routeMailboxToChannels', $bootstrap);
    self::assertStringContainsString('hasMailboxRouting', $bootstrap);
    self::assertStringContainsString("w_query('mail'", $bootstrap);
    self::assertStringNotContainsString('MailAccountManagementService', $bootstrap);
    self::assertStringContainsString('ensureContactAccount', $mailQuery);
    self::assertStringContainsString('ensureMailbox', $mailQuery);
    self::assertStringContainsString('class MailAccountEnsureService', $mailEnsure);
    self::assertStringContainsString('LOCAL_CONTACT', $mailEnsure);
    self::assertStringContainsString('ensure_mail', $config);
    self::assertStringContainsString('runEnsureMailAccountTransport', $config);
    self::assertStringContainsString('MailSmtpBootstrapService', $config);
    self::assertStringContainsString('postRouteMailbox', $config);
    self::assertStringContainsString('smtp-ensure-mail-btn', $view);
    self::assertStringContainsString('用自建邮局一键配置', $view);
    self::assertStringContainsString('smtp-route-mailbox-form', $view);
    self::assertStringContainsString('contact@', $view);
    self::assertStringContainsString('class MailTemplateSetupCoverage', $coverage);
    self::assertStringContainsString('SystemConfig::SCOPE_GLOBAL', $coverage);
    self::assertStringContainsString('localesByChannel', $coverage);
    self::assertStringContainsString('SetupTaskProviderInterface::class', $smtpExtends);
    self::assertStringContainsString('SmtpSetupTaskProvider::class', $smtpExtends);
    self::assertStringNotContainsString('SetupTaskStatusProviderInterface', $smtpExtends);
    self::assertStringContainsString('禁止', $command);
    self::assertStringContainsString('SetupTaskProviderInterface', $command);
    self::assertStringContainsString('AbstractSetupTaskProvider', $command);
}

private function assert_SmtpTestSendFeedback_testPostTestReturnsJsonForAjaxAndFrontShowsFeedback(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $controller = (string) file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
    $template = (string) file_get_contents($moduleRoot . '/view/backend/Config.phtml');
    self::assertStringContainsString('wantsJsonResponse', $controller);
    self::assertStringContainsString('jsonSuccess', $controller);
    self::assertStringContainsString('jsonError', $controller);
    self::assertStringContainsString('markSetupConfirmed', $controller);
    self::assertStringContainsString('application/json', $template);
    self::assertStringContainsString('smtpTestFeedback', $template);
    self::assertStringContainsString('btnDoTest', $template);
    self::assertStringContainsString('dialog.close', $template);
    self::assertStringContainsString('testSuccess', $template);
    self::assertStringNotContainsString("setTimeout(function() { window.location.reload(); }, 500);", $template);
    self::assertStringContainsString('<w:icon name="close"', $template);
}

private function assert_SmtpWebsiteScopeSettings_testSystemConfigDeclarationUsesWebsiteScope(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $declaration = (string) file_get_contents($moduleRoot . '/extends/module/Weline_SystemConfig/Config/backend/smtp.phtml');
    self::assertStringContainsString('@config.area {backend}', $declaration);
    self::assertStringContainsString('@config.acl {Weline_Smtp::system_smtp_config}', $declaration);
    self::assertStringContainsString('key="smtp_senders"', $declaration);
    self::assertStringContainsString('key="smtp_sender_contacts"', $declaration);
    self::assertStringContainsString('key="smtp_host"', $declaration);
    self::assertStringContainsString('key="smtp_mail_bg_header"', $declaration);
    self::assertStringContainsString('key="smtp_mail_bg_body"', $declaration);
    self::assertStringContainsString('key="smtp_mail_bg_footer"', $declaration);
    self::assertStringContainsString('path-global="mail/backgrounds/header"', $declaration);
    self::assertStringContainsString('path="websites/{website}/{store}/mail/backgrounds/header"', $declaration);
    self::assertStringContainsString('lock-root-global="mail"', $declaration);
    self::assertStringContainsString('scope="global,website"', $declaration);
    self::assertStringContainsString('scope="global,website,store"', $declaration);
    self::assertStringContainsString('is_sensitive="true"', $declaration);
    $data = (string) file_get_contents($moduleRoot . '/Helper/Data.php');
    self::assertStringContainsString('key_smtp_mail_bg_header', $data);
    self::assertStringContainsString('getMailShellBackgrounds', $data);
}

private function assert_SmtpWebsiteScopeSettings_testConfigPageUsesTargetScopeAndMenu(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Smtp';
    $controller = (string) file_get_contents($moduleRoot . '/Controller/Backend/Config.php');
    $template = (string) file_get_contents($moduleRoot . '/view/backend/Config.phtml');
    $menuXml = (string) file_get_contents($moduleRoot . '/etc/backend/menu.xml');
    $data = (string) file_get_contents($moduleRoot . '/Helper/Data.php');
    $provider = (string) file_get_contents($moduleRoot . '/extends/module/Weline_Framework/Query/SmtpQueryProvider.php');
    self::assertStringContainsString('SystemConfigTargetScopeService', $controller);
    self::assertStringContainsString('getSenders($module,', $controller);
    self::assertStringContainsString('setSenders(', $controller);
    self::assertStringContainsString('target_scope', $controller);
    self::assertStringContainsString('isValidTransportId', $controller);
    self::assertStringContainsString('<w:scope', $template);
    self::assertStringContainsString('weline_systemconfig/backend/config', $template);
    self::assertStringContainsString('data-testid="smtp-config-scope"', $template);
    self::assertStringContainsString('pattern="[A-Za-z][A-Za-z0-9_]*"', $template);
    self::assertStringContainsString('sanitizeSenderCode', $template);
    self::assertStringContainsString('data-testid="smtp-section-identity"', $template);
    self::assertStringContainsString('data-testid="smtp-section-transport"', $template);
    self::assertStringContainsString('btnSaveSenders', $template);
    self::assertStringContainsString('weline-btn-test-sender, .btn-test-sender', $template);
    self::assertStringContainsString('网站维护中', $template);
    self::assertStringContainsString('isMaintenanceResult', $template);
    self::assertStringContainsString('不是业务代号，也不是邮箱', $template);
    self::assertStringContainsString('smtp-channel-bindings', $template);
    self::assertStringContainsString('smtp-account-management', $template);
    self::assertStringContainsString('WelineThemeSearchSelect', $template);
    self::assertStringContainsString('theme:search-select', $template);
    self::assertStringContainsString('data-smtp-ss="mail-account"', $template);
    self::assertStringContainsString('data-smtp-ss="account-channels"', $template);
    self::assertStringContainsString('data-smtp-ss="channel-transport"', $template);
    self::assertStringContainsString('collectChannelBindings', $template);
    self::assertStringContainsString('generateTransportId', $template);
    self::assertStringContainsString('source="Weline_Smtp::system_smtp_config"', $menuXml);
    self::assertStringContainsString('action="smtp/backend/config"', $menuXml);
    self::assertStringContainsString('title="Smtp配置"', $menuXml);
    self::assertStringContainsString('ConfigReader', $data);
    self::assertStringContainsString('ConfigStore', $data);
    self::assertStringContainsString('setScopedConfig', $data);
    self::assertStringContainsString('function getSenders(string $module = \'Weline_Smtp\', ?string $scope = null)', $data);
    self::assertStringContainsString('function setSenders(array $senders, string $module = \'Weline_Smtp\', ?string $scope = null)', $data);
    self::assertStringContainsString('function resolvePublicFromEmail(string $module = \'Weline_Smtp\', ?string $scope = null)', $data);
    self::assertStringContainsString("'scope'", $provider);
    self::assertStringContainsString('resolveScopeParam', $provider);
}
}
