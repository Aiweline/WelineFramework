<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Backend\Service\ChannelAdapterCollector;
use Weline\Framework\Contract\InterfaceSourceContractProbe;

/**
 * 注册表内全部 ChannelAdapter 必须在源码层满足接口方法（防 Worker Fatal）。
 */
final class ChannelAdapterInterfaceContractTest extends TestCase
{
    public function testAllRegisteredAdaptersSatisfyInterfaceSourceContract(): void
    {
        $extendsFile = \dirname(__DIR__, 3) . '/extends.php';
        self::assertFileExists($extendsFile);

        $config = include $extendsFile;
        $classes = $config[ChannelAdapterInterface::class] ?? [];
        self::assertIsArray($classes);
        self::assertNotEmpty($classes);

        foreach ($classes as $class) {
            self::assertIsString($class);
            $probe = InterfaceSourceContractProbe::check(ChannelAdapterInterface::class, $class);
            self::assertTrue(
                $probe['ok'],
                $class . ' missing=[' . \implode(', ', $probe['missing']) . '] note=' . $probe['note']
            );
        }
    }

    public function testCollectorValidateRegisteredContractsReportsOk(): void
    {
        ChannelAdapterCollector::resetCache();
        $collector = new ChannelAdapterCollector();
        $rows = $collector->validateRegisteredContracts();
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertTrue(
                $row['ok'],
                ($row['class'] ?? '') . ' missing=[' . \implode(', ', $row['missing'] ?? []) . ']'
            );
        }
    }
}
