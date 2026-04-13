<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Admin\Service\BackendRememberLoginService;
use Weline\Backend\Model\Backend\Acl\UserRole;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\BackendUserToken;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

final class BackendRememberLoginServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Cookie::delete('w_ut', ['path' => '/']);
        Cookie::delete('w_ut', ['path' => '/backend']);
        \w_env_set('cookie.w_ut', null);
        unset($_COOKIE['w_ut']);
        parent::tearDown();
    }

    public function testRestoreIfNeededLogsUserIntoBackendSessionWhenRememberTokenValid(): void
    {
        $request = new class extends Request {
            public function getRouteUrlPath(string $url = ''): string
            {
                unset($url);
                return 'dev/tool/admin/sandbox';
            }

            public function clientIP(): string
            {
                return '127.0.0.1';
            }

            public function getAreaRouter(): string
            {
                return 'backend';
            }
        };

        $rawSession = $this->createMock(SessionInterface::class);
        $rawSession->expects(self::exactly(2))
            ->method('set')
            ->withAnyParameters();
        $rawSession->expects(self::once())->method('save');

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->expects(self::once())->method('getUserId')->willReturn(null);
        $session->expects(self::once())->method('login')->with(self::isInstanceOf(BackendUser::class));
        $session->expects(self::once())->method('set')->with('remember_expire_time', 1899999999);
        $session->expects(self::once())->method('getSession')->willReturn($rawSession);
        $session->method('getId')->willReturn('abcdef123456');

        $sessionFactory = $this->createMock(SessionFactory::class);
        $sessionFactory->expects(self::once())->method('createBackendSession')->willReturn($session);

        $tokenModel = new class extends BackendUserToken {
            public function where($field, $value = null, $operator = '='): static
            {
                unset($field, $value, $operator);
                return $this;
            }

            public function find(...$args): static
            {
                unset($args);
                return $this;
            }

            public function fetch(): static
            {
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                unset($default);
                return 7;
            }

            public function getData(string $key = '', $index = null): mixed
            {
                unset($index);
                return $key === BackendUserToken::schema_fields_token_expire_time ? 1899999999 : null;
            }
        };

        $role = new UserRole();
        $role->setRoleId(3);

        $userModel = new class($role) extends BackendUser {
            public array $calls = [];

            public function __construct(private readonly UserRole $role)
            {
            }

            public function load(int|string $field_or_pk_value, $value = null): static
            {
                unset($value);
                $this->calls[] = ['load', $field_or_pk_value];
                return $this;
            }

            public function getId(mixed $default = 0)
            {
                unset($default);
                return 7;
            }

            public function getRole(): UserRole
            {
                return $this->role;
            }

            public function getIsEnabled(): bool
            {
                return true;
            }

            public function setSessionId($sessionId = null): static
            {
                $this->calls[] = ['setSessionId', $sessionId];
                return $this;
            }

            public function setLoginIp($ip = null): static
            {
                $this->calls[] = ['setLoginIp', $ip];
                return $this;
            }

            public function resetAttemptTimes(): static
            {
                $this->calls[] = ['resetAttemptTimes'];
                return $this;
            }

            public function save(string|array|bool|\Weline\Framework\Database\AbstractModel $data = [], string|array $sequence = ''): bool|int
            {
                unset($data, $sequence);
                $this->calls[] = ['save'];
                return 1;
            }
        };

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects(self::never())->method('addWarning');

        $service = new class(
            $request,
            $sessionFactory,
            $tokenModel,
            $userModel,
            $messageManager
        ) extends BackendRememberLoginService {
            public function __construct(
                Request $request,
                SessionFactory $sessionFactory,
                private readonly BackendUserToken $tokenModel,
                private readonly BackendUser $userModel,
                MessageManager $messageManager
            ) {
                parent::__construct($request, $sessionFactory, $tokenModel, $userModel, $messageManager);
            }

            protected function readRememberToken(): string
            {
                return 'remember-token';
            }

            protected function createRememberTokenModel(): BackendUserToken
            {
                return $this->tokenModel;
            }

            protected function createBackendUserModel(): BackendUser
            {
                return $this->userModel;
            }
        };

        self::assertTrue($service->restoreIfNeeded());
        self::assertSame(
            [
                ['load', 7],
                ['setSessionId', 'abcdef123456'],
                ['setLoginIp', '127.0.0.1'],
                ['resetAttemptTimes'],
                ['save'],
            ],
            $userModel->calls
        );
    }

    public function testRestoreIfNeededSkipsRememberRecoveryOnLoginPost(): void
    {
        Cookie::set('w_ut', 'remember-token', 600, ['path' => '/']);
        \w_env_set('cookie.w_ut', 'remember-token');
        $_COOKIE['w_ut'] = 'remember-token';

        $request = new class extends Request {
            public function getRouteUrlPath(string $url = ''): string
            {
                unset($url);
                return 'admin/login/post';
            }
        };

        $sessionFactory = $this->createMock(SessionFactory::class);
        $sessionFactory->expects(self::never())->method('createBackendSession');

        $service = new BackendRememberLoginService(
            $request,
            $sessionFactory,
            $this->createMock(BackendUserToken::class),
            $this->createMock(BackendUser::class),
            $this->createMock(MessageManager::class)
        );

        self::assertFalse($service->restoreIfNeeded());
    }
}
