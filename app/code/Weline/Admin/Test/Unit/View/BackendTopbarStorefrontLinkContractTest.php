<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class BackendTopbarStorefrontLinkContractTest extends TestCase
{
    public function testTopbarExposesStorefrontLinkInNewTabBeforeLanguageSwitcher(): void
    {
        $topbar = $this->read('app/code/Weline/Admin/view/blocks/backend/public/top-bar.phtml');
        $block = $this->read('app/code/Weline/Admin/Block/Backend/Page/Topbar.php');

        self::assertStringContainsString('$storefrontUrl', $topbar);
        self::assertStringContainsString('target="_blank"', $topbar);
        self::assertStringContainsString('rel="noopener noreferrer"', $topbar);
        self::assertStringContainsString('访问前端', $topbar);
        self::assertStringContainsString('<w:icon name="globe"', $topbar);

        $switcherPos = (int)\strpos($topbar, '<w:i18n:switcher');
        $linkPos = (int)\strpos($topbar, '访问前端');
        self::assertGreaterThan(0, $switcherPos);
        self::assertGreaterThan(0, $linkPos);
        self::assertLessThan($switcherPos, $linkPos);

        self::assertStringContainsString('CurrentWebsiteStorefrontUrlProviderInterface', $block);
        self::assertStringContainsString("'storefront_url'", $block);
    }

    private function read(string $path): string
    {
        $content = \file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
