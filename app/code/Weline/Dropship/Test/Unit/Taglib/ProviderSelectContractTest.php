<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Taglib\ProviderSelect;

final class ProviderSelectContractTest extends TestCase
{
    public function testTagNameAndMultipleDefaults(): void
    {
        self::assertSame('dropship:provider:select', ProviderSelect::name());
        self::assertTrue(ProviderSelect::attr()['id']);
        self::assertFalse(ProviderSelect::attr()['multiple']);
        self::assertFalse(ProviderSelect::attr()['auto-submit']);
        self::assertFalse(ProviderSelect::attr()['options-json']);
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Taglib/ProviderSelect.php');
        self::assertStringContainsString('SearchSelect::class', $src);
        self::assertStringContainsString('::buildMarkup([', $src);
        self::assertStringContainsString("'multiple' =>", $src);
        self::assertStringContainsString('auto-submit', $src);
        self::assertStringContainsString('留空=全部', $src);
    }
}
