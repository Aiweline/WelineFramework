<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Service\CustomerAccountService;
use WeShop\Customer\Service\CustomerProfileService;
use WeShop\Customer\Session\CustomerSession;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\Customer\Model\CustomerToken;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;

class CustomerAccountServiceTest extends TestCase
{
    public function testNormalizeEmailLowercasesAndTrimsWhitespace(): void
    {
        $service = new CustomerAccountService(
            $this->createAuthCustomerDouble(),
            $this->createMock(CustomerProfileService::class),
            $this->createMock(CustomerSession::class),
            $this->createMock(Request::class),
            $this->createMock(CustomerToken::class)
        );

        $this->assertSame('ada@example.com', $service->normalizeEmail('  Ada@Example.com  '));
    }

    public function testRegisterStoresEmailOnAuthUserBeforeCreatingProfile(): void
    {
        $authCustomer = $this->createAuthCustomerDouble();
        $profileService = $this->createMock(CustomerProfileService::class);
        $eventsManager = $this->createMock(EventsManager::class);
        $eventsManager->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnSelf();

        $profileService->expects($this->once())
            ->method('getOrCreateByAuthUser')
            ->with(
                $authCustomer,
                $this->callback(static function (array $profileData): bool {
                    return ($profileData['email'] ?? null) === 'ada@example.com'
                        && ($profileData['status'] ?? null) === 'active'
                        && ($profileData['first_name'] ?? null) === 'Ada';
                })
            )
            ->willReturn($this->createMock(CustomerProfile::class));

        $service = new class(
            $authCustomer,
            $profileService,
            $this->createMock(CustomerSession::class),
            $this->createMock(Request::class),
            $this->createMock(CustomerToken::class),
            $eventsManager
        ) extends CustomerAccountService {
            public function findAuthUserByEmail(string $email): ?AuthCustomer
            {
                return null;
            }
        };

        $result = $service->register('  Ada@Example.com  ', 'abc12345', [
            'first_name' => 'Ada',
        ]);

        $this->assertSame('ada@example.com', $authCustomer->capturedEmail);
        $this->assertSame('ada@example.com', $authCustomer->capturedUsername);
        $this->assertSame('abc12345', $authCustomer->capturedPassword);
        $this->assertSame($authCustomer, $result['auth_user']);
    }

    public function testAuthenticateAcceptsPlainUsernameLogin(): void
    {
        $authCustomer = new class extends AuthCustomer {
            public function __construct()
            {
                $this->setData(self::schema_fields_ID, 42);
                $this->setData(self::schema_fields_email, 'ada@example.com');
                $this->setData(self::schema_fields_password, password_hash('abc12345', PASSWORD_DEFAULT));
            }
        };

        $profile = $this->createMock(CustomerProfile::class);
        $profile->method('getData')
            ->willReturnCallback(static function (string $key): mixed {
                return $key === CustomerProfile::schema_fields_STATUS ? 'active' : null;
            });

        $profileService = $this->createMock(CustomerProfileService::class);
        $profileService->expects($this->once())
            ->method('getOrCreateByAuthUser')
            ->with($authCustomer, ['email' => 'ada@example.com'])
            ->willReturn($profile);

        $service = new class(
            $authCustomer,
            $profileService,
            $this->createMock(CustomerSession::class),
            $this->createMock(Request::class),
            $this->createMock(CustomerToken::class),
            $authCustomer
        ) extends CustomerAccountService {
            public function __construct(
                AuthCustomer $authCustomer,
                CustomerProfileService $profileService,
                CustomerSession $customerSession,
                Request $request,
                CustomerToken $customerToken,
                private readonly AuthCustomer $loginUser
            ) {
                parent::__construct($authCustomer, $profileService, $customerSession, $request, $customerToken);
            }

            public function findAuthUserByLogin(string $login): ?AuthCustomer
            {
                return $login === 'weline' ? $this->loginUser : null;
            }
        };

        $result = $service->authenticate('weline', 'abc12345');

        $this->assertSame($authCustomer, $result['auth_user']);
        $this->assertSame($profile, $result['profile']);
    }

    private function createAuthCustomerDouble(): AuthCustomer
    {
        return new class extends AuthCustomer {
            public string $capturedEmail = '';
            public string $capturedUsername = '';
            public string $capturedPassword = '';

            public function __construct()
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function clearData($field = null): static
            {
                return $this;
            }

            public function setEmail(string $email): static
            {
                $this->capturedEmail = $email;
                return $this->setData('email', $email);
            }

            public function setUsername(string $username): static
            {
                $this->capturedUsername = $username;
                return parent::setUsername($username);
            }

            public function setPassword(string $password): static
            {
                $this->capturedPassword = $password;
                return $this->setData('password', $password);
            }

            public function save(string|array|bool|\Weline\Framework\Database\AbstractModel $data = [], string|array $sequence = ''): int|bool
            {
                $this->setData(static::schema_fields_ID, 42);
                return 42;
            }
        };
    }
}
