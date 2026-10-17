<?php

declare(strict_types=1);

namespace Weline\Currency\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Currency\Service\CurrencySwitcherIslandBuilder;
use Weline\Framework\Cache\CachePolicy;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCurrencyServiceContractsPass().
 */
final class CurrencyServiceContractsTest extends TestCase
{
    public function testAllCurrencyServiceContractsPass(): void
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
            'CurrencySwitcherIslandBuilder::testCachePolicyIsLangOnlyWebsiteScoped' => function () {
            $this->assert_CurrencySwitcherIslandBuilder_testCachePolicyIsLangOnlyWebsiteScoped();
        },
            'CurrencySwitcherIslandBuilder::testPendingIslandHasNoInstanceIdOrSelectedCurrency' => function () {
            $this->assert_CurrencySwitcherIslandBuilder_testPendingIslandHasNoInstanceIdOrSelectedCurrency();
        },
            'CurrencySwitcherIslandBuilder::testBuilderSourceUsesHotCacheAndSelectionNeutralBag' => function () {
            $this->assert_CurrencySwitcherIslandBuilder_testBuilderSourceUsesHotCacheAndSelectionNeutralBag();
        },
            'CurrencySwitcherIslandBuilder::testInvalidateIsWiredFromCurrencySaveAndLocalDescription' => function () {
            $this->assert_CurrencySwitcherIslandBuilder_testInvalidateIsWiredFromCurrencySaveAndLocalDescription();
        },
            'CurrencySwitcherIslandBuilder::testPredefinedPoolIsRegistered' => function () {
            $this->assert_CurrencySwitcherIslandBuilder_testPredefinedPoolIsRegistered();
        },
        ];
    }

private function assert_CurrencySwitcherIslandBuilder_testCachePolicyIsLangOnlyWebsiteScoped(): void
{
    $policy = CurrencySwitcherIslandBuilder::cachePolicy();
    self::assertInstanceOf(CachePolicy::class, $policy);
    self::assertSame('currency.switcher.island', $policy->resource);
    self::assertSame(CurrencySwitcherIslandBuilder::POOL, $policy->pool);
    self::assertSame('website', $policy->scope);
    self::assertSame(['lang'], $policy->vary);
    self::assertNotContains('currency', $policy->vary);
    self::assertContains('config', $policy->dependencies);
    self::assertContains('global/i18n', $policy->dependencies);
}

private function assert_CurrencySwitcherIslandBuilder_testPendingIslandHasNoInstanceIdOrSelectedCurrency(): void
{
    $html = CurrencySwitcherIslandBuilder::pendingIslandHtml();
    self::assertStringContainsString('data-weline-chrome-island="currency-switcher"', $html);
    self::assertStringContainsString('<!--weline-chrome-island-pending:currency-switcher-->', $html);
    self::assertStringNotContainsString('SwitcherInstanceId', $html);
    self::assertStringNotContainsString('w-currency-switcher-menu-', $html);
    self::assertStringNotContainsString('data-currency=', $html);
    self::assertStringNotContainsString('aria-current="true"', $html);
}

private function assert_CurrencySwitcherIslandBuilder_testBuilderSourceUsesHotCacheAndSelectionNeutralBag(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/Service/CurrencySwitcherIslandBuilder.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('StorefrontScopeHotCache', $source);
    self::assertStringContainsString('rememberPolicy', $source);
    self::assertStringContainsString(CurrencySwitcherIslandBuilder::LOGICAL_KEY_PREFIX, $source);
    self::assertStringContainsString('buildOptionsBag', $source);
    self::assertStringContainsString('stampIslandHtml', $source);
    self::assertStringContainsString('batchLocalNames', $source);
    self::assertStringContainsString('CTX_OPTIONS_BAG', $source);
    self::assertStringNotContainsString('CTX_LIVE_HTML', $source);
    self::assertStringNotContainsString('header-currency-switcher-data', $source);
    self::assertStringNotContainsString('EventsManager', $source);
    // Search aliases: current + zh + en only (not legacy 9-locale ICU loop).
    self::assertStringContainsString("'zh_Hans_CN', 'en_US'", $source);
    self::assertStringNotContainsString('es_ES', $source);
    self::assertStringNotContainsString('ja_JP', $source);
}

private function assert_CurrencySwitcherIslandBuilder_testInvalidateIsWiredFromCurrencySaveAndLocalDescription(): void
{
    $currencyModel = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/Model/Currency.php');
    self::assertStringContainsString('CurrencySwitcherIslandBuilder::invalidate', $currencyModel);
    $local = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/Service/CurrencyLocalDescriptionService.php');
    self::assertSame(2, substr_count($local, 'CurrencySwitcherIslandBuilder::invalidate'), 'saveLocalNames and deleteLocalNames must both invalidate the island bag');
    $resetter = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Currency' . '/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('CurrencySwitcherIslandBuilder::invalidate', $resetter);
}

private function assert_CurrencySwitcherIslandBuilder_testPredefinedPoolIsRegistered(): void
{
    $manager = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/Cache/CacheManager.php');
    self::assertStringContainsString("'weline_currency_switcher_island'", $manager);
    self::assertStringContainsString('hijack_exempt', $manager);
}
}
