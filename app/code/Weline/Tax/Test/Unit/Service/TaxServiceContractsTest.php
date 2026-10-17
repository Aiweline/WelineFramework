<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Tax\Service\CheckoutTaxAdvisor;
use Weline\Tax\Service\TaxDefaultSeedService;
use Weline\Tax\Service\TaxScopeConfig;
use Weline\Tax\Service\TaxSeedRateCatalog;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllTaxServiceContractsPass().
 */
final class TaxServiceContractsTest extends TestCase
{
    public function testAllTaxServiceContractsPass(): void
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
            'TaxDefaultSeedService::testSeedRevisionAndCoreCountries' => function () {
            $this->assert_TaxDefaultSeedService_testSeedRevisionAndCoreCountries();
        },
            'TaxProcessCache::testTaxEngineAndScopeConfigOwnProcessBags' => function () {
            $this->assert_TaxProcessCache_testTaxEngineAndScopeConfigOwnProcessBags();
        },
            'TaxProcessCache::testProcessCacheResetterIsRegistered' => function () {
            $this->assert_TaxProcessCache_testProcessCacheResetterIsRegistered();
        },
            'TaxScopeConfigChannel::testBackendTaxFieldsAllowChannelScope' => function () {
            $this->assert_TaxScopeConfigChannel_testBackendTaxFieldsAllowChannelScope();
        },
            'TaxScopeConfigChannel::testResolveAcceptsChannelIdAndCheckoutAdvisorPassesIt' => function () {
            $this->assert_TaxScopeConfigChannel_testResolveAcceptsChannelIdAndCheckoutAdvisorPassesIt();
        },
            'TaxScopeConfigChannel::testDisabledScopeConfigTurnsAdvisorOffEvenWhenRolloutAllows' => function () {
            $this->assert_TaxScopeConfigChannel_testDisabledScopeConfigTurnsAdvisorOffEvenWhenRolloutAllows();
        },
        ];
    }

private function assert_TaxDefaultSeedService_testSeedRevisionAndCoreCountries(): void
{
    self::assertSame(0, TaxDefaultSeedService::WEBSITE_ID);
    self::assertSame(TaxSeedRateCatalog::SEED_REVISION, TaxDefaultSeedService::SEED_REVISION);
    self::assertSame(6, TaxSeedRateCatalog::SEED_REVISION);
    self::assertSame('', TaxSeedRateCatalog::seedCollectSalesTaxCountriesCsv());
    $suggested = TaxSeedRateCatalog::suggestedCollectSalesTaxCountryCodes();
    self::assertNotContains('CN', $suggested);
    self::assertContains('US', $suggested);
    self::assertContains('JP', $suggested);
    self::assertGreaterThanOrEqual(40, count($suggested));
    self::assertCount(3, TaxDefaultSeedService::CLASSES);
    self::assertSame('exempt', TaxSeedRateCatalog::CLASS_EXEMPT);
    $rules = [];
    foreach (TaxDefaultSeedService::rules() as $rule) {
        $rules[$rule['class_code'] . '@' . $rule['jurisdiction_key']] = $rule['rate_bps'];
    }
    self::assertGreaterThanOrEqual(120, count($rules));
    self::assertSame(1300, $rules['standard@CN|']);
    self::assertSame(900, $rules['reduced@CN|']);
    self::assertSame(0, $rules['exempt@CN|']);
    self::assertSame(1900, $rules['standard@DE|']);
    self::assertSame(0, $rules['exempt@DE|']);
    self::assertSame(2000, $rules['standard@GB|']);
    self::assertSame(1000, $rules['standard@AU|']);
    self::assertSame(1000, $rules['standard@JP|']);
    self::assertSame(700, $rules['standard@US|']);
    self::assertSame(725, $rules['standard@US|CA']);
    self::assertSame(0, $rules['exempt@US|CA']);
}

private function assert_TaxProcessCache_testTaxEngineAndScopeConfigOwnProcessBags(): void
{
    $engine = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/Service/TaxEngine.php');
    self::assertStringContainsString('$processClassesByWebsite', $engine);
    self::assertStringContainsString('$processRulesByWebsite', $engine);
    self::assertStringContainsString('function clearProcessCache', $engine);
    self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $engine);
    self::assertStringContainsString('ScopeIdentity::websiteById', $engine);
    self::assertStringContainsString("'tax.classes'", $engine);
    $scope = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/Service/TaxScopeConfig.php');
    self::assertStringContainsString('$processResolvedByScope', $scope);
    self::assertStringContainsString('function clearProcessCache', $scope);
    self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $scope);
    self::assertStringContainsString('ScopeIdentity::fromLayerIds', $scope);
    self::assertStringContainsString("'tax.flags'", $scope);
}

private function assert_TaxProcessCache_testProcessCacheResetterIsRegistered(): void
{
    $module = require '/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/etc/module.php';
    self::assertSame(\Weline\Tax\Api\Runtime\ProcessCacheResetter::class, $module['provides']['process_cache_resetter.Weline_Tax'] ?? null);
}

private function assert_TaxScopeConfigChannel_testBackendTaxFieldsAllowChannelScope(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/extends/module/Weline_SystemConfig/Config/backend/tax.phtml');
    self::assertStringContainsString('tax/general/enabled', $src);
    self::assertStringContainsString('tax/general/prices_include_tax', $src);
    self::assertStringContainsString('tax/general/collect_sales_tax_countries', $src);
    self::assertGreaterThanOrEqual(5, substr_count($src, 'scope="global,website,store,channel"'), 'tax/general runtime fields must allow channel scope');
    self::assertDoesNotMatchRegularExpression('/scope="global,website,store"/', $src, 'runtime tax fields must not omit channel');
}

private function assert_TaxScopeConfigChannel_testResolveAcceptsChannelIdAndCheckoutAdvisorPassesIt(): void
{
    $scopeSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/Service/TaxScopeConfig.php');
    self::assertStringContainsString('public function resolve(int $websiteId, int $storeId, int $channelId = 0)', $scopeSrc);
    self::assertStringContainsString('ScopeIdentity::channel(', $scopeSrc);
    self::assertStringContainsString('SalesChannelCatalogInterface', $scopeSrc);
    self::assertStringContainsString('toStorageScope($identity)', $scopeSrc);
    $advisorSrc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Tax' . '/Service/CheckoutTaxAdvisor.php');
    self::assertStringContainsString('max(0, $channelId)', $advisorSrc);
    self::assertStringContainsString("resolved['enabled']", $advisorSrc);
    $resolved = TaxScopeConfig::forTesting(['enabled' => true])->resolve(0, 1, 2);
    self::assertSame(2, (int) $resolved['channel_id']);
    self::assertTrue((bool) $resolved['enabled']);
    $advisor = CheckoutTaxAdvisor::forTestingActive();
    // forTestingActive allowlists website:0 (store/channel 0 → website subject).
    self::assertTrue($advisor->isEffectivelyOn(0, 0, 0));
}

private function assert_TaxScopeConfigChannel_testDisabledScopeConfigTurnsAdvisorOffEvenWhenRolloutAllows(): void
{
    $advisor = CheckoutTaxAdvisor::forTestingActive(pricesIncludeTax: true, collectSalesTaxCountries: '');
    // Replace with disabled scope via reflection-free forTesting stub path:
    $disabled = new CheckoutTaxAdvisor(\Weline\Tax\Service\TaxEngine::forTesting(), $advisor->rollout(), $advisor->lkg(), TaxScopeConfig::forTesting(['enabled' => false]));
    self::assertFalse($disabled->isEffectivelyOn(0, 1, 1));
}
}
