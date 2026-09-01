<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class LoginStorefrontLinkContractTest extends TestCase
{
    public function testLoginToolbarUsesSameStorefrontLinkAsBackendTopbar(): void
    {
        $login = $this->read('app/code/Weline/Admin/view/templates/Login/index.phtml');
        $topbar = $this->read('app/code/Weline/Admin/view/blocks/backend/public/top-bar.phtml');
        $controller = $this->read('app/code/Weline/Admin/Controller/Login.php');

        self::assertStringContainsString('$storefrontUrl', $login);
        self::assertStringContainsString('w-login-card__toolbar-start', $login);
        self::assertStringContainsString('w-login-card__toolbar-end', $login);
        self::assertStringContainsString('target="_blank"', $login);
        self::assertStringContainsString('rel="noopener noreferrer"', $login);
        self::assertStringContainsString('访问前端', $login);
        self::assertStringContainsString('<w:icon name="globe"', $login);
        self::assertStringContainsString('w-backend-topbar__label', $login);

        self::assertStringContainsString('class="w-button"', $login);
        self::assertStringContainsString('data-tone="quiet"', $login);
        self::assertStringContainsString('data-size="sm"', $login);

        $topbarAnchor = $this->extractStorefrontButtonAnchor($topbar);
        $loginAnchor = $this->extractStorefrontButtonAnchor($login);
        self::assertSame($topbarAnchor, $loginAnchor);

        self::assertStringContainsString('CurrentWebsiteStorefrontUrlProviderInterface', $controller);
        self::assertStringContainsString("'storefront_url'", $controller);
    }

    private function extractStorefrontButtonAnchor(string $source): string
    {
        if (!\preg_match(
            '/\<\?php if \(\$storefrontUrl !== \'\'\): \?\>\s*(<a[\s\S]*?<\/a>)/',
            $source,
            $matches,
        )) {
            self::fail('Storefront button anchor markup not found');

            return '';
        }

        return \preg_replace('/\s+/', ' ', \trim($matches[1])) ?? '';
    }

    private function read(string $path): string
    {
        $content = \file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
