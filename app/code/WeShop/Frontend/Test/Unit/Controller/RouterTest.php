<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Frontend\Controller\Router;

class RouterTest extends TestCase
{
    public function testRootPathRewritesToWeshopForWeshopTheme(): void
    {
        $this->assertTrue($this->invokeShouldRewrite('', false, false, [
            'name' => 'weshop-motor',
            'path' => 'WeShop\\motor',
        ]));
    }

    public function testPreviewThemeRootStaysAvailableForThemePreviewGateway(): void
    {
        $this->assertFalse($this->invokeShouldRewrite('', false, true, [
            'name' => 'weshop-motor',
            'path' => 'WeShop\\motor',
        ]));
    }

    public function testNonWeshopThemeDoesNotRewriteRootPath(): void
    {
        $this->assertFalse($this->invokeShouldRewrite('', false, false, [
            'name' => 'default',
            'path' => 'Weline\\default',
        ]));
    }

    /**
     * @param array<string, mixed> $activeTheme
     */
    private function invokeShouldRewrite(
        string $normalizedPath,
        bool $isBackend,
        bool $hasPreviewTheme,
        array $activeTheme
    ): bool {
        $method = new \ReflectionMethod(Router::class, 'shouldRewriteRootToWeShop');
        $method->setAccessible(true);

        return (bool)$method->invoke(null, $normalizedPath, $isBackend, $hasPreviewTheme, $activeTheme);
    }
}
