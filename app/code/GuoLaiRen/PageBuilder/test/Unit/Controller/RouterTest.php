<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testPreviewRootWithHandleStillRewritesToPageBuilder(): void
    {
        $this->assertTrue($this->invokeShouldRewriteRootPath(true, 'preview-home', null));
    }

    public function testLiveRootWithResolvedHomepageRewritesToPageBuilder(): void
    {
        $this->assertTrue($this->invokeShouldRewriteRootPath(false, '', 'home'));
    }

    public function testRootWithoutPreviewHandleOrHomepageFallsBackToFrameworkDefaultRoute(): void
    {
        $this->assertFalse($this->invokeShouldRewriteRootPath(false, '', null));
        $this->assertFalse($this->invokeShouldRewriteRootPath(true, '', null));
    }

    private function invokeShouldRewriteRootPath(bool $isPreview, string $queryHandle, ?string $homePageHandle): bool
    {
        $method = new \ReflectionMethod(Router::class, 'shouldRewriteRootPath');
        $method->setAccessible(true);

        return (bool)$method->invoke(null, $isPreview, $queryHandle, $homePageHandle);
    }
}
