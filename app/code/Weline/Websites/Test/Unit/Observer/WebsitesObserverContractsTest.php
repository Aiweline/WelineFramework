<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Api\Auth\BackendLoginAccount;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\Event;
use Weline\Websites\Observer\DenySuperAdminBypassOnNonDefaultWebsite;
use Weline\Websites\Observer\EnforceBackendUserWebsiteBindOnLogin;
use Weline\Websites\Observer\WebsiteCrawlerPolicySaveAfter;
use Weline\Websites\Service\WebsiteAclGrantService;
use Weline\Websites\Service\WebsiteCrawlerPolicyService;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesObserverContractsPass().
 */
final class WebsitesObserverContractsTest extends TestCase
{
    public function testAllWebsitesObserverContractsPass(): void
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
            'SuperAdminWebsiteGateExemption::testLoginBindSkipsWhenRoleIdIsSuperAdmin' => function () {
            $this->assert_SuperAdminWebsiteGateExemption_testLoginBindSkipsWhenRoleIdIsSuperAdmin();
        },
            'SuperAdminWebsiteGateExemption::testLoginBindSkipsWhenUserIdIsOneEvenWithoutSuperRole' => function () {
            $this->assert_SuperAdminWebsiteGateExemption_testLoginBindSkipsWhenUserIdIsOneEvenWithoutSuperRole();
        },
            'SuperAdminWebsiteGateExemption::testDenySuperAdminBypassObserverIsNoOp' => function () {
            $this->assert_SuperAdminWebsiteGateExemption_testDenySuperAdminBypassObserverIsNoOp();
        },
            'SuperAdminWebsiteGateExemption::testFilterRoleAclEntriesLeavesSuperAdminEntriesUntouchedOnNonDefaultSite' => function () {
            $this->assert_SuperAdminWebsiteGateExemption_testFilterRoleAclEntriesLeavesSuperAdminEntriesUntouchedOnNonDefaultSite();
        },
            'SuperAdminWebsiteGateExemption::testFilterRoleAclEntriesStillEmptiesNonSuperOnEmptyGrantPackage' => function () {
            $this->assert_SuperAdminWebsiteGateExemption_testFilterRoleAclEntriesStillEmptiesNonSuperOnEmptyGrantPackage();
        },
            'WebsiteCrawlerPolicySaveAfterConnection::testSaveForWebsiteReceivesEventConnection' => function () {
            $this->assert_WebsiteCrawlerPolicySaveAfterConnection_testSaveForWebsiteReceivesEventConnection();
        },
            'WebsiteCrawlerPolicySaveAfterConnection::testMissingConnectionFailsClosed' => function () {
            $this->assert_WebsiteCrawlerPolicySaveAfterConnection_testMissingConnectionFailsClosed();
        },
            'WebsiteCrawlerPolicySaveAfterConnection::testDispatchPayloadDocumentsConnectionKey' => function () {
            $this->assert_WebsiteCrawlerPolicySaveAfterConnection_testDispatchPayloadDocumentsConnectionKey();
        },
        ];
    }

private function assert_SuperAdminWebsiteGateExemption_testLoginBindSkipsWhenRoleIdIsSuperAdmin(): void
{
    $user = new BackendLoginAccount(5, 'cursor_ops', 'cursor_ops@example.test', '', 0, false, true, false, 1);
    $event = new Event(['user' => $user, 'handled' => false]);
    (new EnforceBackendUserWebsiteBindOnLogin())->execute($event);
    self::assertFalse((bool) $event->getData('handled'));
    self::assertNull($event->getData('error'));
}

private function assert_SuperAdminWebsiteGateExemption_testLoginBindSkipsWhenUserIdIsOneEvenWithoutSuperRole(): void
{
    $user = new BackendLoginAccount(1, 'weline', 'admin@example.test', '', 0, false, true, false, 0);
    $event = new Event(['user' => $user, 'handled' => false]);
    (new EnforceBackendUserWebsiteBindOnLogin())->execute($event);
    self::assertFalse((bool) $event->getData('handled'));
    self::assertNull($event->getData('error'));
}

private function assert_SuperAdminWebsiteGateExemption_testDenySuperAdminBypassObserverIsNoOp(): void
{
    $event = new Event(['allow_bypass' => true, 'role_id' => 1]);
    (new DenySuperAdminBypassOnNonDefaultWebsite())->execute($event);
    self::assertTrue((bool) $event->getData('allow_bypass'));
    $eventCleared = new Event(['allow_bypass' => false, 'role_id' => 1]);
    (new DenySuperAdminBypassOnNonDefaultWebsite())->execute($eventCleared);
    self::assertFalse((bool) $eventCleared->getData('allow_bypass'));
}

private function assert_SuperAdminWebsiteGateExemption_testFilterRoleAclEntriesLeavesSuperAdminEntriesUntouchedOnNonDefaultSite(): void
{
    $service = new WebsiteAclGrantService();
    $entries = [['source_id' => 'Weline_Backend::dashboard', 'type' => 'menus', 'route' => 'weline_dashboard/backend/dashboard'], ['source_id' => 'Weline_Admin::system_dashboard', 'type' => 'menus', 'route' => 'admin']];
    $filtered = $service->filterRoleAclEntries($entries, 1, 99);
    self::assertSame($entries, $filtered);
}

private function assert_SuperAdminWebsiteGateExemption_testFilterRoleAclEntriesStillEmptiesNonSuperOnEmptyGrantPackage(): void
{
    $service = new WebsiteAclGrantService();
    WebsiteAclGrantService::clearRequestCache();
    $ref = new \ReflectionClass(WebsiteAclGrantService::class);
    $prop = $ref->getProperty('sourceIdCache');
    $prop->setAccessible(true);
    $prop->setValue(null, [9999991 => []]);
    $entries = [['source_id' => 'Weline_Backend::dashboard', 'type' => 'menus', 'route' => 'weline_dashboard/backend/dashboard']];
    $filtered = $service->filterRoleAclEntries($entries, 2, 9999991);
    self::assertSame([], $filtered);
    WebsiteAclGrantService::clearRequestCache();
}

private function assert_WebsiteCrawlerPolicySaveAfterConnection_testSaveForWebsiteReceivesEventConnection(): void
{
    $connection = $this->createMock(ConnectionFactory::class);
    $service = $this->createMock(WebsiteCrawlerPolicyService::class);
    $service->expects(self::once())->method('saveForWebsite')->with(7, self::callback(static fn(array $input): bool => ($input['enabled'] ?? null) === '1'), $connection)->willReturn(['enabled' => true, 'block_duration' => 0, 'entries' => []]);
    $observer = new WebsiteCrawlerPolicySaveAfter($service);
    $event = new Event(['website_id' => 7, 'post_data' => ['extensions' => ['crawler' => ['enabled' => '1', 'entries' => []]]], 'connection' => $connection]);
    $observer->execute($event);
}

private function assert_WebsiteCrawlerPolicySaveAfterConnection_testMissingConnectionFailsClosed(): void
{
    $service = $this->createMock(WebsiteCrawlerPolicyService::class);
    $service->expects(self::never())->method('saveForWebsite');
    $observer = new WebsiteCrawlerPolicySaveAfter($service);
    $event = new Event(['website_id' => 7, 'post_data' => ['extensions' => ['crawler' => ['enabled' => '1']]]]);
    $this->expectException(\RuntimeException::class);
    $observer->execute($event);
}

private function assert_WebsiteCrawlerPolicySaveAfterConnection_testDispatchPayloadDocumentsConnectionKey(): void
{
    $controller = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Controller/Admin/Website.php');
    self::assertStringContainsString("'connection' => \$this->website->getConnection()", $controller);
    self::assertStringContainsString('JSON toast only', $controller);
}
}
