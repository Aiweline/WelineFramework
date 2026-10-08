<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Env;

use PHPUnit\Framework\TestCase;

/**
 * Linux PHP rebuild must always pass --enable-pcntl even when requirements.php lists other exts.
 */
final class InstallPhpPcntlConfigureContractTest extends TestCase
{
    public function testRequiredExtensionsAlwaysIncludePcntl(): void
    {
        $bash = (string)file_get_contents(dirname(__DIR__, 7) . '/bin/install.bash');
        self::assertStringContainsString('exts="$exts pcntl opcache sodium"', $bash);
        self::assertStringContainsString('pcntl)      echo "--enable-pcntl"', $bash);
        self::assertStringContainsString('WLS/多进程', $bash);
    }
}
