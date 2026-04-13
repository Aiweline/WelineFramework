<?php
declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Extends\Module\Weline_Ai\Agent;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Interface\ToolInterface;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\Provider\ProviderFactory;
use Weline\Ai\Service\Provider\ProviderInterface;
use Weline\Websites\Extends\Module\Weline_Ai\Agent\WebsiteBuilderAgent;

final class WebsiteBuilderAgentTest extends TestCase
{
    public function testGetMaxIterationsUsesRaisedDefault(): void
    {
        $agent = new WebsiteBuilderAgent();
        $this->assertSame(12, $agent->getMaxIterations());
    }

    public function testExecuteHonorsMaxIterationsParamOverride(): void
    {
        $tool = $this->createToolDouble('loop_tool', ['ok' => true]);
        $provider = $this->createMock(ProviderInterface::class);
        $provider->expects($this->exactly(2))
            ->method('generate')
            ->willReturn([
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'tc_1',
                        'name' => 'loop_tool',
                        'arguments' => [],
                    ],
                ],
            ]);

        $providerFactory = $this->createMock(ProviderFactory::class);
        $providerFactory->expects($this->once())
            ->method('getProvider')
            ->willReturn($provider);

        $model = $this->createMock(AiModel::class);
        $model->method('getModelCode')->willReturn('test-model');

        $agent = new class([$tool]) extends WebsiteBuilderAgent {
            public function __construct(private readonly array $testTools)
            {
            }

            public function getTools(): array
            {
                return $this->testTools;
            }
        };

        $result = $agent->execute(
            prompt: 'loop',
            model: $model,
            params: [
                'provider_factory' => $providerFactory,
                'max_iterations' => 2,
            ]
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('最大轮次', (string)$result->error);
    }

    public function testExecuteStopsLoopWhenPurchaseToolReturnsSuccess(): void
    {
        $tool = $this->createToolDouble('purchase_domain_and_build_site', [
            'success' => true,
            'domain' => 'demo.local',
            'message' => '建站完成：demo.local',
        ]);
        $provider = $this->createMock(ProviderInterface::class);
        $provider->expects($this->once())
            ->method('generate')
            ->willReturn([
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'tc_1',
                        'name' => 'purchase_domain_and_build_site',
                        'arguments' => [],
                    ],
                ],
            ]);

        $providerFactory = $this->createMock(ProviderFactory::class);
        $providerFactory->expects($this->once())
            ->method('getProvider')
            ->willReturn($provider);

        $model = $this->createMock(AiModel::class);
        $model->method('getModelCode')->willReturn('test-model');

        $agent = new class([$tool]) extends WebsiteBuilderAgent {
            public function __construct(private readonly array $testTools)
            {
            }

            public function getTools(): array
            {
                return $this->testTools;
            }
        };

        $result = $agent->execute(
            prompt: 'build site',
            model: $model,
            params: [
                'provider_factory' => $providerFactory,
                'max_iterations' => 2,
            ]
        );

        $this->assertTrue($result->success);
        $this->assertStringContainsString('demo.local', (string)$result->content);
    }

    public function testSystemPromptContainsDemoHintWhenDemoModeEnabled(): void
    {
        $agent = new WebsiteBuilderAgent();
        $prompt = $agent->getSystemPrompt([
            'account_id' => 900001,
            'demo_mode' => true,
        ]);

        $this->assertStringContainsString('演示/沙盒环境', $prompt);
        $this->assertStringContainsString('避免反复重试', $prompt);
    }

    public function testExecutePropagatesStreamCancellationToProviderStreamFull(): void
    {
        $provider = new class implements ProviderInterface {
            public function generate(AiModel $model, string $prompt, array $params = []): array
            {
                return ['content' => '', 'tool_calls' => []];
            }

            public function generateStream(AiModel $model, string $prompt, callable $callback, array $params = []): array
            {
                return ['content' => '', 'usage' => []];
            }

            public function supports(string $modelCode): bool
            {
                return true;
            }

            public function getProviderCode(): string
            {
                return 'test';
            }

            public function getSupportedModels(): array
            {
                return [];
            }

            public function generateStreamFull(AiModel $model, string $prompt, array $params = []): array
            {
                $continued = ($params['on_content'])('partial chunk');
                if ($continued === false) {
                    throw new \RuntimeException('upstream cancelled');
                }

                return ['content' => 'final', 'tool_calls' => []];
            }
        };

        $providerFactory = $this->createMock(ProviderFactory::class);
        $providerFactory->expects($this->once())
            ->method('getProvider')
            ->willReturn($provider);

        $model = $this->createMock(AiModel::class);
        $model->method('getModelCode')->willReturn('test-model');

        $agent = new WebsiteBuilderAgent();
        $result = $agent->execute(
            prompt: 'build site',
            model: $model,
            params: [
                'provider_factory' => $providerFactory,
                'max_iterations' => 1,
            ],
            streamCallback: static function (string $eventType, array $data): bool {
                if ($eventType === 'ai_response') {
                    return false;
                }

                return true;
            }
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('upstream cancelled', (string)$result->error);
    }

    private function createToolDouble(string $name, array $result): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getDescription')->willReturn('test tool');
        $tool->method('getParameters')->willReturn([
            'type' => 'object',
            'properties' => [],
        ]);
        $tool->method('isEnabled')->willReturn(true);
        $tool->method('execute')->willReturn($result);
        return $tool;
    }
}

