<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Controller\Admin\Website;

/**
 * 编辑入口须同时接受 id 与 website_id（含默认站 0），避免仅 website_id 时落入 offcanvas 错误页。
 */
final class WebsiteEditTargetIdContractTest extends TestCase
{
    public function testResolverFallsBackFromIdToWebsiteId(): void
    {
        $resolver = $this->methodSource('resolveEditTargetWebsiteIdFromRequest');
        self::assertStringContainsString("getParam('id')", $resolver);
        self::assertStringContainsString("getParam('website_id')", $resolver);
        self::assertStringContainsString('requireWebsiteId($raw)', $resolver);
        self::assertMatchesRegularExpression(
            '/\$raw\s*===\s*null\s*\|\|\s*\$raw\s*===\s*[\'"]{2}/',
            $resolver,
        );
    }

    public function testEditUsesSharedResolverNotBareIdParam(): void
    {
        $edit = $this->methodSource('edit');
        self::assertStringContainsString('resolveEditTargetWebsiteIdFromRequest()', $edit);
        self::assertStringNotContainsString(
            "requireWebsiteId(\$this->request->getParam('id'))",
            $edit,
        );
        self::assertStringContainsString("getParam('website_id')", $edit);
    }

    public function testRequireWebsiteIdAcceptsCanonicalZero(): void
    {
        $require = $this->methodSource('requireWebsiteId');
        self::assertStringContainsString('/^(0|[1-9][0-9]*)$/D', $require);
        self::assertStringContainsString('$websiteId < 0', $require);
    }

    private function methodSource(string $methodName): string
    {
        $method = new \ReflectionMethod(Website::class, $methodName);
        $fileName = $method->getFileName();
        self::assertIsString($fileName);
        $lines = file($fileName);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
