<?php

declare(strict_types=1);

namespace Weline\Framework\System\Process;

use Weline\Framework\System\Process\Driver\ProcessDriverFactory;
use Weline\Framework\System\Process\Driver\ProcessDriverInterface;
use Weline\Framework\UnitTest\TestCore;

/**
 * Processer 与进程名规范化的单元测试
 *
 * 不启动真实进程、不执行 kill，仅测试纯逻辑与驱动解析。
 */
class ProcesserTest extends TestCore
{
    protected function tearDown(): void
    {
        ProcessDriverFactory::reset();
        parent::tearDown();
    }

    /* ---------- normalizeName ---------- */

    public function testNormalizeNameEmptyReturnsEmpty(): void
    {
        self::assertSame('', Processer::normalizeName(''));
    }

    public function testNormalizeNameReplacesPunctuationWithDash(): void
    {
        self::assertSame('a-b-c', Processer::normalizeName('a.b.c'));
        self::assertSame('worker-port-9980', Processer::normalizeName('worker.port.9980'));
    }

    public function testNormalizeNameStripsQuotes(): void
    {
        self::assertSame('name', Processer::normalizeName('"name"'));
        self::assertSame('name', Processer::normalizeName("'name'"));
    }

    public function testNormalizeNameCollapsesMultipleDashes(): void
    {
        self::assertSame('a-b', Processer::normalizeName('a---b'));
        self::assertSame('a-b', Processer::normalizeName('a--b'));
    }

    public function testNormalizeNameTrimsLeadingTrailingDashes(): void
    {
        self::assertSame('name', Processer::normalizeName('--name--'));
    }

    public function testNormalizeNameLowercase(): void
    {
        self::assertSame('weline-worker', Processer::normalizeName('Weline-Worker'));
    }

    public function testNormalizeNameTruncatesToMaxLength(): void
    {
        $long = \str_repeat('a', Processer::PROCESS_NAME_MAX_LENGTH + 10);
        $result = Processer::normalizeName($long);
        self::assertLessThanOrEqual(Processer::PROCESS_NAME_MAX_LENGTH, \strlen($result));
    }

    public function testNormalizeNamePortStyle(): void
    {
        self::assertSame('weline-worker-port-9980', Processer::normalizeName('weline-worker-port-9980'));
        self::assertSame('worker-port-9980', Processer::normalizeName('worker.port.9980'));
    }

    /* ---------- generateProcessName ---------- */

    public function testGenerateProcessNameFromCommandWithNameParam(): void
    {
        $cmd = 'php worker.php --port=9980 --name=weline-worker-port-9980';
        self::assertSame('weline-worker-port-9980', Processer::generateProcessName($cmd));
    }

    public function testGenerateProcessNameAddsWelinePrefixWhenMissing(): void
    {
        $cmd = 'php worker.php --name=my-worker';
        self::assertSame('weline-my-worker', Processer::generateProcessName($cmd));
    }

    public function testGenerateProcessNameFromCommandWithoutName(): void
    {
        $cmd = 'php worker.php --port=9980';
        $name = Processer::generateProcessName($cmd);
        self::assertStringStartsWith(Processer::WELINE_PROCESS_PREFIX, $name);
        self::assertStringContainsString('9980', $name);
    }

    public function testGenerateProcessNameEmptyCommandReturnsUnknownWithTimestamp(): void
    {
        $name = Processer::generateProcessName('');
        self::assertStringStartsWith(Processer::WELINE_PROCESS_PREFIX . 'unknown-', $name);
    }

    /* ---------- ensureProcessName ---------- */

    public function testEnsureProcessNameWhenNamePresentLeavesCommandUnchanged(): void
    {
        $cmd = 'php worker.php --port=9980 --name=weline-worker-port-9980';
        $result = Processer::ensureProcessName($cmd);
        self::assertSame($cmd, $result['command']);
        self::assertSame('weline-worker-port-9980', $result['name']);
    }

    public function testEnsureProcessNameWhenNameMissingAppendsName(): void
    {
        $cmd = 'php worker.php --port=9980';
        $result = Processer::ensureProcessName($cmd);
        self::assertStringContainsString('--name=', $result['command']);
        self::assertNotSame($cmd, $result['command']);
        self::assertStringStartsWith(Processer::WELINE_PROCESS_PREFIX, $result['name']);
    }

    public function testEnsureProcessNameShortFormatName(): void
    {
        $cmd = 'php script.php -name=weline-foo';
        $result = Processer::ensureProcessName($cmd);
        self::assertSame($cmd, $result['command']);
        self::assertSame('weline-foo', $result['name']);
    }

    /* ---------- getSearchableIdentifier ---------- */

    public function testGetSearchableIdentifierFromPnameWithNameParam(): void
    {
        $pname = '--name=weline-master-default-worker-1';
        self::assertSame('weline-master-default-worker-1', Processer::getSearchableIdentifier($pname));
    }

    public function testGetSearchableIdentifierFromPureName(): void
    {
        self::assertSame('weline-worker', Processer::getSearchableIdentifier('weline-worker'));
    }

    public function testGetSearchableIdentifierFromCommand(): void
    {
        $cmd = 'php worker.php --port=9980 --name=weline-worker-port-9980';
        self::assertSame('weline-worker-port-9980', Processer::getSearchableIdentifier($cmd));
    }

    /* ---------- Driver (LSP/OCP) ---------- */

    public function testGetDriverReturnsProcessDriverInterface(): void
    {
        $driver = Processer::getDriver();
        self::assertInstanceOf(ProcessDriverInterface::class, $driver);
    }

    public function testGetDriverSupportsCurrentOs(): void
    {
        $driver = Processer::getDriver();
        self::assertTrue($driver->supports(), 'Driver must support current OS');
    }

    public function testGetDriverOsNameNonEmpty(): void
    {
        $driver = Processer::getDriver();
        self::assertNotEmpty($driver->getOsName());
    }

    public function testProcessDriverFactoryIsWindowsOrNot(): void
    {
        $isWin = ProcessDriverFactory::isWindows();
        self::assertIsBool($isWin);
        self::assertSame($isWin, Processer::isWindows());
    }

    public function testProcessDriverFactoryGetRegisteredDrivers(): void
    {
        $drivers = ProcessDriverFactory::getRegisteredDrivers();
        self::assertIsArray($drivers);
        self::assertGreaterThanOrEqual(1, \count($drivers));
        foreach ($drivers as $class) {
            self::assertTrue(\is_subclass_of($class, ProcessDriverInterface::class), "Driver $class must implement interface");
        }
    }

    /* ---------- Constants ---------- */

    public function testWelineProcessPrefixConstant(): void
    {
        self::assertSame('weline-', Processer::WELINE_PROCESS_PREFIX);
    }

    public function testProcessNameMaxLengthConstant(): void
    {
        self::assertGreaterThan(0, Processer::PROCESS_NAME_MAX_LENGTH);
    }
}
