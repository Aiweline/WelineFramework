<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCmsObserverContractsPass().
 */
final class CmsObserverContractsTest extends TestCase
{
    public function testAllCmsObserverContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ProcessCmsPageUriBeforeSkip::testObserverChecksExplicitSkipBeforePageLookup' => function () {
            $this->assert_ProcessCmsPageUriBeforeSkip_testObserverChecksExplicitSkipBeforePageLookup();
        },
            'ProcessCmsPageUriBeforeSkip::testCmsDeclaresUriInterceptSkipExtensionPoint' => function () {
            $this->assert_ProcessCmsPageUriBeforeSkip_testCmsDeclaresUriInterceptSkipExtensionPoint();
        },
        ];
    }

private function assert_ProcessCmsPageUriBeforeSkip_testObserverChecksExplicitSkipBeforePageLookup(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cms' . '/Observer/ProcessCmsPageUriBefore.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('CmsUriInterceptSkipRegistry', $source);
    self::assertStringContainsString('shouldSkipUriIntercept', $source);
    self::assertStringContainsString("getData('cms_uri_skip')", $source);
}

private function assert_ProcessCmsPageUriBeforeSkip_testCmsDeclaresUriInterceptSkipExtensionPoint(): void
{
    $extends = include '/Users/weline/Project/Official/框架/app/code/Weline/Cms' . '/extends.php';
    self::assertArrayHasKey('extends', $extends);
    self::assertArrayHasKey('UriInterceptSkip', $extends['extends']);
}
}
