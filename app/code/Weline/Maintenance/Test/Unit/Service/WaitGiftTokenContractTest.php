<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Service\UpgradeWaveService;
use Weline\Maintenance\Service\WaitGiftService;
use Weline\Maintenance\Service\WaitLedger;

final class WaitGiftTokenContractTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = \sys_get_temp_dir() . '/weline_wait_gift_' . \uniqid('', true) . '/';
        @\mkdir($this->tmpRoot . 'var/maintenance', 0775, true);
        @\mkdir($this->tmpRoot . 'app/code/Weline/Framework/etc', 0775, true);
        @\file_put_contents(
            $this->tmpRoot . 'app/code/Weline/Framework/etc/module.php',
            "<?php\nreturn ['name'=>'Weline_Framework','version'=>'9.9.9'];\n"
        );
        // Theme module.php must NOT drive theme_version_*; published theme release is the authority.
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    public function testWaveSnapshotIncludesSystemAndThemeVersions(): void
    {
        $waves = new UpgradeWaveService($this->tmpRoot);
        $wave = $waves->beginWave();
        self::assertNotSame('', (string)($wave['wave_id'] ?? ''));
        self::assertSame('9.9.9', (string)($wave['system_version_to'] ?? ''));
        // Isolated fixture has no ThemePublishedVersionRuntimeResolver publish → unknown (not Theme module semver).
        self::assertSame('unknown', (string)($wave['theme_version_to'] ?? ''));
        self::assertTrue(\is_file($waves->waveFilePath()));

        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/UpgradeWaveService.php');
        self::assertStringContainsString('ThemePublishedVersionRuntimeResolver', $src);
        self::assertStringNotContainsString("Weline/Theme/etc/module.php", $src);
    }

    public function testRedeemWindowIsTenMinutes(): void
    {
        $waves = new UpgradeWaveService($this->tmpRoot);
        $waves->beginWave();
        $recovered = $waves->markRecovered(1_700_000_000);
        self::assertSame(1_700_000_000 + UpgradeWaveService::REDEEM_TTL_SECONDS, (int)($recovered['redeem_deadline_at'] ?? 0));
        self::assertTrue($waves->isRedeemWindowOpen($recovered, 1_700_000_000 + 60));
        self::assertFalse($waves->isRedeemWindowOpen($recovered, 1_700_000_000 + UpgradeWaveService::REDEEM_TTL_SECONDS + 1));
    }

    public function testLedgerStoresHashOnlyAndMarksRedeemedOnce(): void
    {
        $ledger = new WaitLedger($this->tmpRoot);
        $service = new WaitGiftService(new UpgradeWaveService($this->tmpRoot), $ledger);
        $opaque = $service->generateOpaque();
        $hash = $service->hashToken($opaque);
        $ledger->createWaiting($hash, 'wave-test', [
            'cookie_key' => 'bk_test',
            'min_wait_sec' => 0,
        ]);
        $record = $ledger->findByTokenHash($hash);
        self::assertSame(WaitLedger::STATUS_WAITING, (string)($record['status'] ?? ''));
        self::assertSame($hash, (string)($record['token_hash'] ?? ''));
        self::assertStringNotContainsString($opaque, (string)\json_encode($record));

        $ledger->markRedeemed($hash, 'MWTESTCODE');
        $redeemed = $ledger->findByTokenHash($hash);
        self::assertSame(WaitLedger::STATUS_REDEEMED, (string)($redeemed['status'] ?? ''));
        self::assertSame('MWTESTCODE', (string)($redeemed['redeemed_coupon_code'] ?? ''));
    }

    public function testSourceContractsExist(): void
    {
        $root = \dirname(__DIR__, 3);
        self::assertFileExists($root . '/Service/WaitGiftService.php');
        self::assertFileExists($root . '/Controller/Frontend/WaitGift.php');
        self::assertFileExists($root . '/view/statics/js/maintenance.js');
        $js = (string)\file_get_contents($root . '/view/statics/js/maintenance.js');
        self::assertStringContainsString('weline_mw_wait_gift', $js);
        self::assertStringContainsString('/maintenance/frontend/wait-gift/redeem', $js);
        self::assertStringContainsString('window.location.reload()', $js);
        self::assertStringNotContainsString('scheduleHeartbeat', $js);
        self::assertStringNotContainsString('abandonWaitToken', $js);
        self::assertStringNotContainsString('redeemThenReload', $js);

        $frontendJs = (string)\file_get_contents(\dirname($root) . '/Frontend/view/statics/js/weline.js');
        self::assertStringContainsString('weline-maintenance-wait-modal', $frontendJs);
        self::assertStringContainsString('data-w-mw-gift', $frontendJs);
        self::assertStringContainsString('维护补偿礼金', $frontendJs);
        self::assertStringContainsString('/maintenance/frontend/wait-gift/wave', $frontendJs);
        self::assertStringContainsString('#ffd814', $frontendJs);
        self::assertStringContainsString('resolveMaintenanceMeta', $frontendJs);

        $service = (string)\file_get_contents($root . '/Service/WaitGiftService.php');
        self::assertStringContainsString('function redeemHttpStatus', $service);
        self::assertSame(404, WaitGiftService::redeemHttpStatus(['success' => false, 'error' => 'token_not_found']));
        self::assertSame(503, WaitGiftService::redeemHttpStatus(['success' => false, 'error' => 'still_maintaining']));
        self::assertSame(200, WaitGiftService::redeemHttpStatus(['success' => true]));
    }

    private function removeTree(string $dir): void
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
                $this->removeTree(\rtrim($path, '/\\') . '/');
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
