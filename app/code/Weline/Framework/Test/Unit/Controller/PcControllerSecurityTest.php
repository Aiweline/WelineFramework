<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Controller\PcController;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Session\Session;
use Weline\Framework\Ui\FormKey;

final class PcControllerSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::getInstance()->reload();
    }

    public function testJsonContentTypeMatcherAcceptsCharsetAndJsonSuffixes(): void
    {
        $controller = new class extends PcController {
            public function __construct()
            {
            }

            public function matches(string $contentType): bool
            {
                return $this->isJsonContentType($contentType);
            }
        };

        self::assertTrue($controller->matches('application/json'));
        self::assertTrue($controller->matches('application/json; charset=utf-8'));
        self::assertTrue($controller->matches('application/vnd.api+json; charset=utf-8'));
        self::assertTrue($controller->matches('text/json'));
        self::assertFalse($controller->matches('text/html; charset=utf-8'));
    }

    public function testPcControllerCsrfDefaultsRemainOff(): void
    {
        Env::getInstance()->reload();

        $controller = new class extends PcController {
            public function __construct()
            {
            }

            public function csrfName(): string
            {
                return $this->csrf();
            }
        };

        self::assertSame('', $controller->csrfName());
    }

    public function testPcControllerCanEnableSessionFormKeyModeByConfig(): void
    {
        $env = Env::getInstance()->reload();
        $env->applyRuntimeConfig([
            'security' => [
                'csrf' => [
                    'pc_controller_mode' => 'session',
                ],
            ],
        ]);

        $controller = new class extends PcController {
            public function __construct()
            {
            }

            public function csrfName(): string
            {
                return $this->csrf();
            }
        };

        self::assertSame(FormKey::key_name, $controller->csrfName());
    }

    public function testSubclassCsrfOverrideStillWinsInSessionMode(): void
    {
        $env = Env::getInstance()->reload();
        $env->applyRuntimeConfig([
            'security' => [
                'csrf' => [
                    'pc_controller_mode' => 'session',
                ],
            ],
        ]);

        $controller = new class extends PcController {
            public function __construct()
            {
            }

            protected function csrf(): string
            {
                return 'custom_token';
            }

            public function csrfName(): string
            {
                return $this->csrf();
            }
        };

        self::assertSame('custom_token', $controller->csrfName());
    }

    public function testCsrfValidationSkipsSafeMethods(): void
    {
        $controller = new class extends PcController {
            public function __construct()
            {
            }

            public function shouldValidate(string $method): bool
            {
                return $this->shouldValidateCsrfForMethod($method);
            }
        };

        self::assertFalse($controller->shouldValidate('GET'));
        self::assertFalse($controller->shouldValidate('HEAD'));
        self::assertFalse($controller->shouldValidate('OPTIONS'));
        self::assertTrue($controller->shouldValidate('POST'));
        self::assertTrue($controller->shouldValidate('PUT'));
        self::assertTrue($controller->shouldValidate('PATCH'));
        self::assertTrue($controller->shouldValidate('DELETE'));
    }

    public function testRequestParamsAssignPolicyDefaultsToAll(): void
    {
        Env::getInstance()->reload();

        $controller = new class extends PcController {
            private array $captured = [];

            public function __construct()
            {
            }

            protected function assign(array|string $tpl_var, mixed $value = null): static
            {
                if (\is_array($tpl_var)) {
                    $this->captured = $tpl_var;
                }

                return $this;
            }

            public function apply(array $params): array
            {
                $this->assignRequestParams($params);
                return $this->captured;
            }
        };

        self::assertSame(
            ['id' => 1, 'view_mode' => 'grid'],
            $controller->apply(['id' => 1, 'view_mode' => 'grid'])
        );
    }

    public function testRequestParamsAssignPolicyCanDisableImplicitParamInjection(): void
    {
        $env = Env::getInstance()->reload();
        $env->applyRuntimeConfig([
            'security' => [
                'view' => [
                    'assign_params_mode' => 'none',
                ],
            ],
        ]);

        $controller = new class extends PcController {
            private array $captured = [];

            public function __construct()
            {
            }

            protected function assign(array|string $tpl_var, mixed $value = null): static
            {
                if (\is_array($tpl_var)) {
                    $this->captured = $tpl_var;
                }

                return $this;
            }

            public function apply(array $params): array
            {
                $this->assignRequestParams($params);
                return $this->captured;
            }
        };

        self::assertSame([], $controller->apply(['id' => 1, 'view_mode' => 'grid']));
    }

    public function testRequestParamsAssignPolicyCanRestrictByPrefixes(): void
    {
        $env = Env::getInstance()->reload();
        $env->applyRuntimeConfig([
            'security' => [
                'view' => [
                    'assign_params_mode' => 'prefix',
                    'assign_params_prefixes' => ['view_', 'safe.'],
                ],
            ],
        ]);

        $controller = new class extends PcController {
            private array $captured = [];

            public function __construct()
            {
            }

            protected function assign(array|string $tpl_var, mixed $value = null): static
            {
                if (\is_array($tpl_var)) {
                    $this->captured = $tpl_var;
                }

                return $this;
            }

            public function apply(array $params): array
            {
                $this->assignRequestParams($params);
                return $this->captured;
            }
        };

        self::assertSame(
            ['view_mode' => 'grid', 'safe.token' => 'ok'],
            $controller->apply([
                'id' => 1,
                'view_mode' => 'grid',
                'unsafe' => 'drop',
                'safe.token' => 'ok',
            ])
        );
    }

    public function testFormKeyPeekDoesNotMintAToken(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects(self::never())->method('setData');
        $session->method('getData')->willReturn(null);

        self::assertNull((new FormKey($session))->peekKey());
    }

    public function testCsrfMismatchTerminatesAsForbiddenInsteadOfMissingRoute(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getPost')->willReturn('not-the-session-key');
        $request->method('getContentType')->willReturn('application/json');
        $request->method('getServer')->willReturn('');
        $request->method('getParam')->willReturn('');
        $request->method('getHeader')->willReturn('application/json');

        $controller = new class extends PcController {
            public function __construct()
            {
            }

            protected function csrf(): string
            {
                return FormKey::key_name;
            }

            protected function getExpectedCsrfToken(string $name): ?string
            {
                return 'expected-token';
            }

            public function check(): void
            {
                $this->isAllowed();
            }
        };

        $property = new \ReflectionProperty(PcController::class, 'request');
        $property->setAccessible(true);
        $property->setValue($controller, $request);

        try {
            $controller->check();
            self::fail('CSRF mismatch must stop the request');
        } catch (ResponseTerminateException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            self::assertStringContainsString('页面校验已失效', $exception->getBody());
            self::assertStringNotContainsString('404', $exception->getBody());
        }
    }
}
