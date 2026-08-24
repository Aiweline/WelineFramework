<?php

declare(strict_types=1);

namespace Weline\ThemeFancy\Test\Unit\Controller\Template;

use PHPUnit\Framework\TestCase;
use Weline\ThemeFancy\Controller\Template\Fancy;

class FancyTest extends TestCase
{
    public function testResolveTemplateFileDefaultsToIndexHtml(): void
    {
        $controller = new Fancy();

        $this->assertSame(
            'templates/Template/Fancy/index.html',
            $this->resolveTemplateFile($controller, '')
        );
    }

    public function testResolveTemplateFileKeepsKnownHtmlPages(): void
    {
        $controller = new Fancy();

        $this->assertSame(
            'templates/Template/Fancy/shop.html',
            $this->resolveTemplateFile($controller, 'shop.html')
        );
    }

    public function testResolveTemplateFileRejectsPathTraversal(): void
    {
        $controller = new Fancy();

        $this->assertSame('', $this->resolveTemplateFile($controller, '../shop.html'));
        $this->assertSame('', $this->resolveTemplateFile($controller, 'Template/Fancy/shop.html'));
    }

    private function resolveTemplateFile(Fancy $controller, string $method): string
    {
        $reflection = new \ReflectionMethod(Fancy::class, 'resolveTemplateFile');
        $reflection->setAccessible(true);

        return (string)$reflection->invoke($controller, $method);
    }
}
