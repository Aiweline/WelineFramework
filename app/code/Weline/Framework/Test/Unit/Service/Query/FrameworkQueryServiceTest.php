<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Service\Query\QueryProviderRegistry;

final class FrameworkQueryServiceTestProvider implements QueryProviderInterface
{
    /** @var array<int, array{operation:string, params:array}> */
    public array $calls = [];

    public function __construct(private readonly string $providerName)
    {
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $this->calls[] = [
            'operation' => $operation,
            'params' => $params,
        ];

        return [
            'provider' => $this->providerName,
            'operation' => $operation,
            'params' => $params,
        ];
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => $this->providerName,
            'name' => 'Demo provider',
            'description' => 'Demo descriptor',
            'module' => 'Test_Module',
            'operations' => [
                ['name' => 'load', 'description' => 'Load data'],
            ],
        ];
    }
}

final class FrameworkQueryServiceTestEventsManager extends EventsManager
{
    /** @var array<int, string> */
    public array $events = [];

    /** @var null|callable */
    public $beforeCallback = null;

    /** @var null|callable */
    public $afterCallback = null;

    public function __construct()
    {
    }

    public function dispatch(string $eventName, mixed &$data = []): static
    {
        $this->events[] = $eventName;

        if ($eventName === 'Weline_Framework_Query::before_execute' && is_callable($this->beforeCallback)) {
            ($this->beforeCallback)($data);
        }

        if ($eventName === 'Weline_Framework_Query::after_execute' && is_callable($this->afterCallback)) {
            ($this->afterCallback)($data);
        }

        return $this;
    }
}

final class FrameworkQueryServiceTestRegistry extends QueryProviderRegistry
{
    /** @var array<string, QueryProviderInterface> */
    private array $providers;

    /** @var array<int, array<string, mixed>> */
    private array $descriptors;

    /** @var array<int, string> */
    public array $requestedProviders = [];

    /**
     * @param array<string, QueryProviderInterface> $providers
     * @param array<int, array<string, mixed>> $descriptors
     */
    public function __construct(array $providers, array $descriptors = [])
    {
        $this->providers = $providers;
        $this->descriptors = $descriptors;
    }

    public function getProvider(string $providerName): ?QueryProviderInterface
    {
        $this->requestedProviders[] = $providerName;
        return $this->providers[$providerName] ?? null;
    }

    public function getAllDescriptors(): array
    {
        return $this->descriptors;
    }
}

final class FrameworkQueryServiceTest extends TestCase
{
    public function testExecuteDelegatesToRequestedProviderAndRunsBeforeAfterEvents(): void
    {
        $provider = new FrameworkQueryServiceTestProvider('demo');
        $registry = new FrameworkQueryServiceTestRegistry([
            'demo' => $provider,
        ]);
        $eventsManager = new FrameworkQueryServiceTestEventsManager();
        $eventsManager->beforeCallback = static function (array &$data): void {
            $data['params']['user_id'] = 7;
        };
        $eventsManager->afterCallback = static function (array &$data): void {
            $data['result']['after'] = true;
        };

        $service = new FrameworkQueryService($eventsManager, $registry);
        $result = $service->execute('demo', 'load', ['page' => 2]);

        self::assertSame(
            [
                'provider' => 'demo',
                'operation' => 'load',
                'params' => ['page' => 2, 'user_id' => 7],
                'after' => true,
            ],
            $result
        );
        self::assertSame(['demo'], $registry->requestedProviders);
        self::assertCount(1, $provider->calls);
        self::assertSame(['page' => 2, 'user_id' => 7], $provider->calls[0]['params']);
        self::assertSame(
            ['Weline_Framework_Query::before_execute', 'Weline_Framework_Query::after_execute'],
            $eventsManager->events
        );
    }

    public function testExecuteStopsWhenBeforeEventDeniesQuery(): void
    {
        $provider = new FrameworkQueryServiceTestProvider('demo');
        $registry = new FrameworkQueryServiceTestRegistry([
            'demo' => $provider,
        ]);
        $eventsManager = new FrameworkQueryServiceTestEventsManager();
        $eventsManager->beforeCallback = static function (array &$data): void {
            $data['allow'] = false;
            $data['error'] = 'Blocked by policy';
        };

        $service = new FrameworkQueryService($eventsManager, $registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Blocked by policy');

        try {
            $service->execute('demo', 'load', ['page' => 1]);
        } finally {
            self::assertSame([], $provider->calls);
            self::assertSame(['Weline_Framework_Query::before_execute'], $eventsManager->events);
        }
    }

    public function testIntrospectProvidersUsesRegistryDescriptors(): void
    {
        $descriptors = [
            [
                'provider' => 'demo',
                'name' => 'Demo Provider',
                'description' => 'Demo descriptor',
                'module' => 'Test_Module',
                'operations' => [
                    ['name' => 'load', 'description' => 'Load data'],
                    ['name' => 'save', 'description' => 'Save data'],
                ],
            ],
        ];
        $registry = new FrameworkQueryServiceTestRegistry([], $descriptors);
        $eventsManager = new FrameworkQueryServiceTestEventsManager();

        $service = new FrameworkQueryService($eventsManager, $registry);
        $result = $service->execute('framework', 'introspect', ['what' => 'providers']);

        self::assertSame(
            [[
                'provider' => 'demo',
                'name' => 'Demo Provider',
                'description' => 'Demo descriptor',
                'module' => 'Test_Module',
                'operation_count' => 2,
            ]],
            $result
        );
        self::assertSame([], $registry->requestedProviders);
        self::assertSame([], $eventsManager->events);
    }

    public function testHelpModeListsProvidersWhenProviderAndOperationAreEmpty(): void
    {
        $descriptors = [
            [
                'provider' => 'demo',
                'name' => 'Demo Provider',
                'description' => 'Demo descriptor',
                'module' => 'Test_Module',
                'operations' => [
                    ['name' => 'load', 'description' => 'Load data'],
                ],
            ],
        ];
        $registry = new FrameworkQueryServiceTestRegistry([], $descriptors);
        $service = new FrameworkQueryService(new FrameworkQueryServiceTestEventsManager(), $registry);

        $result = $service->execute(null, null, []);

        self::assertSame(
            [[
                'provider' => 'demo',
                'name' => 'Demo Provider',
                'description' => 'Demo descriptor',
                'module' => 'Test_Module',
                'operation_count' => 1,
            ]],
            $result
        );
    }

    public function testHelpModeReturnsProviderDescriptorForSingleProviderInput(): void
    {
        $descriptors = [
            [
                'provider' => 'demo',
                'name' => 'Demo Provider',
                'description' => 'Demo descriptor',
                'module' => 'Test_Module',
                'operations' => [
                    ['name' => 'load', 'description' => 'Load data'],
                ],
            ],
        ];
        $registry = new FrameworkQueryServiceTestRegistry([], $descriptors);
        $service = new FrameworkQueryService(new FrameworkQueryServiceTestEventsManager(), $registry);

        $result = $service->execute('demo', null, []);

        self::assertSame('demo', $result['provider']);
        self::assertSame('Test_Module', $result['module']);
        self::assertArrayHasKey('usage', $result);
        self::assertSame("w_query('demo', 'load', [...])", $result['operations'][0]['example']);
    }

    public function testHelpModeResolvesProviderByModuleName(): void
    {
        $descriptors = [
            [
                'provider' => 'theme',
                'name' => 'Theme Provider',
                'description' => 'Theme descriptor',
                'module' => 'Weline_Theme',
                'operations' => [
                    ['name' => 'getActiveTheme', 'description' => 'Get active theme'],
                ],
            ],
        ];
        $registry = new FrameworkQueryServiceTestRegistry([], $descriptors);
        $service = new FrameworkQueryService(new FrameworkQueryServiceTestEventsManager(), $registry);

        $result = $service->execute('Weline/Theme', null, []);

        self::assertSame('theme', $result['provider']);
        self::assertSame('Weline_Theme', $result['module']);
    }

    public function testIntrospectWhatProviderReturnsEnrichedDescriptor(): void
    {
        $descriptors = [
            [
                'provider' => 'demo',
                'name' => 'Demo Provider',
                'description' => 'Demo descriptor',
                'module' => 'Test_Module',
                'operations' => [
                    ['name' => 'load', 'description' => 'Load data'],
                ],
            ],
        ];
        $registry = new FrameworkQueryServiceTestRegistry([], $descriptors);
        $service = new FrameworkQueryService(new FrameworkQueryServiceTestEventsManager(), $registry);

        $result = $service->execute('framework', 'introspect', ['what' => 'provider', 'provider' => 'demo']);

        self::assertSame('demo', $result['provider']);
        self::assertArrayHasKey('usage', $result);
    }

    public function testFrontendOnlyHelpFiltersNonFrontendOperations(): void
    {
        $descriptors = [
            [
                'provider' => 'cart',
                'name' => 'Cart Provider',
                'description' => 'Cart descriptor',
                'module' => 'Weline_Cart',
                'operations' => [
                    ['name' => 'miniItems', 'description' => 'Mini items', 'frontend' => true, 'mode' => 'read'],
                    ['name' => 'internalSync', 'description' => 'Internal sync'],
                ],
            ],
        ];
        $registry = new FrameworkQueryServiceTestRegistry([], $descriptors);
        $service = new FrameworkQueryService(new FrameworkQueryServiceTestEventsManager(), $registry);

        $result = $service->introspectHelp('cart', ['frontend_only' => true], 'frontend_worker', true);

        self::assertCount(1, $result['operations']);
        self::assertSame('miniItems', $result['operations'][0]['name']);
    }
}
