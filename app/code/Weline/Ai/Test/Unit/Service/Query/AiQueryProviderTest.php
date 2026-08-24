<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Extends\Module\Weline_Framework\Query\AiQueryProvider;
use Weline\Ai\Service\AiService;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * AiQueryProvider 行为单测：
 *  - 把每个 operation 的入参完整透传给 AiService 对应方法
 *  - generateStreamBatch 在串行回退 (concurrency=1) 时按 settled 协议返回
 *  - 不支持 op / 缺 callable / 空 prompt 的边界全部抛 InvalidArgumentException
 */
final class AiQueryProviderTest extends TestCase
{
    protected function setUp(): void
    {
        SchedulerSystem::disableScheduler();
        WelineEnv::getInstance()->reset();
    }

    protected function tearDown(): void
    {
        SchedulerSystem::disableScheduler();
        WelineEnv::getInstance()->reset();
    }

    public function testGetProviderNameReturnsAi(): void
    {
        $provider = new AiQueryProvider($this->createMock(AiService::class));
        self::assertSame('ai', $provider->getProviderName());
    }

    public function testGenerateForwardsAllParamsToAiService(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generate')
            ->with(
                'hello world',
                'gpt-test',
                'pagebuilder_component_generation',
                'en_US',
                ['temperature' => 0.5, 'max_tokens' => 1024],
                42,
                true
            )
            ->willReturn('reply text');

        $provider = new AiQueryProvider($aiService);
        $result = $provider->execute('generate', [
            'prompt' => 'hello world',
            'model_code' => 'gpt-test',
            'scenario_code' => 'pagebuilder_component_generation',
            'locale' => 'en_US',
            'params' => ['temperature' => 0.5, 'max_tokens' => 1024],
            'user_id' => 42,
            'is_backend' => true,
        ]);

        self::assertSame('reply text', $result);
    }

    public function testGenerateRejectsEmptyPrompt(): void
    {
        $provider = new AiQueryProvider($this->createMock(AiService::class));
        $this->expectException(\InvalidArgumentException::class);
        $provider->execute('generate', ['prompt' => '   ']);
    }

    public function testGenerateStreamForwardsCallbackToAiService(): void
    {
        $captured = [];
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStream')
            ->with(
                'stream prompt',
                self::callback(static function (callable $callback) use (&$captured): bool {
                    $callback('chunk-A');
                    $callback('chunk-B');
                    $captured = [$callback('chunk-C')];
                    return true;
                }),
                'gpt-test',
                'scenario-x',
                'zh_Hans_CN',
                ['temperature' => 0.2]
            );

        $received = [];
        $provider = new AiQueryProvider($aiService);
        $result = $provider->execute('generateStream', [
            'prompt' => 'stream prompt',
            'on_chunk' => static function (string $chunk) use (&$received): bool {
                $received[] = $chunk;
                return true;
            },
            'model_code' => 'gpt-test',
            'scenario_code' => 'scenario-x',
            'locale' => 'zh_Hans_CN',
            'params' => ['temperature' => 0.2],
        ]);

        self::assertSame(['chunk-A', 'chunk-B', 'chunk-C'], $received);
        self::assertSame(['status' => 'fulfilled'], $result);
    }

    public function testGenerateStreamRequiresOnChunkCallable(): void
    {
        $provider = new AiQueryProvider($this->createMock(AiService::class));
        $this->expectException(\InvalidArgumentException::class);
        $provider->execute('generateStream', [
            'prompt' => 'p',
            'on_chunk' => 'not-a-callable',
        ]);
    }

    public function testGenerateStreamResultForwardsAndReturnsArray(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStreamResult')
            ->willReturn(['success' => true, 'mode' => 'stream', 'extra' => 'value']);

        $provider = new AiQueryProvider($aiService);
        $payload = $provider->execute('generateStreamResult', [
            'prompt' => 'p',
            'on_chunk' => static fn(): bool => true,
        ]);

        self::assertSame(['success' => true, 'mode' => 'stream', 'extra' => 'value'], $payload);
    }

    public function testGenerateStructuredForwardsParamsAndReturnsArray(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::once())
            ->method('generateStructured')
            ->with('p', 'gpt-test', 'scenario-x', ['temperature' => 0.1])
            ->willReturn(['choices' => [['message' => ['content' => 'hello']]]]);

        $provider = new AiQueryProvider($aiService);
        $payload = $provider->execute('generateStructured', [
            'prompt' => 'p',
            'model_code' => 'gpt-test',
            'scenario_code' => 'scenario-x',
            'params' => ['temperature' => 0.1],
        ]);

        self::assertSame(['choices' => [['message' => ['content' => 'hello']]]], $payload);
    }

    public function testGenerateStreamBatchSerialFallbackInvokesEachTaskInOrder(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::exactly(3))
            ->method('generateStream')
            ->willReturnCallback(static function (
                string $prompt,
                callable $callback
            ): void {
                $callback('chunk-for-' . $prompt);
            });

        $received = [];
        $provider = new AiQueryProvider($aiService);
        $events = $provider->execute('generateStreamBatch', [
            'concurrency' => 1,
            'tasks' => [
                'a' => [
                    'prompt' => 'one',
                    'on_chunk' => static function (string $chunk) use (&$received): bool {
                        $received['a'][] = $chunk;
                        return true;
                    },
                ],
                'b' => [
                    'prompt' => 'two',
                    'on_chunk' => static function (string $chunk) use (&$received): bool {
                        $received['b'][] = $chunk;
                        return true;
                    },
                ],
                'c' => [
                    'prompt' => 'three',
                    'on_chunk' => static function (string $chunk) use (&$received): bool {
                        $received['c'][] = $chunk;
                        return true;
                    },
                ],
            ],
        ]);

        self::assertSame(['a', 'b', 'c'], \array_keys($events));
        self::assertSame('fulfilled', $events['a']['status']);
        self::assertSame('fulfilled', $events['b']['status']);
        self::assertSame('fulfilled', $events['c']['status']);

        self::assertSame(['chunk-for-one'], $received['a']);
        self::assertSame(['chunk-for-two'], $received['b']);
        self::assertSame(['chunk-for-three'], $received['c']);
    }

    public function testGenerateStreamBatchCapturesPerTaskExceptionsAsRejected(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->method('generateStream')
            ->willReturnCallback(static function (string $prompt, callable $callback): void {
                if ($prompt === 'bad') {
                    throw new \RuntimeException('vendor-down');
                }
                $callback('ok-' . $prompt);
            });

        $provider = new AiQueryProvider($aiService);
        $events = $provider->execute('generateStreamBatch', [
            'concurrency' => 1,
            'tasks' => [
                'good' => [
                    'prompt' => 'good',
                    'on_chunk' => static fn(): bool => true,
                ],
                'bad' => [
                    'prompt' => 'bad',
                    'on_chunk' => static fn(): bool => true,
                ],
                'tail' => [
                    'prompt' => 'tail',
                    'on_chunk' => static fn(): bool => true,
                ],
            ],
        ]);

        self::assertSame('fulfilled', $events['good']['status']);
        self::assertSame('rejected', $events['bad']['status']);
        self::assertInstanceOf(\RuntimeException::class, $events['bad']['error']);
        self::assertSame('vendor-down', $events['bad']['error']->getMessage());
        self::assertSame('fulfilled', $events['tail']['status']);
    }

    public function testGenerateStreamBatchRequiresEachTaskOnChunkCallable(): void
    {
        $provider = new AiQueryProvider($this->createMock(AiService::class));
        $this->expectException(\InvalidArgumentException::class);
        $provider->execute('generateStreamBatch', [
            'tasks' => [
                'a' => [
                    'prompt' => 'p',
                    'on_chunk' => 'not-callable',
                ],
            ],
        ]);
    }

    public function testGenerateStreamBatchInvokesOnEventCallbackForEachSettlement(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->method('generateStream')
            ->willReturnCallback(static function (string $prompt, callable $callback): void {
                $callback('c-' . $prompt);
            });

        $observed = [];
        $provider = new AiQueryProvider($aiService);
        $provider->execute('generateStreamBatch', [
            'concurrency' => 1,
            'tasks' => [
                'a' => ['prompt' => 'one', 'on_chunk' => static fn(): bool => true],
                'b' => ['prompt' => 'two', 'on_chunk' => static fn(): bool => true],
            ],
            'on_event' => static function (string|int $key, array $event) use (&$observed): void {
                $observed[$key] = $event['status'];
            },
        ]);

        self::assertSame(['a' => 'fulfilled', 'b' => 'fulfilled'], $observed);
    }

    public function testExecuteRejectsUnknownOperation(): void
    {
        $provider = new AiQueryProvider($this->createMock(AiService::class));
        $this->expectException(\InvalidArgumentException::class);
        $provider->execute('chat-with-bot', []);
    }

    public function testGetDescriptorListsAllSupportedOperations(): void
    {
        $provider = new AiQueryProvider($this->createMock(AiService::class));
        $descriptor = $provider->getDescriptor();

        self::assertSame('ai', $descriptor['provider']);
        self::assertSame('Weline_Ai', $descriptor['module']);
        $names = \array_map(static fn(array $op): string => (string)($op['name'] ?? ''), $descriptor['operations'] ?? []);
        foreach (['generate', 'generateStream', 'generateStreamResult', 'generateStructured', 'generateStreamBatch'] as $expectedOp) {
            self::assertContains($expectedOp, $names, "descriptor missing op {$expectedOp}");
        }
    }
}
