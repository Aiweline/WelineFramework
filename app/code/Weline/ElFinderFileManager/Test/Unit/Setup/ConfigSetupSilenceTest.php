<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

class ConfigSetupSilenceTest extends TestCase
{
    public function testConfigDoesNotEmitShellCopyErrorsWhenVendorAssetsAreAbsent(): void
    {
        ob_start();
        $result = include dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config.php';
        $output = ob_get_clean();

        $this->assertTrue($result);
        $this->assertSame('', $output);
    }
}
