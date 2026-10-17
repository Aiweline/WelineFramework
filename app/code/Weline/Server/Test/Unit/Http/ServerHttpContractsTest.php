<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Server\Http\ServiceUnavailablePage;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllServerHttpContractsPass().
 */
final class ServerHttpContractsTest extends TestCase
{
    public function testAllServerHttpContractsPass(): void
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
            'ServiceUnavailablePageHost::testHtmlBodyPassesHostIntoMaintenanceStaticPage' => function () {
            $this->assert_ServiceUnavailablePageHost_testHtmlBodyPassesHostIntoMaintenanceStaticPage();
        },
            'ServiceUnavailablePageHost::testHttpResponseAcceptsHostHeaderWithoutThrowing' => function () {
            $this->assert_ServiceUnavailablePageHost_testHttpResponseAcceptsHostHeaderWithoutThrowing();
        },
        ];
    }

private function assert_ServiceUnavailablePageHost_testHtmlBodyPassesHostIntoMaintenanceStaticPage(): void
{
    $source = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/Http/ServiceUnavailablePage.php');
    self::assertStringContainsString("headers['host']", $source);
    self::assertStringContainsString('loadHtml(', $source);
    self::assertStringContainsString('loadJson(', $source);
    // Host must be forwarded for website×locale zero-DB selection.
    self::assertMatchesRegularExpression('/loadHtml\([\s\S]*headers\[.host.\].*\)/m', $source);
    self::assertMatchesRegularExpression('/loadJson\([\s\S]*headers\[.host.\].*\)/m', $source);
}

private function assert_ServiceUnavailablePageHost_testHttpResponseAcceptsHostHeaderWithoutThrowing(): void
{
    $response = ServiceUnavailablePage::httpResponse(ServiceUnavailablePage::VARIANT_MAINTENANCE, false, '', 5, ['host' => 'shop.example.com', 'accept' => 'text/html'], '/store-a/en_US/');
    self::assertStringContainsString('HTTP/1.1 503', $response);
    self::assertStringContainsString('text/html', $response);
}
}
