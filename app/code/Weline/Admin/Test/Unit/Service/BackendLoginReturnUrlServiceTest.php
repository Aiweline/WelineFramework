<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

use ReflectionMethod;
use PHPUnit\Framework\TestCase;
use Weline\Acl\Service\AclService;
use Weline\Admin\Service\BackendLoginReturnUrlService;
use Weline\Backend\Service\MenuServiceInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

require_once dirname(__DIR__, 3) . '/Service/BackendLoginReturnUrlService.php';

final class BackendLoginReturnUrlServiceTest extends TestCase
{
    private BackendLoginReturnUrlService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new BackendLoginReturnUrlService(
            $this->createAclServiceStub(),
            $this->createMenuServiceStub(),
            $this->createRequestStub(),
            $this->createUrlStub()
        );
    }

    public function testEditableBackendPagesRemainValidReturnTargets(): void
    {
        self::assertTrue($this->isCandidateRouteAllowed('weline_dbmanager/backend/wls-db-manager/edit'));
        self::assertTrue($this->isCandidateRouteAllowed('weline_dbmanager/backend/wls-db-manager/add'));
    }

    public function testRouteContainingEditAsPlainTextIsNotRejected(): void
    {
        self::assertTrue($this->isCandidateRouteAllowed('marketing/backend/credit/index'));
    }

    public function testUnsafeActionRoutesAreStillRejected(): void
    {
        self::assertFalse($this->isCandidateRouteAllowed('weline_dbmanager/backend/wls-db-manager/download'));
        self::assertFalse($this->isCandidateRouteAllowed('weline_dbmanager/backend/wls-db-manager/batch-delete'));
        self::assertFalse($this->isCandidateRouteAllowed('weline_dbmanager/backend/wls-db-manager/export-csv'));
    }

    private function isCandidateRouteAllowed(string $routePath): bool
    {
        $method = new ReflectionMethod($this->service, 'isCandidateRouteAllowed');
        $method->setAccessible(true);

        return (bool)$method->invoke($this->service, $routePath);
    }

    private function createAclServiceStub(): AclService
    {
        return new class() extends AclService {
            public function __construct()
            {
            }

            public function isRouteProtected(string $routePath): bool
            {
                return in_array($routePath, [
                    'marketing/backend/credit/index',
                    'weline_dbmanager/backend/wls-db-manager/add',
                    'weline_dbmanager/backend/wls-db-manager/edit',
                ], true);
            }
        };
    }

    private function createMenuServiceStub(): MenuServiceInterface
    {
        return new class() implements MenuServiceInterface {
            public function getMenuTreeByRoleId(int $roleId): array
            {
                return [];
            }

            public function getMenuTreeByUserId(int $userId): array
            {
                return [];
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
    }

    private function createRequestStub(): Request
    {
        $urlBuilder = $this->createUrlStub();

        return new class($urlBuilder) extends Request {
            public function __construct(private readonly Url $urlBuilder)
            {
            }

            public function isSecure(): bool
            {
                return false;
            }

            public function getServer(string $key = ''): string|array
            {
                $server = [
                    'HTTP_HOST' => 'admin.test',
                    'SERVER_NAME' => 'admin.test',
                ];

                if ($key === '') {
                    return $server;
                }

                return $server[$key] ?? '';
            }

            public function getUrlBuilder(): Url
            {
                return $this->urlBuilder;
            }
        };
    }

    private function createUrlStub(): Url
    {
        return new class() extends Url {
            public function __construct()
            {
            }

            public function getCurrentUrl(array $params = [], bool $merge_url_params = true): string
            {
                return 'http://admin.test/admin/login';
            }
        };
    }
}
