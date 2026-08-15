<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Api\CaptchaManagerInterface;
use Weline\Customer\Extends\Module\Weline_Framework\Query\AccountQueryProvider;
use Weline\Customer\Model\Customer;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Customer\Service\PasswordResetService;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

final class AccountQueryProviderCaptchaTest extends TestCase
{
    public function testLoginEventPayloadIsAssignedBeforeByReferenceDispatch(): void
    {
        $source = (string)file_get_contents((string)(new \ReflectionClass(AccountQueryProvider::class))->getFileName());

        self::assertStringContainsString('$loginEvent = new DataObject([', $source);
        self::assertStringContainsString("->dispatch('Weline_Customer_Account_Login::login_after', \$loginEvent);", $source);
    }

    public function testLoginDescriptorAcceptsTheRenderedCaptchaProof(): void
    {
        /** @var AccountQueryProvider $provider */
        $provider = (new \ReflectionClass(AccountQueryProvider::class))->newInstanceWithoutConstructor();
        $login = null;
        foreach ($provider->getDescriptor()['operations'] as $operation) {
            if (($operation['name'] ?? '') === 'login') {
                $login = $operation;
                break;
            }
        }

        self::assertIsArray($login);
        self::assertSame(
            [
                'captcha_provider' => ['type' => 'string', 'max_length' => 64],
                'captcha_token' => ['type' => 'string', 'max_length' => 128],
                'captcha_response' => ['type' => 'string', 'max_length' => 8192],
            ],
            \array_intersect_key(
                $login['params'] ?? [],
                \array_fill_keys(['captcha_provider', 'captcha_token', 'captcha_response'], true),
            ),
        );
    }

    public function testRejectedCaptchaStopsWorkerAuthentication(): void
    {
        $submission = [
            'username' => 'customer@example.test',
            'password' => 'secret',
            'remember_duration' => 21600,
            'redirect_url' => '',
            'captcha_provider' => 'local_image',
            'captcha_token' => \str_repeat('a', 48),
            'captcha_response' => 'A2B3C4',
        ];
        $request = $this->createMock(Request::class);
        $request->method('getServer')
            ->willReturnCallback(static fn(string $key): string => match ($key) {
                'HTTP_HOST' => 'shop.example.test:9617',
                'SERVER_NAME' => 'shop.example.test',
                default => '',
            });
        $request->method('clientIP')->willReturn('203.0.113.8');

        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isLoggedIn')->willReturn(false);
        $session->method('get')->willReturn('');
        $sessionFactory = $this->createMock(SessionFactory::class);
        $sessionFactory->method('createFrontendSession')->willReturn($session);

        $customer = new class extends Customer {
            public function __construct()
            {
            }

            public function __call($method, $args)
            {
                return $this;
            }

            public function getId(mixed $default = 0): mixed
            {
                return 0;
            }
        };
        $captcha = $this->createMock(CaptchaManagerInterface::class);
        $captcha->method('verifySubmission')->willReturn(false);

        $provider = new AccountQueryProvider(
            $customer,
            $this->createMock(CustomerAccountService::class),
            $this->createMock(PasswordResetService::class),
            $sessionFactory,
            $request,
            $this->createMock(EventsManager::class),
            new CustomerAuthReturnUrlService($request),
            $captcha,
        );

        $result = $provider->execute('login', $submission);

        self::assertFalse($result['success']);
        self::assertSame('Human verification failed or expired. Please try again.', $result['message']);
    }
}
