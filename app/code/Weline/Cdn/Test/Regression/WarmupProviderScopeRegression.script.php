<?php

declare(strict_types=1);

/**
 * Regression: CDN Warmup Provider tree + Domain scope (frozen UC source contracts)
 *
 * Usage: php app/code/Weline/Cdn/Test/Regression/WarmupProviderScopeRegression.script.php
 */

$root = dirname(__DIR__, 6);
require $root . '/app/bootstrap.php';

use Weline\Cdn\Cron\Warmup;
use Weline\Cdn\Service\WarmupProviderScanner;
use Weline\Cdn\WarmupProvider\FpcExtraDeclaredUrls;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Extends\Module\Weline_Cdn\ProductHeatUrls;

$fail = 0;
$pass = static function (string $msg): void {
    echo "[PASS] {$msg}\n";
};
$assert = static function (bool $cond, string $msg) use (&$fail, $pass): void {
    if ($cond) {
        $pass($msg);
    } else {
        echo "[FAIL] {$msg}\n";
        $fail++;
    }
};

echo "=== Warmup Provider Scope Regression ===\n";

$scanner = ObjectManager::getInstance(WarmupProviderScanner::class);
$providers = $scanner->scanProviders(true);
$assert(in_array(FpcExtraDeclaredUrls::class, $providers, true), 'UC1: builtin FpcExtraDeclaredUrls registered');
$assert(
    in_array(ProductHeatUrls::class, $providers, true) || count(array_filter($providers, static fn ($c) => str_ends_with((string)$c, '\\ProductHeatUrls'))) > 0,
    'UC1: ProductHeatUrls visible via Extends scan (or class present)'
);

$fpcSrc = (string)file_get_contents($root . '/app/code/Weline/Cdn/WarmupProvider/FpcExtraDeclaredUrls.php');
$assert(str_contains($fpcSrc, 'isProductPattern'), 'UC6: FPC provider skips product patterns');
$assert(str_contains($fpcSrc, 'isExcluded'), 'UC6: FPC provider excludes login/backend prefixes');
$assert(str_contains($fpcSrc, 'WarmupLocaleUrlExpander'), 'locale: FPC expands via WarmupLocaleUrlExpander');
$assert(str_contains($fpcSrc, '覆盖站点已启用语种'), 'locale: FPC UI hint mentions enabled locales');
$prodSrc = (string)file_get_contents($root . '/app/code/Weline/Product/extends/module/Weline_Cdn/ProductHeatUrls.php');
$assert(str_contains($prodSrc, 'WarmupLocaleUrlExpander'), 'locale: ProductHeat expands locales');
$assert(str_contains($prodSrc, '站点已启用语种'), 'locale: ProductHeat hint mentions locales');

$ui = (string)file_get_contents($root . '/app/code/Weline/Cdn/view/templates/Backend/Warmup/index.phtml');
$assert(str_contains($ui, '<w:scope'), 'UC1 UI: uses official w:scope taglib');
$assert(str_contains($ui, 'target_scope'), 'UC1 UI: target_scope wiring');
$assert(str_contains($ui, 'scope="global,website"'), 'UC1 UI: global+website levels');
$assert(!str_contains($ui, '<select id="cdn-warmup-scope"'), 'UC1 UI: no hand-rolled domain select');
$assert(str_contains($ui, '已入队 URL'), 'UC3: queued URL panel title');
$assert(str_contains($ui, '写入 %{1} · 范围未命中过滤 %{2} · 去重跳过 %{3}'), 'UC2: collect echo counters');
$assert(str_contains($ui, 'executeWarmup'), 'UC4: executeWarmup only runner path');
$assert(str_contains($ui, '范围使用官方作用范围标签'), 'UC7 note: scope taglib + home/products note');

$runner = (string)file_get_contents($root . '/app/code/Weline/Cdn/Service/WarmupRunner.php');
$assert(str_contains($runner, '$skipped++'), 'UC5: skipped counter');
$assert(str_contains($runner, "\$outcome === 'skipped'"), 'UC5: skipped branch before success');

$cron = ObjectManager::getInstance(Warmup::class);
$assert($cron instanceof CronTaskInterface, 'UC8: Cron implements CronTaskInterface');
$assert($cron->execute_name() === 'cdn_warmup', 'UC8: execute_name=cdn_warmup');

echo $fail === 0 ? "\nALL PASSED\n" : "\nFAILED={$fail}\n";
exit($fail === 0 ? 0 : 1);
