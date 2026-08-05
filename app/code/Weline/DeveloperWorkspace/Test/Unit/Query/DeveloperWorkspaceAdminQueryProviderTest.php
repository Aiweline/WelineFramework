<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\DeveloperWorkspace\Extends\Module\Weline_Framework\Query\DeveloperWorkspaceAdminQueryProvider;

final class DeveloperWorkspaceAdminQueryProviderTest extends TestCase
{
    public function testPublicDocumentationOperationDoesNotRelaxAdminBridgeAuthorization(): void
    {
        $operations = [];
        foreach ((new DeveloperWorkspaceAdminQueryProvider())->getDescriptor()['operations'] as $operation) {
            $operations[(string)$operation['name']] = $operation;
        }

        self::assertSame('backend', $operations['adminRequest']['auth']);
        self::assertSame('write', $operations['adminRequest']['mode']);
        self::assertSame('any', $operations['docsRequest']['auth']);
        self::assertSame('read', $operations['docsRequest']['mode']);
        self::assertSame('any', $operations['panelRequest']['auth']);
        self::assertSame('write', $operations['panelRequest']['mode']);
    }

    public function testPanelOperationRejectsUnknownPathsBeforeDispatch(): void
    {
        $response = (new DeveloperWorkspaceAdminQueryProvider())->execute('panelRequest', [
            'url' => '/dev/tool/rest/v1/unknown/action',
            'method' => 'GET',
        ]);

        self::assertTrue($response['__weline_panel_response']);
        self::assertSame(400, $response['status']);
        self::assertFalse($response['success']);
        self::assertStringContainsString('Unsupported panel path', $response['body']);
    }

    public function testPublicDocumentationOperationRejectsWritesAndNonDocumentationPaths(): void
    {
        $provider = new DeveloperWorkspaceAdminQueryProvider();

        self::assertSame(
            ['success' => false, 'message' => 'Documentation bridge only accepts GET'],
            $provider->execute('docsRequest', [
                'url' => '/dev/tool/docs/tree',
                'method' => 'POST',
            ])
        );
        self::assertSame(
            ['success' => false, 'message' => 'Unsupported documentation path: /dev/tool/rest/v1/routes'],
            $provider->execute('docsRequest', [
                'url' => '/dev/tool/rest/v1/routes',
                'method' => 'GET',
            ])
        );
    }
}
