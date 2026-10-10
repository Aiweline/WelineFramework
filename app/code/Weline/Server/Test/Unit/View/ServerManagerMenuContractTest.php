<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** server-manager 必须挂在系统服务组，避免侧栏无上游归属。 */
final class ServerManagerMenuContractTest extends TestCase
{
    public function testMenuXmlRegistersServerManagerUnderSystemServiceGroup(): void
    {
        $path = dirname(__DIR__, 3) . '/etc/backend/menu.xml';
        self::assertFileExists($path);
        $xml = (string)file_get_contents($path);
        self::assertStringContainsString('source="Weline_Server::server_manager"', $xml);
        self::assertStringContainsString('name="server_manager"', $xml);
        self::assertStringContainsString('parent="Weline_Backend::system_service_group"', $xml);
        self::assertStringContainsString('action="*/backend/server-manager"', $xml);
    }

    public function testControllerDeclaresServerManagerAcl(): void
    {
        $path = dirname(__DIR__, 3) . '/Controller/Backend/ServerManager.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("Weline_Server::server_manager", $src);
        self::assertStringContainsString("Weline_Server::server_manager_view", $src);
        self::assertStringContainsString('Weline_Backend::system_service_group', $src);
    }
}
