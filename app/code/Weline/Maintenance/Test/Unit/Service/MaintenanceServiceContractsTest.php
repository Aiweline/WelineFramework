<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Service\MaintenanceContactEmailResolver;
use Weline\Maintenance\Service\UpgradeWaveService;
use Weline\Maintenance\Service\WaitGiftService;
use Weline\Maintenance\Service\WaitLedger;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllMaintenanceServiceContractsPass().
 */
final class MaintenanceServiceContractsTest extends TestCase
{
    public function testAllMaintenanceServiceContractsPass(): void
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
            'MaintenanceContactEmailResolver::testConfigKeyAndModuleAreStable' => function () {
            $this->assert_MaintenanceContactEmailResolver_testConfigKeyAndModuleAreStable();
        },
            'MaintenanceContactEmailResolver::testResolverSourceUsesDedicatedConfigFirst' => function () {
            $this->assert_MaintenanceContactEmailResolver_testResolverSourceUsesDedicatedConfigFirst();
        },
            'MaintenanceContactEmailResolver::testStaticGeneratorUsesResolver' => function () {
            $this->assert_MaintenanceContactEmailResolver_testStaticGeneratorUsesResolver();
        },
            'MaintenanceStaticLocaleCopy::testGeneratorDoesNotFallbackToChineseCsvForNonChineseLocales' => function () {
            $this->assert_MaintenanceStaticLocaleCopy_testGeneratorDoesNotFallbackToChineseCsvForNonChineseLocales();
        },
            'MaintenanceStaticLocaleCopy::testPublicSurfaceCatalogsAreNotChineseIdentity' => function () {
            $this->assert_MaintenanceStaticLocaleCopy_testPublicSurfaceCatalogsAreNotChineseIdentity();
        },
            'MaintenanceStaticLocaleCopy::testBengaliPublicSurfaceUsesBengaliScript' => function () {
            $this->assert_MaintenanceStaticLocaleCopy_testBengaliPublicSurfaceUsesBengaliScript();
        },
            'UpgradeWaveCloseExpired::testCloseExpiredWaveMarksStaleRedeemWindowClosed' => function () {
            $this->setUp_UpgradeWaveCloseExpired();
            try {
                $this->assert_UpgradeWaveCloseExpired_testCloseExpiredWaveMarksStaleRedeemWindowClosed();
            } finally {
                $this->tearDown_UpgradeWaveCloseExpired();
            }
        },
            'UpgradeWaveCloseExpired::testCloseExpiredWaveLeavesOpenWindowUntouched' => function () {
            $this->setUp_UpgradeWaveCloseExpired();
            try {
                $this->assert_UpgradeWaveCloseExpired_testCloseExpiredWaveLeavesOpenWindowUntouched();
            } finally {
                $this->tearDown_UpgradeWaveCloseExpired();
            }
        },
            'WaitGiftToken::testWaveSnapshotIncludesSystemAndThemeVersions' => function () {
            $this->setUp_WaitGiftToken();
            try {
                $this->assert_WaitGiftToken_testWaveSnapshotIncludesSystemAndThemeVersions();
            } finally {
                $this->tearDown_WaitGiftToken();
            }
        },
            'WaitGiftToken::testRedeemWindowIsTenMinutes' => function () {
            $this->setUp_WaitGiftToken();
            try {
                $this->assert_WaitGiftToken_testRedeemWindowIsTenMinutes();
            } finally {
                $this->tearDown_WaitGiftToken();
            }
        },
            'WaitGiftToken::testLedgerStoresHashOnlyAndMarksRedeemedOnce' => function () {
            $this->setUp_WaitGiftToken();
            try {
                $this->assert_WaitGiftToken_testLedgerStoresHashOnlyAndMarksRedeemedOnce();
            } finally {
                $this->tearDown_WaitGiftToken();
            }
        },
            'WaitGiftToken::testSourceContractsExist' => function () {
            $this->setUp_WaitGiftToken();
            try {
                $this->assert_WaitGiftToken_testSourceContractsExist();
            } finally {
                $this->tearDown_WaitGiftToken();
            }
        },
            'WaitGiftToken::testTocAudienceRejectsWholesaleCookie' => function () {
            $this->setUp_WaitGiftToken();
            try {
                $this->assert_WaitGiftToken_testTocAudienceRejectsWholesaleCookie();
            } finally {
                $this->tearDown_WaitGiftToken();
            }
        },
            'MaintenanceModeToggle::testLayeredApiAndCliThinWrappers' => function () {
            $this->assert_MaintenanceModeToggle_testLayeredApiAndCliThinWrappers();
        },
        ];
    }

private function assert_MaintenanceModeToggle_testLayeredApiAndCliThinWrappers(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Maintenance';
    $toggle = (string) file_get_contents($base . '/Service/MaintenanceModeToggleService.php');
    self::assertStringContainsString('function enableRuntimeGate', $toggle);
    self::assertStringContainsString('function disableRuntimeGate', $toggle);
    self::assertStringContainsString('function enableFull', $toggle);
    self::assertStringContainsString('function disableFull', $toggle);
    self::assertStringContainsString('WlsMaintenanceSync::syncAfterCliToggle', $toggle);
    self::assertStringContainsString('beginWave', $toggle);
    // runtime gate must not start upgrade wave (full owns wave)
    $runtimeStart = strpos($toggle, 'function enableRuntimeGate');
    $fullStart = strpos($toggle, 'function enableFull');
    self::assertNotFalse($runtimeStart);
    self::assertNotFalse($fullStart);
    $runtimeBlock = substr($toggle, (int)$runtimeStart, (int)$fullStart - (int)$runtimeStart);
    self::assertStringNotContainsString('beginWave', $runtimeBlock);
    self::assertStringNotContainsString('WaitGiftCampaignSyncService', $runtimeBlock);

    $enable = (string) file_get_contents($base . '/Console/Maintenance/Enable.php');
    $disable = (string) file_get_contents($base . '/Console/Maintenance/Disable.php');
    self::assertStringContainsString('enableFull', $enable);
    self::assertStringContainsString('disableFull', $disable);
    self::assertStringNotContainsString('beginWave', $enable);
    self::assertStringNotContainsString('UpgradeWaveService', $disable);
}

private function assert_MaintenanceContactEmailResolver_testConfigKeyAndModuleAreStable(): void
{
    self::assertSame('Weline_Maintenance', MaintenanceContactEmailResolver::MODULE);
    self::assertSame('maintenance/developer_email', MaintenanceContactEmailResolver::CONFIG_KEY);
    self::assertSame('support@example.com', MaintenanceContactEmailResolver::DEFAULT_EMAIL);
}

private function assert_MaintenanceContactEmailResolver_testResolverSourceUsesDedicatedConfigFirst(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $source = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Service/MaintenanceContactEmailResolver.php');
    self::assertStringContainsString("self::CONFIG_KEY", $source);
    self::assertStringContainsString('BackendConfigStore', $source);
    self::assertStringContainsString('contact_email', $source);
    self::assertStringContainsString('FILTER_VALIDATE_EMAIL', $source);
}

private function assert_MaintenanceContactEmailResolver_testStaticGeneratorUsesResolver(): void
{
    $root = '/Users/weline/Project/Official/框架';
    $source = (string) \file_get_contents($root . '/app/code/Weline/Maintenance/Service/MaintenanceStaticGenerator.php');
    self::assertStringContainsString('MaintenanceContactEmailResolver::class', $source);
    self::assertStringNotContainsString("Env::getInstance()->getConfig('contact_email'", $source);
}

/** @var list<string> */
private const PUBLIC_SOURCES_MaintenanceStaticLocaleCopy = ['系统升级维护中', '网站正在维护中...', '请稍等片刻', '正在尝试现场抢修，恢复后会自动进入，请耐心等待', '为什么网站会处于维护模式？', '多久能够恢复？', '需要帮助吗？', '返回首页', '刷新当前页面'];

/** @var list<string> */
private const TARGET_LOCALES_MaintenanceStaticLocaleCopy = ['bn_BD', 'hi_IN', 'ar_SA', 'es_ES', 'fr_FR', 'id_ID', 'pt_BR', 'ur_PK', 'de_DE', 'ja_JP', 'ko_KR', 'th_TH', 'vi_VN', 'ru_RU', 'zh_Hant_TW'];

private function assert_MaintenanceStaticLocaleCopy_testGeneratorDoesNotFallbackToChineseCsvForNonChineseLocales(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/Service/MaintenanceStaticGenerator.php');
    self::assertStringContainsString('function isUsableTranslation', $source);
    self::assertStringContainsString('function readModuleCsv', $source);
    self::assertStringNotContainsString("i18nFile = BP . 'app/code/Weline/Maintenance/i18n/' . self::DEFAULT_LANG . '.csv'", $source);
    self::assertStringNotContainsString('loadModuleTranslations', $source);
}

private function assert_MaintenanceStaticLocaleCopy_testPublicSurfaceCatalogsAreNotChineseIdentity(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/i18n';
    foreach (self::TARGET_LOCALES_MaintenanceStaticLocaleCopy as $locale) {
        $path = $root . '/' . $locale . '.csv';
        self::assertFileExists($path, "Missing Maintenance {$locale}.csv");
        $catalog = $this->loadCatalog_MaintenanceStaticLocaleCopy($path);
        foreach (self::PUBLIC_SOURCES_MaintenanceStaticLocaleCopy as $source) {
            self::assertArrayHasKey($source, $catalog, "Missing {$locale}: {$source}");
            $translated = trim($catalog[$source]);
            self::assertNotSame('', $translated, "Empty {$locale}: {$source}");
            // Non-Chinese locales must not keep the Simplified Chinese source as identity.
            // Japanese/Korean/Traditional may still use Han characters legitimately.
            if (!str_starts_with($locale, 'zh')) {
                self::assertNotSame($source, $translated, "Untranslated {$locale}: {$source}");
            }
        }
    }
}

private function assert_MaintenanceStaticLocaleCopy_testBengaliPublicSurfaceUsesBengaliScript(): void
{
    $catalog = $this->loadCatalog_MaintenanceStaticLocaleCopy('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/i18n/bn_BD.csv');
    self::assertSame('সিস্টেম আপগ্রেড চলছে', $catalog['系统升级维护中'] ?? '');
    self::assertMatchesRegularExpression('/\p{Bengali}/u', $catalog['系统升级维护中']);
    self::assertMatchesRegularExpression('/\p{Bengali}/u', $catalog['返回首页'] ?? '');
    self::assertMatchesRegularExpression('/\p{Bengali}/u', $catalog['刷新当前页面'] ?? '');
}

/** @return array<string, string> */
private function loadCatalog_MaintenanceStaticLocaleCopy(string $path): array
{
    $handle = fopen($path, 'rb');
    self::assertIsResource($handle, "Unable to open {$path}");
    $bom = fread($handle, 3);
    if ($bom !== "﻿") {
        rewind($handle);
    }
    $catalog = [];
    while (($row = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
        $source = trim((string) ($row[0] ?? ''));
        if ($source === '') {
            continue;
        }
        $catalog[$source] = (string) ($row[1] ?? '');
    }
    fclose($handle);
    return $catalog;
}

private string $tmpRoot_UpgradeWaveCloseExpired = '';

private function setUp_UpgradeWaveCloseExpired(): void
{
    $this->tmpRoot_UpgradeWaveCloseExpired = \sys_get_temp_dir() . '/weline-mw-wave-' . \bin2hex(\random_bytes(4));
    \mkdir($this->tmpRoot_UpgradeWaveCloseExpired . '/var/maintenance', 0775, true);
}

private function tearDown_UpgradeWaveCloseExpired(): void
{
    $file = $this->tmpRoot_UpgradeWaveCloseExpired . '/' . UpgradeWaveService::WAVE_FILE;
    if (\is_file($file)) {
        @\unlink($file);
    }
    @\rmdir($this->tmpRoot_UpgradeWaveCloseExpired . '/var/maintenance');
    @\rmdir($this->tmpRoot_UpgradeWaveCloseExpired . '/var');
    @\rmdir($this->tmpRoot_UpgradeWaveCloseExpired);
}

private function assert_UpgradeWaveCloseExpired_testCloseExpiredWaveMarksStaleRedeemWindowClosed(): void
{
    $service = new UpgradeWaveService($this->tmpRoot_UpgradeWaveCloseExpired);
    $now = 1790000000;
    $service->writeWave(['wave_id' => 'test', 'status' => 'redeem_window', 'recovered_at' => $now - 900, 'redeem_deadline_at' => $now - 100, 'wait_gift_enabled' => true]);
    $closed = $service->closeExpiredWave($now);
    self::assertNotNull($closed);
    self::assertSame('closed', $closed['status'] ?? null);
    self::assertFalse($service->isRedeemWindowOpen($closed, $now));
}

private function assert_UpgradeWaveCloseExpired_testCloseExpiredWaveLeavesOpenWindowUntouched(): void
{
    $service = new UpgradeWaveService($this->tmpRoot_UpgradeWaveCloseExpired);
    $now = 1790000000;
    $service->writeWave(['wave_id' => 'test-open', 'status' => 'redeem_window', 'recovered_at' => $now - 60, 'redeem_deadline_at' => $now + 500, 'wait_gift_enabled' => true]);
    $wave = $service->closeExpiredWave($now);
    self::assertNotNull($wave);
    self::assertSame('redeem_window', $wave['status'] ?? null);
}

private string $tmpRoot_WaitGiftToken = '';

private function setUp_WaitGiftToken(): void
{
    parent::setUp();
    $this->tmpRoot_WaitGiftToken = \sys_get_temp_dir() . '/weline_wait_gift_' . \uniqid('', true) . '/';
    @\mkdir($this->tmpRoot_WaitGiftToken . 'var/maintenance', 0775, true);
    @\mkdir($this->tmpRoot_WaitGiftToken . 'app/code/Weline/Framework/etc', 0775, true);
    @\file_put_contents($this->tmpRoot_WaitGiftToken . 'app/code/Weline/Framework/etc/module.php', "<?php\nreturn ['name'=>'Weline_Framework','version'=>'9.9.9'];\n");
    // Theme module.php must NOT drive theme_version_*; published theme release is the authority.
}

private function tearDown_WaitGiftToken(): void
{
    $this->removeTree_WaitGiftToken($this->tmpRoot_WaitGiftToken);
    parent::tearDown();
}

private function assert_WaitGiftToken_testWaveSnapshotIncludesSystemAndThemeVersions(): void
{
    $waves = new UpgradeWaveService($this->tmpRoot_WaitGiftToken);
    $wave = $waves->beginWave();
    self::assertNotSame('', (string) ($wave['wave_id'] ?? ''));
    self::assertSame('9.9.9', (string) ($wave['system_version_to'] ?? ''));
    // Isolated fixture has no ThemePublishedVersionRuntimeResolver publish → unknown (not Theme module semver).
    self::assertSame('unknown', (string) ($wave['theme_version_to'] ?? ''));
    self::assertTrue(\is_file($waves->waveFilePath()));
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Maintenance' . '/Service/UpgradeWaveService.php');
    self::assertStringContainsString('ThemePublishedVersionRuntimeResolver', $src);
    self::assertStringNotContainsString("Weline/Theme/etc/module.php", $src);
}

private function assert_WaitGiftToken_testRedeemWindowIsTenMinutes(): void
{
    $waves = new UpgradeWaveService($this->tmpRoot_WaitGiftToken);
    $waves->beginWave();
    $recovered = $waves->markRecovered(1700000000);
    self::assertSame(1700000000 + UpgradeWaveService::REDEEM_TTL_SECONDS, (int) ($recovered['redeem_deadline_at'] ?? 0));
    self::assertTrue($waves->isRedeemWindowOpen($recovered, 1700000000 + 60));
    self::assertFalse($waves->isRedeemWindowOpen($recovered, 1700000000 + UpgradeWaveService::REDEEM_TTL_SECONDS + 1));
}

private function assert_WaitGiftToken_testLedgerStoresHashOnlyAndMarksRedeemedOnce(): void
{
    $ledger = new WaitLedger($this->tmpRoot_WaitGiftToken);
    $service = new WaitGiftService(new UpgradeWaveService($this->tmpRoot_WaitGiftToken), $ledger);
    $opaque = $service->generateOpaque();
    $hash = $service->hashToken($opaque);
    $ledger->createWaiting($hash, 'wave-test', ['cookie_key' => 'bk_test', 'min_wait_sec' => 0]);
    $record = $ledger->findByTokenHash($hash);
    self::assertSame(WaitLedger::STATUS_WAITING, (string) ($record['status'] ?? ''));
    self::assertSame($hash, (string) ($record['token_hash'] ?? ''));
    self::assertStringNotContainsString($opaque, (string) \json_encode($record));
    $ledger->markRedeemed($hash, 'MWTESTCODE');
    $redeemed = $ledger->findByTokenHash($hash);
    self::assertSame(WaitLedger::STATUS_REDEEMED, (string) ($redeemed['status'] ?? ''));
    self::assertSame('MWTESTCODE', (string) ($redeemed['redeemed_coupon_code'] ?? ''));
}

private function assert_WaitGiftToken_testSourceContractsExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Maintenance';
    self::assertFileExists($root . '/Service/WaitGiftService.php');
    self::assertFileExists($root . '/Controller/Frontend/WaitGift.php');
    self::assertFileExists($root . '/view/statics/js/maintenance.js');
    $js = (string) \file_get_contents($root . '/view/statics/js/maintenance.js');
    self::assertStringContainsString('weline_mw_wait_gift', $js);
    self::assertStringContainsString('/maintenance/frontend/wait-gift/redeem', $js);
    self::assertStringContainsString('scrubWholesaleGiftCopy', $js);
    self::assertStringContainsString('weline_selling_mode', $js);
    self::assertStringContainsString('window.location.reload()', $js);
    self::assertStringNotContainsString('scheduleHeartbeat', $js);
    self::assertStringNotContainsString('abandonWaitToken', $js);
    self::assertStringNotContainsString('redeemThenReload', $js);
    $frontendJs = (string) \file_get_contents(\dirname($root) . '/Frontend/view/statics/js/weline.js');
    self::assertStringContainsString('maintenanceAsyncWait', $frontendJs);
    self::assertStringContainsString('installMaintenanceHandlerLazyBridge', $frontendJs);
    self::assertStringNotContainsString('weline-maintenance-wait-modal', $frontendJs);
    self::assertStringNotContainsString('resolveMaintenanceMeta', $frontendJs);
    $asyncJs = (string) \file_get_contents($root . '/view/statics/js/maintenance-async-wait.js');
    self::assertStringContainsString('weline-maintenance-wait-modal', $asyncJs);
    self::assertStringContainsString('data-w-mw-gift', $asyncJs);
    self::assertStringContainsString('维护补偿礼金', $asyncJs);
    self::assertStringContainsString('/maintenance/frontend/wait-gift/wave', $asyncJs);
    self::assertStringContainsString('#ffd814', $asyncJs);
    self::assertStringContainsString('resolveMaintenanceMeta', $asyncJs);
    self::assertStringContainsString('isTocAudience', $asyncJs);
    self::assertStringContainsString('weline.cart.pending_coupon', $asyncJs);
    self::assertStringContainsString('weline:cart:apply-coupon', $asyncJs);
    self::assertStringContainsString('wait_gift_eligible', $asyncJs);
    self::assertFileExists($root . '/view/statics/frontend/weline.modules.js');
    $modules = (string) \file_get_contents($root . '/view/statics/frontend/weline.modules.js');
    self::assertStringContainsString('maintenanceAsyncWait', $modules);
    self::assertStringContainsString('WelineMaintenanceAsyncWait', $modules);
    $service = (string) \file_get_contents($root . '/Service/WaitGiftService.php');
    self::assertStringContainsString('function redeemHttpStatus', $service);
    self::assertStringContainsString('function isTocAudience', $service);
    self::assertStringContainsString('audience_tob', $service);
    self::assertSame(404, WaitGiftService::redeemHttpStatus(['success' => false, 'error' => 'token_not_found']));
    self::assertSame(503, WaitGiftService::redeemHttpStatus(['success' => false, 'error' => 'still_maintaining']));
    self::assertSame(200, WaitGiftService::redeemHttpStatus(['success' => true]));
}

private function assert_WaitGiftToken_testTocAudienceRejectsWholesaleCookie(): void
{
    $service = new WaitGiftService(new UpgradeWaveService($this->tmpRoot_WaitGiftToken), new WaitLedger($this->tmpRoot_WaitGiftToken));
    self::assertTrue($service->isTocAudience([]));
    self::assertTrue($service->isTocAudience(['selling_mode' => 'toc']));
    self::assertFalse($service->isTocAudience(['selling_mode' => 'tob']));
    self::assertFalse($service->isTocAudience(['cookies' => ['weline_selling_mode_w1' => 'tob']]));
    self::assertTrue($service->isTocAudience(['cookies' => ['weline_selling_mode_w1' => 'toc']]));
    self::assertSame('tob', $service->resolveSellingMode(['cookies' => ['weline_selling_mode' => 'toc', 'weline_selling_mode_w1' => 'tob']]));
}

private function removeTree_WaitGiftToken(string $dir): void
{
    if ($dir === '' || !\is_dir($dir)) {
        return;
    }
    $items = \scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . $item;
        if (\is_dir($path)) {
            $this->removeTree_WaitGiftToken(\rtrim($path, '/\\') . '/');
        } else {
            @\unlink($path);
        }
    }
    @\rmdir($dir);
}
}
