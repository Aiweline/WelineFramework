<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Service\Query\QueryProviderRegistry;

/**
 * 验证 unified-query-provider 接入：
 *  - 注入了 AiService → 直接调（兼容既有 mock 测试，旁路 w_query）
 *  - 未注入 AiService → 走 `w_query('ai', ...)`，由 QueryProviderRegistry 上的 ai provider 接管
 */
final class AiSitePageComponentGenerationServiceWQueryTest extends TestCase
{
    /** @var array{providers: array<string, QueryProviderInterface>, definitionsLoaded: bool} */
    private array $registrySnapshot = [];

    protected function setUp(): void
    {
        $this->registrySnapshot = $this->snapshotRegistry();
    }

    protected function tearDown(): void
    {
        $this->restoreRegistry($this->registrySnapshot);
    }

    public function testCallAiOperationGenerateUsesInjectedAiServiceWhenAvailable(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generate')
            ->with(
                self::callback(static function (string $prompt): bool {
                    return \str_contains($prompt, 'AI BUILDER SKILL CAPABILITY')
                        && \str_contains($prompt, 'hello');
                }),
                'gpt-test',
                'pagebuilder_component_generation',
                'en_US',
                ['temperature' => 0.5],
                7,
                true
            )
            ->willReturn('mocked');

        $service = new AiSitePageComponentGenerationService(aiService: $aiService);

        $result = $this->invokeCallAiOperation($service, 'generate', [
            'prompt' => 'hello',
            'model_code' => 'gpt-test',
            'scenario_code' => 'pagebuilder_component_generation',
            'locale' => 'en_US',
            'params' => ['temperature' => 0.5],
            'user_id' => 7,
            'is_backend' => true,
        ]);

        self::assertSame('mocked', $result);
    }

    public function testCallAiOperationGenerateStreamUsesInjectedAiServiceCallback(): void
    {
        $received = [];
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->willReturnCallback(static function (
                string $prompt,
                callable $callback
            ) use (&$received): void {
                $received[] = $prompt;
                $callback('chunk-1');
                $callback('chunk-2');
            });

        $service = new AiSitePageComponentGenerationService(aiService: $aiService);

        $captured = [];
        $result = $this->invokeCallAiOperation($service, 'generateStream', [
            'prompt' => 'streamed',
            'on_chunk' => static function (string $chunk) use (&$captured): bool {
                $captured[] = $chunk;
                return true;
            },
        ]);

        self::assertCount(1, $received);
        self::assertStringContainsString('AI BUILDER SKILL CAPABILITY', $received[0]);
        self::assertStringContainsString('streamed', $received[0]);
        self::assertSame(['chunk-1', 'chunk-2'], $captured);
        self::assertSame(['status' => 'fulfilled'], $result);
    }

    public function testCallAiOperationFallsBackToWQueryWhenNoAiServiceInjected(): void
    {
        $callRecord = [];
        $fakeProvider = new class ($callRecord) implements QueryProviderInterface {
            public function __construct(private array &$callRecord)
            {
            }

            public function getProviderName(): string
            {
                return 'ai';
            }

            public function execute(string $operation, array $params = []): mixed
            {
                $this->callRecord[] = ['operation' => $operation, 'params' => $params];

                return match ($operation) {
                    'generate' => 'fake-text-response',
                    'generateStream' => ['status' => 'fulfilled'],
                    default => throw new \InvalidArgumentException('unsupported in fake: ' . $operation),
                };
            }

            public function getDescriptor(): array
            {
                return [
                    'provider' => 'ai',
                    'name' => 'fake-ai',
                    'description' => 'unit test only',
                    'module' => 'TestFixture',
                    'operations' => [],
                ];
            }
        };

        $this->overrideRegisteredProvider('ai', $fakeProvider);

        $service = new AiSitePageComponentGenerationService();

        $result = $this->invokeCallAiOperation($service, 'generate', [
            'prompt' => 'hello via w_query',
            'scenario_code' => 'pagebuilder_component_generation',
            'params' => ['temperature' => 0.35],
        ]);

        self::assertSame('fake-text-response', $result);
        self::assertCount(1, $callRecord);
        self::assertSame('generate', $callRecord[0]['operation']);
        self::assertStringContainsString('AI BUILDER SKILL CAPABILITY', $callRecord[0]['params']['prompt']);
        self::assertStringContainsString('hello via w_query', $callRecord[0]['params']['prompt']);
        self::assertSame('pagebuilder_component_generation', $callRecord[0]['params']['scenario_code']);
        self::assertSame(['temperature' => 0.35], $callRecord[0]['params']['params']);
    }

    public function testCallAiOperationStreamFallsBackToWQueryAndForwardsCallback(): void
    {
        $forwardedCallback = null;
        $fakeProvider = new class ($forwardedCallback) implements QueryProviderInterface {
            public function __construct(private mixed &$forwardedCallback)
            {
            }

            public function getProviderName(): string
            {
                return 'ai';
            }

            public function execute(string $operation, array $params = []): mixed
            {
                if ($operation === 'generateStream') {
                    $this->forwardedCallback = $params['on_chunk'] ?? null;
                    if (\is_callable($this->forwardedCallback)) {
                        ($this->forwardedCallback)('w-query-chunk-A');
                        ($this->forwardedCallback)('w-query-chunk-B');
                    }
                    return ['status' => 'fulfilled'];
                }
                throw new \InvalidArgumentException('unsupported');
            }

            public function getDescriptor(): array
            {
                return ['provider' => 'ai', 'operations' => []];
            }
        };

        $this->overrideRegisteredProvider('ai', $fakeProvider);

        $service = new AiSitePageComponentGenerationService();

        $captured = [];
        $result = $this->invokeCallAiOperation($service, 'generateStream', [
            'prompt' => 'p',
            'on_chunk' => static function (string $chunk) use (&$captured): bool {
                $captured[] = $chunk;
                return true;
            },
        ]);

        self::assertSame(['status' => 'fulfilled'], $result);
        self::assertSame(['w-query-chunk-A', 'w-query-chunk-B'], $captured);
        self::assertNotNull($forwardedCallback, 'on_chunk should be forwarded into the registered ai provider');
    }

    public function testDispatchInjectedGenerateStreamRejectsMissingOnChunkCallable(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::never())->method('generateStream');

        $service = new AiSitePageComponentGenerationService(aiService: $aiService);

        $this->expectException(\InvalidArgumentException::class);
        $this->invokeCallAiOperation($service, 'generateStream', ['prompt' => 'p']);
    }

    private function invokeCallAiOperation(
        AiSitePageComponentGenerationService $service,
        string $operation,
        array $params
    ): mixed {
        $reflector = new \ReflectionMethod($service, 'callAiOperation');
        $reflector->setAccessible(true);

        return $reflector->invoke($service, $operation, $params);
    }

    private function overrideRegisteredProvider(string $name, QueryProviderInterface $provider): void
    {
        $registry = ObjectManager::getInstance(QueryProviderRegistry::class);
        $reflection = new \ReflectionClass($registry);

        $providersProp = $reflection->getProperty('providers');
        $providersProp->setAccessible(true);
        $current = $providersProp->getValue($registry);
        if (!\is_array($current)) {
            $current = [];
        }
        $current[$name] = $provider;
        $providersProp->setValue($registry, $current);

        $loadedProp = $reflection->getProperty('definitionsLoaded');
        $loadedProp->setAccessible(true);
        $loadedProp->setValue($registry, true);
    }

    /**
     * @return array{providers: array<string, QueryProviderInterface>, definitionsLoaded: bool}
     */
    private function snapshotRegistry(): array
    {
        $registry = ObjectManager::getInstance(QueryProviderRegistry::class);
        $reflection = new \ReflectionClass($registry);

        $providersProp = $reflection->getProperty('providers');
        $providersProp->setAccessible(true);
        $providers = $providersProp->getValue($registry);

        $loadedProp = $reflection->getProperty('definitionsLoaded');
        $loadedProp->setAccessible(true);
        $loaded = $loadedProp->getValue($registry);

        return [
            'providers' => \is_array($providers) ? $providers : [],
            'definitionsLoaded' => (bool)$loaded,
        ];
    }

    /**
     * @param array{providers: array<string, QueryProviderInterface>, definitionsLoaded: bool} $snapshot
     */
    private function restoreRegistry(array $snapshot): void
    {
        $registry = ObjectManager::getInstance(QueryProviderRegistry::class);
        $reflection = new \ReflectionClass($registry);

        $providersProp = $reflection->getProperty('providers');
        $providersProp->setAccessible(true);
        $providersProp->setValue($registry, $snapshot['providers']);

        $loadedProp = $reflection->getProperty('definitionsLoaded');
        $loadedProp->setAccessible(true);
        $loadedProp->setValue($registry, $snapshot['definitionsLoaded']);
    }
}
