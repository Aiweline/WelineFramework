<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class ClaimBlogNamespaceUriBeforeContractTest extends TestCase
{
    public function testObserverSetsCmsUriSkipForBlogNamespace(): void
    {
        $path = dirname(__DIR__, 3) . '/Observer/ClaimBlogNamespaceUriBefore.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('cms_uri_skip', $source);
        self::assertStringContainsString('BlogNamespace::skipPayload()', $source);
        self::assertStringContainsString('blog_namespace_owner', $source);
        self::assertStringContainsString('Weline_Framework_Router::process_uri_before', (string)file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml'));
    }

    public function testUriInterceptSkipExtensionRegistersBlogOwner(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Cms/UriInterceptSkip/BlogUriInterceptSkip.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('CmsUriInterceptSkipInterface', $source);
        self::assertStringContainsString('BlogNamespace::isBlogIdentifier', $source);
    }
}
