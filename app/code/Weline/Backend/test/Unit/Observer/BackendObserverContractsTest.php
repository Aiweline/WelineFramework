<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendObserverContractsPass().
 */
final class BackendObserverContractsTest extends TestCase
{
    public function testAllBackendObserverContractsPass(): void
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
            'ForceMenuSetupEvent::testUpgradeMenuAppliesForceMenuFromEvent' => function () {
            $this->assert_ForceMenuSetupEvent_testUpgradeMenuAppliesForceMenuFromEvent();
        },
            'ForceMenuSetupEvent::testSetupUpgradeAfterReleasesForceMenu' => function () {
            $this->assert_ForceMenuSetupEvent_testSetupUpgradeAfterReleasesForceMenu();
        },
            'ResponseRedirectBeforeAllowedHost::testSecurityRedirectAllowsRegisteredWebsiteHosts' => function () {
            $this->assert_ResponseRedirectBeforeAllowedHost_testSecurityRedirectAllowsRegisteredWebsiteHosts();
        },
            'ResponseRedirectBeforeAllowedHost::testGroceryStorefrontHostIsAllowedWhenRegistered' => function () {
            $this->assert_ResponseRedirectBeforeAllowedHost_testGroceryStorefrontHostIsAllowedWhenRegistered();
        },
        ];
    }

private function assert_ForceMenuSetupEvent_testUpgradeMenuAppliesForceMenuFromEvent(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Observer/UpgradeMenu.php');
    self::assertStringContainsString('force_menu', $src);
    self::assertStringContainsString('beginForceFullHold', $src);
    self::assertStringContainsString('requestForceFull', $src);
}

private function assert_ForceMenuSetupEvent_testSetupUpgradeAfterReleasesForceMenu(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Observer/SetupUpgradeAfter.php');
    self::assertStringContainsString('force_menu_release', $src);
    self::assertStringContainsString('resetForceFullState', $src);
}

private function assert_ResponseRedirectBeforeAllowedHost_testSecurityRedirectAllowsRegisteredWebsiteHosts(): void
{
    $path = (new \ReflectionClass(\Weline\Backend\Observer\ResponseRedirectBefore::class))->getFileName();
    self::assertNotFalse($path);
    $source = (string) \file_get_contents((string) $path);
    self::assertNotSame('', $source);
    self::assertStringContainsString('isRegisteredWebsiteStorefrontHost(', $source);
    self::assertStringContainsString('WebsiteDomain::class', $source);
    self::assertStringContainsString('Website::class', $source);
    self::assertStringContainsString('schema_fields_DOMAIN', $source);
    // Must not keep the localhost-only denylist that blocked grocery preview.
    self::assertStringNotContainsString("// 可以添加更多允许的域名", $source);
}

private function assert_ResponseRedirectBeforeAllowedHost_testGroceryStorefrontHostIsAllowedWhenRegistered(): void
{
    $observer = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Backend\Observer\ResponseRedirectBefore::class);
    $ref = new \ReflectionClass($observer);
    $method = $ref->getMethod('isAllowedHost');
    $method->setAccessible(true);
    self::assertTrue($method->invoke($observer, 'localhost'));
    self::assertTrue($method->invoke($observer, '127.0.0.1'));
    try {
        $origin = \trim((string) (\Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Service\ThemeApplicationUsageService::class)->resolveStorefrontOriginForWebsite(544, 'grocery') ?? ''));
    } catch (\Throwable) {
        $origin = '';
    }
    $host = \strtolower((string) (\parse_url($origin, \PHP_URL_HOST) ?: ''));
    if ($host === '') {
        self::markTestSkipped('grocery storefront origin not resolvable in this environment');
    }
    self::assertTrue($method->invoke($observer, $host), 'registered grocery Host must be an allowed backend redirect target');
}
}
