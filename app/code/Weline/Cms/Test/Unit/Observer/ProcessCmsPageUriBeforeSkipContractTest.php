<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class ProcessCmsPageUriBeforeSkipContractTest extends TestCase
{
    public function testObserverChecksExplicitSkipBeforePageLookup(): void
    {
        $path = dirname(__DIR__, 3) . '/Observer/ProcessCmsPageUriBefore.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('CmsUriInterceptSkipRegistry', $source);
        self::assertStringContainsString('shouldSkipUriIntercept', $source);
        self::assertStringContainsString("getData('cms_uri_skip')", $source);
    }

    public function testCmsDeclaresUriInterceptSkipExtensionPoint(): void
    {
        $extends = include dirname(__DIR__, 3) . '/extends.php';
        self::assertArrayHasKey('extends', $extends);
        self::assertArrayHasKey('UriInterceptSkip', $extends['extends']);
    }
}
