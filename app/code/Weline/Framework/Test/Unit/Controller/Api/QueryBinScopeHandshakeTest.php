<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Binary\WelineBinaryCodec;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Context;
use Weline\Framework\Controller\Api\QueryBin;
use Weline\Framework\Controller\Core;
use Weline\Framework\Http\Request;
use Weline\Framework\Runtime\FrontendWorkerScopeProviderInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendQueryGateway;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\FrontendWorkerStateStoreInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeRolloutDecision;

final class QueryBinScopeHandshakeTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];
    private ?Context $previousContext = null;

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        Context::leave();
        RequiredScopeProvider::$binding = null;
    }

    protected function tearDown(): void
    {
        Context::leave();
        if ($this->previousContext instanceof Context) {
            Context::enter($this->previousContext);
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        RequiredScopeProvider::$binding = null;
    }

    public function testRequiredScopeRejectsEmptyBootstrapBeforeSessionWrite(): void
    {
        $store = new InspectableFrontendWorkerStateStore();
        $controller = $this->controller($store, $this->registry([
            FrontendWorkerScopeProviderInterface::class => RequiredScopeProvider::class,
        ]));
        $this->enterHttpsContext();

        try {
            $this->handshake($controller, [
                'type' => 'handshake',
                'deploy_version' => 'test-deploy',
                'worker_build_id' => 'test-worker',
            ]);
            self::fail('Required Scope handshake unexpectedly created an unbound session.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('scope_binding_required', $exception->getErrorCode());
            self::assertSame(401, $exception->getHttpStatus());
        }

        self::assertSame(0, $store->transactions);
        self::assertSame([], $store->state);
    }

    public function testBrokenOptionalProviderRegistryFailsClosedBeforeSessionWrite(): void
    {
        $store = new InspectableFrontendWorkerStateStore();
        $missingRegistry = sys_get_temp_dir() . '/weline-query-bin-missing-' . bin2hex(random_bytes(6)) . '.php';
        $controller = $this->controller($store, $missingRegistry);
        $this->enterHttpsContext();

        try {
            $this->handshake($controller, [
                'type' => 'handshake',
                'deploy_version' => 'test-deploy',
                'worker_build_id' => 'test-worker',
            ]);
            self::fail('Broken provider registry unexpectedly created a session.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('scope_service_unavailable', $exception->getErrorCode());
            self::assertSame(503, $exception->getHttpStatus());
        }

        self::assertSame(0, $store->transactions);
        self::assertSame([], $store->state);
    }

    public function testScopeBootstrapStoreFailurePreservesServiceUnavailableStatus(): void
    {
        $this->enterHttpsContext();
        $store = new InspectableFrontendWorkerStateStore();
        $sessionService = new FrontendWorkerSessionService($store);
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST);
        $now = time();
        $binding = new FrontendWorkerScopeBinding(
            $scope,
            'shop.example.test',
            hash('sha256', 'scope-token'),
            $now,
            $now + 1800,
            true,
        );
        RequiredScopeProvider::$binding = $binding;
        $bootstrap = $sessionService->createScopeBootstrap($binding);

        $controller = $this->controller(
            $store,
            $this->registry([
                FrontendWorkerScopeProviderInterface::class => RequiredScopeProvider::class,
            ]),
            $sessionService,
        );
        $this->installRequest($controller, [
            'HTTP_COOKIE' => $bootstrap['cookie_name'] . '=scope-token',
            'HTTP_HOST' => 'shop.example.test',
            'WELINE_FULL_REQUEST_URI' => 'https://shop.example.test/api/framework/query-bin',
        ]);
        $store->failure = new FrontendQueryException(
            'worker_capacity_exhausted',
            'Worker session capacity is exhausted.',
            503,
        );

        try {
            $this->handshake($controller, [
                'type' => 'handshake',
                'deploy_version' => 'test-deploy',
                'worker_build_id' => 'test-worker',
                'scope_bootstrap_id' => $bootstrap['bootstrap_id'],
            ]);
            self::fail('Scope bootstrap store failure was unexpectedly downgraded.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('worker_capacity_exhausted', $exception->getErrorCode());
            self::assertSame(503, $exception->getHttpStatus());
        }
    }

    public function testBinaryOutputGuardNeverLogsCapturedBytes(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Framework/Controller/Api/QueryBin.php');

        self::assertStringContainsString("'sha256' => \\hash('sha256', \$preExisting)", $source);
        self::assertStringContainsString("'sha256' => \\hash('sha256', \$captured)", $source);
        self::assertStringNotContainsString("mb_substr(\\trim(\$preExisting)", $source);
        self::assertStringNotContainsString("mb_substr(\\trim(\$captured)", $source);
    }

    private function controller(
        InspectableFrontendWorkerStateStore $store,
        string $registryFile,
        ?FrontendWorkerSessionService $sessionService = null,
    ): QueryBin {
        $gateway = (new \ReflectionClass(FrontendQueryGateway::class))->newInstanceWithoutConstructor();
        return new QueryBin(
            new WelineBinaryCodec(),
            $gateway,
            $sessionService ?? new FrontendWorkerSessionService($store),
            new RuntimeProviderResolver(new ServiceProviderRegistry($registryFile)),
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function handshake(QueryBin $controller, array $payload): array
    {
        $method = new \ReflectionMethod(QueryBin::class, 'handleHandshake');
        $result = $method->invoke($controller, $payload, 'test-request');
        self::assertIsArray($result);
        return $result;
    }

    /** @param array<string, class-string> $provides */
    private function registry(array $provides): string
    {
        $file = sys_get_temp_dir() . '/weline-query-bin-registry-' . bin2hex(random_bytes(6)) . '.php';
        $this->files[] = $file;
        $compiled = [
            'format' => 1,
            'order' => ['Test_Scope'],
            'modules' => ['Test_Scope' => ['provides' => $provides]],
        ];
        file_put_contents($file, '<?php return ' . var_export($compiled, true) . ';');
        return $file;
    }

    /** @param array<string, string> $server */
    private function installRequest(QueryBin $controller, array $server): void
    {
        $context = Context::getCurrent();
        self::assertInstanceOf(Context::class, $context);
        foreach ($server as $key => $value) {
            $context->set('input.server.' . $key, $value);
        }
        $request = (new \ReflectionClass(Request::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(Core::class, 'request');
        $property->setValue($controller, $request);
    }

    private function enterHttpsContext(): void
    {
        Context::leave();
        Context::enter(new Context([
            'meta' => ['type' => 'request'],
            'input' => [
                'scheme' => 'https',
                'host' => 'shop.example.test',
                'uri' => '/api/framework/query-bin',
                'full_request_uri' => 'https://shop.example.test/api/framework/query-bin',
                'server' => [
                    'HTTP_HOST' => 'shop.example.test',
                    'WELINE_FULL_REQUEST_URI' => 'https://shop.example.test/api/framework/query-bin',
                    'REQUEST_SCHEME' => 'https',
                    'REQUEST_URI' => '/api/framework/query-bin',
                ],
            ],
        ]));
    }
}

final class InspectableFrontendWorkerStateStore implements FrontendWorkerStateStoreInterface
{
    /** @var array<string, mixed> */
    public array $state = [];
    public int $transactions = 0;
    public ?FrontendQueryException $failure = null;

    public function transaction(callable $callback): mixed
    {
        ++$this->transactions;
        if ($this->failure instanceof FrontendQueryException) {
            throw $this->failure;
        }
        return $callback($this->state);
    }

    public function driver(): string
    {
        return 'test-memory';
    }

    public function isShared(): bool
    {
        return false;
    }
}

final class RequiredScopeProvider implements FrontendWorkerScopeProviderInterface
{
    public static ?FrontendWorkerScopeBinding $binding = null;

    public function requiresBinding(string $requestScheme): bool
    {
        return true;
    }

    public function rollout(ScopeIdentity $scope, string $requestScheme): FrontendWorkerScopeRolloutDecision
    {
        throw new \LogicException('Unused test method.');
    }

    public function issueToken(
        ScopeIdentity $trustedScope,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?string {
        throw new \LogicException('Unused test method.');
    }

    public function verifyToken(
        string $token,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?FrontendWorkerScopeBinding {
        return self::$binding;
    }

    public function restoreBinding(
        ?FrontendWorkerScopeBinding $binding,
        string $requestScheme,
        string $authorityHost,
        ?int $now = null,
    ): ?ScopeIdentity {
        return $binding?->scope;
    }
}
