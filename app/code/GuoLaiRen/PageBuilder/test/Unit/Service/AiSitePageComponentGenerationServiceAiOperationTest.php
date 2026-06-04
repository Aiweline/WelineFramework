<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiService;

/**
 * Verifies component generation dispatches through the injected AI service.
 */
final class AiSitePageComponentGenerationServiceAiOperationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
        if (!\defined('DS')) {
            \define('DS', \DIRECTORY_SEPARATOR);
        }
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

    public function testDispatchInjectedGenerateStreamRejectsMissingOnChunkCallable(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::never())->method('generateStream');

        $service = new AiSitePageComponentGenerationService(aiService: $aiService);

        $this->expectException(\InvalidArgumentException::class);
        $this->invokeCallAiOperation($service, 'generateStream', ['prompt' => 'p']);
    }

    public function testFakeModeGenerateReturnsContractPayloadWithoutCallingInjectedAiService(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::never())->method('generate');
        $aiService->expects(self::never())->method('generateStream');

        $service = new AiSitePageComponentGenerationService(aiService: $aiService);

        $result = $this->invokeCallAiOperation($service, 'generate', [
            'prompt' => 'fake block',
            'test_region' => 'content',
            'test_default_config' => [
                'content.title' => 'India card-game APK downloads',
                'content.copy' => 'Verified local fake content proves the build path.',
            ],
            'test_render_context' => [
                '_scope' => [
                    'fake_mode' => 1,
                    'build_execution_mode' => 'local_fake_demo',
                ],
                'plan_json_task' => [
                    'task_key' => 'home_page.hero',
                    'section_code' => 'hero',
                ],
            ],
        ]);

        self::assertIsString($result);
        $payload = \json_decode($result, true);
        self::assertIsArray($payload);
        self::assertStringContainsString('content.title =>', (string)($payload['extra_fields'] ?? ''));
        self::assertStringContainsString('$contentTitle = $getConfig', (string)($payload['php_variables'] ?? ''));
        self::assertStringContainsString('<?= htmlspecialchars($contentTitle', (string)($payload['html_content'] ?? ''));
        self::assertStringContainsString('India card-game APK downloads', (string)($payload['extra_fields'] ?? ''));
    }

    public function testFakeModeGenerateStreamPushesContractPayloadWithoutCallingInjectedAiService(): void
    {
        $aiService = $this->createMock(AiService::class);
        $aiService->expects(self::never())->method('generate');
        $aiService->expects(self::never())->method('generateStream');

        $service = new AiSitePageComponentGenerationService(aiService: $aiService);
        $chunks = [];

        $result = $this->invokeCallAiOperation($service, 'generateStream', [
            'prompt' => 'fake streamed block',
            'on_chunk' => static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            'test_region' => 'content',
            'test_default_config' => [],
            'test_render_context' => [
                '_scope' => ['fake_mode' => 1],
                'plan_json_task' => ['section_code' => 'proof'],
            ],
        ]);

        self::assertSame(['status' => 'fulfilled'], $result);
        self::assertCount(1, $chunks);
        $payload = \json_decode($chunks[0], true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('html_content', $payload);
        self::assertStringContainsString('data-pb-ai-action', (string)($payload['html_content'] ?? ''));
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

}
