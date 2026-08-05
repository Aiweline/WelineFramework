<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Extends\Module\Weline_Framework\Query\AiQueryProvider;
use Weline\Ai\Service\AiService;
use Weline\Framework\Session\SessionFactory;

final class AiLegacyExecutionBoundaryTest extends TestCase
{
    public function testLegacyConnectionBoundFrontendControllerIsRemoved(): void
    {
        $moduleRoot = dirname(__DIR__, 4);

        self::assertFileDoesNotExist($moduleRoot . '/Controller/Frontend/AiController.php');
    }

    public function testPublicGuidesUseRuntimeTasksInsteadOfConnectionBoundStreaming(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        $guide = (string)file_get_contents($moduleRoot . '/Controller/Frontend/Index.php');
        $readme = (string)file_get_contents($moduleRoot . '/README.md');
        $manual = (string)file_get_contents($moduleRoot . '/doc/用户/AI模块使用手册.md');

        foreach ([$guide, $readme, $manual] as $content) {
            self::assertStringContainsString('runtime_task', $content);
            self::assertStringNotContainsString('echo $chunk', $content);
            self::assertStringNotContainsString('flush()', $content);
        }

        self::assertStringNotContainsString('/ai/api/chat/generate', $readme);
        self::assertStringNotContainsString('/ai/api/chat/generate', $manual);
    }

    /**
     * 执行型操作（generate/generateStream/generateImage/executeAgent 等）仅供
     * 服务端 w_query 调用链使用；它们绝不能出现在提供器描述符中，否则会被
     * 前端查询网关暴露给浏览器直接调用。
     */
    public function testGenericAiQueryGatewayDoesNotDeclareExecutionOperationsToFrontend(): void
    {
        $sessionFactory = $this->createMock(SessionFactory::class);
        $provider = new AiQueryProvider(
            $this->createMock(AiService::class),
            $sessionFactory,
        );

        $declared = array_map(
            static fn(array $operation): string => (string)($operation['name'] ?? ''),
            $provider->getDescriptor()['operations'] ?? []
        );

        foreach ([
            'generate',
            'generateText',
            'generateImage',
            'generateStream',
            'generateStreamBatch',
            'chat',
            'analyzeImage',
            'executeAgent',
        ] as $executionOperation) {
            self::assertNotContains(
                $executionOperation,
                $declared,
                $executionOperation . ' must not be declared in the frontend-facing descriptor.'
            );
        }
    }

    public function testLegacyChatOperationIsRemovedFromGenericAiQueryGateway(): void
    {
        $provider = new AiQueryProvider(
            $this->createMock(AiService::class),
            $this->createMock(SessionFactory::class),
        );

        try {
            $provider->execute('chat', ['message' => 'hi']);
            self::fail('chat must not be exposed by the generic AI query gateway.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('chat', $exception->getMessage());
        }
    }
}
