<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\EarlyDatabaseAccessGate;
use Weline\Framework\Setup\Lock\SetupDatabaseAccessLock;
use Weline\Framework\Setup\Lock\SetupUpgradeIntent;

final class EarlyDatabaseAccessGateUpgradeWaitContractTest extends TestCase
{
    /** @var resource|null */
    private $sharedHolder = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!\defined('BP')) {
            $dir = __DIR__;
            while ($dir !== \dirname($dir)) {
                if (\is_file($dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w')) {
                    \define('BP', $dir . DIRECTORY_SEPARATOR);
                    break;
                }
                $dir = \dirname($dir);
            }
        }
        self::assertTrue(\defined('BP'), 'BP must resolve to repository root');
        require_once \BP . 'app/code/Weline/Framework/Setup/Lock/SetupDatabaseAccessLock.php';
        require_once \BP . 'app/code/Weline/Framework/Setup/Lock/SetupUpgradeIntent.php';
        require_once \BP . 'app/code/Weline/Framework/Phrase/DatabaseFreeTranslator.php';
        require_once \BP . 'app/code/Weline/Framework/Console/EarlyDatabaseAccessGate.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        SetupDatabaseAccessLock::releaseCliBootstrapLease();
        SetupUpgradeIntent::clearIfOwner();
        $intent = SetupUpgradeIntent::path();
        if (is_file($intent)) {
            @unlink($intent);
        }
        SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->sharedHolder)) {
            @flock($this->sharedHolder, LOCK_UN);
            @fclose($this->sharedHolder);
            $this->sharedHolder = null;
        }
        SetupDatabaseAccessLock::releaseCliBootstrapLease();
        SetupUpgradeIntent::clearIfOwner();
        $intent = SetupUpgradeIntent::path();
        if (is_file($intent)) {
            @unlink($intent);
        }
        SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
        parent::tearDown();
    }

    public function testSetupWithoutForceWaitsAndTimesOutWhileSharedHeld(): void
    {
        $lockPath = SetupDatabaseAccessLock::path();
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->sharedHolder = fopen($lockPath, 'c+b');
        self::assertIsResource($this->sharedHolder);
        self::assertTrue(flock($this->sharedHolder, LOCK_SH | LOCK_NB));

        SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(600);
        // No -f: cooperative wait path.
        $exit = EarlyDatabaseAccessGate::prepare(['bin/w', 's:up'], 600);

        self::assertSame(75, $exit);
        self::assertFalse(SetupUpgradeIntent::isOwnedByCurrentProcess());
        self::assertFalse(SetupUpgradeIntent::isActive());
    }

    public function testForceDoesNotUseLongWaitWhenSharedHeldByNonCron(): void
    {
        $lockPath = SetupDatabaseAccessLock::path();
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->sharedHolder = fopen($lockPath, 'c+b');
        self::assertIsResource($this->sharedHolder);
        self::assertTrue(flock($this->sharedHolder, LOCK_SH | LOCK_NB));

        $started = hrtime(true);
        // -f: force path (~5s), must not fall into 900s cooperative wait.
        $exit = EarlyDatabaseAccessGate::prepare(['bin/w', 's:up', '-f']);
        $elapsedMs = (int)((hrtime(true) - $started) / 1_000_000);

        self::assertSame(75, $exit);
        self::assertLessThan(12_000, $elapsedMs);
        self::assertFalse(SetupUpgradeIntent::isActive());
    }

    public function testHotSetupDoesNotPublishIntent(): void
    {
        $exit = EarlyDatabaseAccessGate::prepare(['bin/w', 's:up', '--hot']);
        self::assertNull($exit);
        self::assertFalse(SetupUpgradeIntent::isActive());
        SetupDatabaseAccessLock::releaseCliBootstrapLease();
    }

    public function testForcePathIsDocumentedInGateSource(): void
    {
        $src = (string)file_get_contents(\BP . 'app/code/Weline/Framework/Console/EarlyDatabaseAccessGate.php');
        self::assertStringContainsString('acquireExclusiveForced', $src);
        self::assertStringContainsString('terminateCronLockHolders', $src);
        self::assertStringContainsString('FORCE_EXCLUSIVE_WAIT_MS', $src);
    }
}
