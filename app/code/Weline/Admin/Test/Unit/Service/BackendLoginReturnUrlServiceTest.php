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

    public function testBackendDetailPagesCanBeReturnTargetsEvenWhenTheyAreNotMenus(): void
    {
        self::assertTrue($this->isCandidateRouteAllowed('trash/backend/item/detail'));
    }

    public function testUnsafeActionRoutesAreStillRejected(): void
    {
        self::assertFalse($this->isCandidateRouteAllowed('weline_dbmanager/backend/wls-db-manager/download'));
        self::assertFalse($this->isCandidateRouteAllowed('weline_dbmanager/backend/wls-db-manager/batch-delete'));
        self::assertFalse($this->isCandidateRouteAllowed('weline_dbmanager/backend/wls-db-manager/export-csv'));
    }

    public function testDocumentPageRequestCapturesReturnUrl(): void
    {
        $service = new BackendLoginReturnUrlService(
            $this->createAclServiceStub(),
            $this->createMenuServiceStub(),
            $this->createRequestStub(documentNavigation: true),
            $this->createUrlStub()
        );

        $url = '/marketing/backend/credit/index?theme_id=7';

        self::assertTrue($service->shouldCaptureCurrentRequestReturnUrl(null, $url));
        $loginUrl = $service->buildLoginUrlWithReturn('http://admin.test/admin/login', $url, 'not_logged_in');
        self::assertStringContainsString('return_url=%2F', $loginUrl);
        self::assertStringContainsString('%2Fmarketing%2Fbackend%2Fcredit%2Findex%3Ftheme_id%3D7', $loginUrl);
        self::assertStringNotContainsString('return_url=http%3A', $loginUrl);
        self::assertStringNotContainsString('return_url=https%3A', $loginUrl);
    }

    public function testAjaxRequestDoesNotCaptureReturnUrl(): void
    {
        $service = new BackendLoginReturnUrlService(
            $this->createAclServiceStub(),
            $this->createMenuServiceStub(),
            $this->createRequestStub(documentNavigation: false),
            $this->createUrlStub()
        );

        $url = '/marketing/backend/credit/index?theme_id=7';

        self::assertFalse($service->shouldCaptureCurrentRequestReturnUrl(null, $url));
        $loginUrl = $service->buildLoginUrlWithReturn('http://admin.test/admin/login', $url, 'not_logged_in');
        self::assertStringContainsString('no_access_reason=not_logged_in', $loginUrl);
        self::assertStringNotContainsString('return_url=', $loginUrl);
    }

    public function testApiPathDoesNotCaptureReturnUrlEvenForDocumentRequest(): void
    {
        $service = new BackendLoginReturnUrlService(
            $this->createAclServiceStub(),
            $this->createMenuServiceStub(),
            $this->createRequestStub(documentNavigation: true),
            $this->createUrlStub()
        );

        $url = 'http://admin.test/admin/dev/tool/rest/v1/panel/session';

        self::assertFalse($service->shouldCaptureCurrentRequestReturnUrl(null, $url));
        self::assertStringNotContainsString(
            'return_url=',
            $service->buildLoginUrlWithReturn('http://admin.test/admin/login', $url, 'not_logged_in')
        );
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

    private function createRequestStub(bool $documentNavigation = true): Request
    {
        $urlBuilder = $this->createUrlStub();

        return new class($urlBuilder, $documentNavigation) extends Request {
            public function __construct(
                private readonly Url $urlBuilder,
                private readonly bool $documentNavigation
            )
            {
            }

            public function isDocumentNavigationRequest(): bool
            {
                return $this->documentNavigation;
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
