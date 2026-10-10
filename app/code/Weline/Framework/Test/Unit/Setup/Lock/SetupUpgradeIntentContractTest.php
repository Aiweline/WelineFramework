<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Lock;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Setup\Lock\SetupUpgradeIntent;

final class SetupUpgradeIntentContractTest extends TestCase
{
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
    }

    protected function setUp(): void
    {
        parent::setUp();
        SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
        SetupUpgradeIntent::clearIfOwner();
        $path = SetupUpgradeIntent::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    protected function tearDown(): void
    {
        SetupUpgradeIntent::clearIfOwner();
        $path = SetupUpgradeIntent::path();
        if (is_file($path)) {
            @unlink($path);
        }
        SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
        parent::tearDown();
    }

    public function testPublishOwnsAndClearIfOwner(): void
    {
        self::assertFalse(SetupUpgradeIntent::isActive());
        self::assertTrue(SetupUpgradeIntent::publish('setup:upgrade'));
        self::assertTrue(SetupUpgradeIntent::isActive());
        self::assertTrue(SetupUpgradeIntent::isOwnedByCurrentProcess());
        self::assertTrue(SetupUpgradeIntent::shouldYield());

        SetupUpgradeIntent::clearIfOwner();
        self::assertFalse(SetupUpgradeIntent::isActive());
        self::assertFileDoesNotExist(SetupUpgradeIntent::path());
    }

    public function testDeadPidIntentIsReclaimed(): void
    {
        $path = SetupUpgradeIntent::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode([
            'pid' => 999999991,
            'started_at' => microtime(true),
            'command' => 'setup:upgrade',
        ], JSON_UNESCAPED_UNICODE) . "\n");

        self::assertFalse(SetupUpgradeIntent::isActive());
        self::assertFileDoesNotExist($path);
    }

    public function testExclusiveWaitOverride(): void
    {
        SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(1234);
        self::assertSame(1234, SetupUpgradeIntent::exclusiveWaitMilliseconds());
        SetupUpgradeIntent::setTestExclusiveWaitMilliseconds(null);
        self::assertSame(SetupUpgradeIntent::DEFAULT_EXCLUSIVE_WAIT_MS, SetupUpgradeIntent::exclusiveWaitMilliseconds());
    }
}
