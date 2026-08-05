<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Admin\Service\MenuRenderService;
use Weline\Backend\Api\Auth\BackendUserContext;
use Weline\Backend\Api\Menu\MenuReaderInterface;

final class MenuRenderServiceAclIsolationTest extends TestCase
{
    public function testSidebarUsesAuthenticatedRoleWithoutReloadingUserById(): void
    {
        $context = new BackendUserContext(91, 'restricted', 'restricted@example.test', '', 27, true, false);
        $service = new class($this->createMenuAccessLogStub(), $context) extends MenuRenderService {
            public function __construct(
                MenuAccessLog $menuAccessLog,
                private readonly BackendUserContext $context,
            ) {
                parent::__construct($menuAccessLog);
            }

            public function getCurrentUser(): ?BackendUserContext
            {
                return $this->context;
            }
        };

        $reader = new class() implements MenuReaderInterface {
            public int $roleId = 0;
            public int $userLookupCount = 0;

            public function getMenuTreeByRoleId(int $roleId): array
            {
                $this->roleId = $roleId;
                return [['source_id' => 'Weline_Product::restricted']];
            }

            public function getMenuTreeByUserId(int $userId): array
            {
                $this->userLookupCount++;
                return [['source_id' => 'Weline_Backend::unexpected-full-tree']];
            }

            public function hasMenuEntry(int $roleId): bool
            {
                return false;
            }

            public function getDefaultEntryRoute(int $roleId): ?string
            {
                return null;
            }

            public function findMenuNodeByRoute(int $roleId, string $routePath): ?array
            {
                return null;
            }
        };

        $property = new ReflectionProperty(MenuRenderService::class, 'menuReader');
        $property->setValue($service, $reader);

        self::assertSame([['source_id' => 'Weline_Product::restricted']], $service->getMenuTree());
        self::assertSame(27, $reader->roleId);
        self::assertSame(0, $reader->userLookupCount);
    }

    public function testShortcutAndSearchMarkupKeepCanonicalSourceIdentityUnique(): void
    {
        $serviceSource = (string)\file_get_contents(BP . '/app/code/Weline/Admin/Service/MenuRenderService.php');
        $sidebarSource = (string)\file_get_contents(BP . '/app/code/Weline/Admin/view/templates/common/left-sidebar.phtml');

        self::assertStringContainsString('data-menu-source-ref=', $serviceSource);
        self::assertStringNotContainsString('class="frequent-menu-item" data-source=', $serviceSource);
        self::assertStringContainsString("'data-source': source", $sidebarSource);
        self::assertStringContainsString("\$link.attr('data-source') || d.\$item.attr('data-source')", $sidebarSource);
        self::assertStringContainsString("\$link.attr('data-menu-source-ref') || d.\$item.attr('data-menu-source-ref')", $sidebarSource);
    }

    private function createMenuAccessLogStub(): MenuAccessLog
    {
        return new class() extends MenuAccessLog {
            public function __construct()
            {
            }
        };
    }
}
