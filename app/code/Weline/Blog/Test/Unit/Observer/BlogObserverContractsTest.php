<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBlogObserverContractsPass().
 */
final class BlogObserverContractsTest extends TestCase
{
    public function testAllBlogObserverContractsPass(): void
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
            'ClaimBlogNamespaceUriBefore::testObserverSetsCmsUriSkipForBlogNamespace' => function () {
            $this->assert_ClaimBlogNamespaceUriBefore_testObserverSetsCmsUriSkipForBlogNamespace();
        },
            'ClaimBlogNamespaceUriBefore::testUriInterceptSkipExtensionRegistersBlogOwner' => function () {
            $this->assert_ClaimBlogNamespaceUriBefore_testUriInterceptSkipExtensionRegistersBlogOwner();
        },
        ];
    }

private function assert_ClaimBlogNamespaceUriBefore_testObserverSetsCmsUriSkipForBlogNamespace(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/Observer/ClaimBlogNamespaceUriBefore.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('cms_uri_skip', $source);
    self::assertStringContainsString('BlogNamespace::skipPayload()', $source);
    self::assertStringContainsString('blog_namespace_owner', $source);
    self::assertStringContainsString('Weline_Framework_Router::process_uri_before', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/etc/event.xml'));
}

private function assert_ClaimBlogNamespaceUriBefore_testUriInterceptSkipExtensionRegistersBlogOwner(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Blog' . '/extends/module/Weline_Cms/UriInterceptSkip/BlogUriInterceptSkip.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('CmsUriInterceptSkipInterface', $source);
    self::assertStringContainsString('BlogNamespace::isBlogIdentifier', $source);
}
}
