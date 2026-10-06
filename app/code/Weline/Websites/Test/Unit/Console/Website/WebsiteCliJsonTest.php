<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Console\Website;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Console\Website\CliJson;

class WebsiteCliJsonTest extends TestCase
{
    public function testRequestedReadsJsonFlag(): void
    {
        self::assertTrue(CliJson::requested(['json' => true]));
        self::assertTrue(CliJson::requested(['j' => true]));
        self::assertFalse(CliJson::requested(['command' => 'website:listing']));
    }

    public function testEmitWritesStdoutJson(): void
    {
        \ob_start();
        $code = CliJson::emit([['website_id' => 0, 'code' => 'default']]);
        $out = (string)\ob_get_clean();
        self::assertSame(0, $code);
        self::assertSame('[{"website_id":0,"code":"default"}]' . \PHP_EOL, $out);
    }
}
