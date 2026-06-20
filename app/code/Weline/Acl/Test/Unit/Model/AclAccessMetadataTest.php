<?php
declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\Acl;
use Weline\Acl\Model\Role;
use Weline\Acl\Model\RoleAccess;
use Weline\Acl\Service\AclService;
use Weline\Framework\Database\Schema\SchemaParser;

final class AclAccessMetadataTest extends TestCase
{
    private ?string $routeRegistryFile = null;

    protected function tearDown(): void
    {
        AclService::resetRequestCache();
        if ($this->routeRegistryFile !== null && is_file($this->routeRegistryFile)) {
            @unlink($this->routeRegistryFile);
        }
        $this->routeRegistryFile = null;
        parent::tearDown();
    }

    public function testAclSchemaDeclaresApiAccessMetadataColumns(): void
    {
        $schema = (new SchemaParser())->parse(Acl::class);
        self::assertNotNull($schema);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        self::assertArrayHasKey(Acl::schema_fields_ACCESS_MODE, $columns);
        self::assertSame(Acl::ACCESS_MODE_EDIT, $columns[Acl::schema_fields_ACCESS_MODE]->default);
        self::assertArrayHasKey(Acl::schema_fields_SCOPE_GROUP, $columns);
        self::assertArrayHasKey(Acl::schema_fields_API_EXPOSABLE, $columns);
        self::assertSame(0, $columns[Acl::schema_fields_API_EXPOSABLE]->default);
    }

    public function testNormalizeAccessModeKeepsExplicitModesAndDefaultsByMethod(): void
    {
        self::assertSame(Acl::ACCESS_MODE_READ, Acl::normalizeAccessMode('read', 'POST'));
        self::assertSame(Acl::ACCESS_MODE_EDIT, Acl::normalizeAccessMode('edit', 'GET'));
        self::assertSame(Acl::ACCESS_MODE_READ, Acl::normalizeAccessMode('', 'GET'));
        self::assertSame(Acl::ACCESS_MODE_READ, Acl::normalizeAccessMode('', 'HEAD'));
        self::assertSame(Acl::ACCESS_MODE_EDIT, Acl::normalizeAccessMode('', 'POST'));
        self::assertSame(Acl::ACCESS_MODE_EDIT, Acl::normalizeAccessMode('', ''));
    }

    public function testRouteAllowedByEntriesRejectsReadScopeForMutatingMethod(): void
    {
        $service = new class(
            $this->createMock(Role::class),
            $this->createMock(RoleAccess::class),
            $this->createMock(Acl::class)
        ) extends AclService {
            public function isRouteProtected(string $routePath): bool
            {
                return $routePath === 'api/rest/v1/products';
            }
        };

        $entries = [[
            Acl::schema_fields_ROUTE => 'api/rest/v1/products',
            Acl::schema_fields_METHOD => '',
            Acl::schema_fields_ACCESS_MODE => Acl::ACCESS_MODE_READ,
        ]];

        self::assertTrue($service->isRouteAllowedByEntries($entries, 'api/rest/v1/products', 'GET', true));
        self::assertFalse($service->isRouteAllowedByEntries($entries, 'api/rest/v1/products', 'POST', true));
    }

    public function testRouteAllowedByEntriesMatchesGeneratedAliasesForSameControllerAction(): void
    {
        $this->routeRegistryFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-acl-route-alias-' . bin2hex(random_bytes(4)) . '.php';
        $routeData = [
            'class' => [
                'name' => 'Weline\\Server\\Controller\\Backend\\WlsPanel',
                'method' => 'postSecurityRulesSave',
                'request_method' => 'POST',
            ],
        ];
        file_put_contents($this->routeRegistryFile, '<?php return ' . var_export([
            'server/backend/wls-panel/security-rules-save::POST' => $routeData,
            'server/backend/wls-panel/post-security-rules-save::POST' => $routeData,
            'server/backend/wls-panel/postsecurityrulessave::POST' => $routeData,
            'server/backend/wls-panel/security::GET' => [
                'class' => [
                    'name' => 'Weline\\Server\\Controller\\Backend\\WlsPanel',
                    'method' => 'getSecurity',
                    'request_method' => 'GET',
                ],
            ],
        ], true) . ';');

        $service = new class(
            $this->routeRegistryFile,
            $this->createMock(Role::class),
            $this->createMock(RoleAccess::class),
            $this->createMock(Acl::class)
        ) extends AclService {
            public function __construct(
                private readonly string $routeRegistryFile,
                Role $roleModel,
                RoleAccess $roleAccessModel,
                Acl $aclModel
            ) {
                parent::__construct($roleModel, $roleAccessModel, $aclModel);
            }

            public function isRouteProtected(string $routePath): bool
            {
                return true;
            }

            protected function getRouteRegistryFiles(): array
            {
                return [$this->routeRegistryFile];
            }
        };

        $entries = [[
            Acl::schema_fields_ROUTE => 'server/backend/wls-panel/postsecurityrulessave',
            Acl::schema_fields_METHOD => 'POST',
            Acl::schema_fields_ACCESS_MODE => Acl::ACCESS_MODE_EDIT,
        ]];

        self::assertTrue($service->isRouteAllowedByEntries($entries, 'server/backend/wls-panel/security-rules-save', 'POST'));
        self::assertFalse($service->isRouteAllowedByEntries($entries, 'server/backend/wls-panel/security', 'POST'));
    }
}
