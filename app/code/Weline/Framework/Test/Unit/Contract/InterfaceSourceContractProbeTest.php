<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Contract;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Contract\InterfaceSourceContractProbe;

final class InterfaceSourceContractProbeTest extends TestCase
{
    public function testDetectsMissingInterfaceMethodWithoutLoadingImplementation(): void
    {
        $tmp = \sys_get_temp_dir() . '/weline_iface_probe_' . \bin2hex(\random_bytes(4)) . '.php';
        \file_put_contents($tmp, <<<'PHP'
<?php
namespace Weline\Framework\Test\Unit\Contract\Fixture;
class IncompleteAdapter
{
    public function getChannelCode(): string { return 'x'; }
}
PHP);

        $probe = InterfaceSourceContractProbe::check(
            \Weline\Backend\Api\Notification\ChannelAdapterInterface::class,
            'Weline\\Framework\\Test\\Unit\\Contract\\Fixture\\IncompleteAdapter',
            $tmp
        );

        @\unlink($tmp);

        self::assertFalse($probe['ok']);
        self::assertContains('test', $probe['missing']);
        self::assertContains('send', $probe['missing']);
        self::assertSame('missing_interface_methods', $probe['note']);
    }

    public function testPassesWhenAllInterfaceMethodsPresentInSource(): void
    {
        $file = InterfaceSourceContractProbe::resolveClassFile(
            \Weline\Backend\Adapter\Notification\WebhookAdapter::class
        );
        self::assertNotNull($file);

        $probe = InterfaceSourceContractProbe::check(
            \Weline\Backend\Api\Notification\ChannelAdapterInterface::class,
            \Weline\Backend\Adapter\Notification\WebhookAdapter::class,
            $file
        );

        self::assertTrue($probe['ok'], 'missing=' . \implode(',', $probe['missing']));
        self::assertSame([], $probe['missing']);
    }
}
