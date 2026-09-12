<?php

declare(strict_types=1);

namespace Weline\HelpPay\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

final class HelpPayFrontendLayoutContractTest extends TestCase
{
    /** @return list<string> */
    private function controllers(): array
    {
        $base = dirname(__DIR__, 3) . '/Controller/Frontend';

        return [
            $base . '/QuickPay.php',
            $base . '/Payer.php',
            $base . '/SelectionShare.php',
        ];
    }

    public function testShortLinkControllersSetDefaultLayoutType(): void
    {
        foreach ($this->controllers() as $file) {
            self::assertFileExists($file);
            $src = (string) file_get_contents($file);
            self::assertStringContainsString('ThemeLayout::PAGE_TYPE_DEFAULT', $src, $file);
            self::assertStringContainsString('$this->layoutType = ThemeLayout::PAGE_TYPE_DEFAULT', $src, $file);
        }
    }
}
