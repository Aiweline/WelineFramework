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

    public function testGenericAiQueryGatewayDoesNotExposeDirectGenerationOperations(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        $provider = (string)file_get_contents(
            $moduleRoot . '/extends/module/Weline_Framework/Query/AiQueryProvider.php'
        );

        foreach ([
            "'generate', 'generateText' =>",
            "'generateImage' =>",
            "'generateStream' =>",
            "'generateStreamBatch' =>",
            "'name' => 'generate'",
        ] as $legacyOperation) {
            self::assertStringNotContainsString($legacyOperation, $provider);
        }
    }

    public function testGenericAiQueryGatewayRejectsEveryRemovedExecutionOperation(): void
    {
        $provider = new AiQueryProvider(
            $this->createMock(AiService::class),
            $this->createMock(SessionFactory::class),
        );

        foreach ([
            'generate',
            'generateText',
            'generateImage',
            'generateStream',
            'generateStreamBatch',
        ] as $legacyOperation) {
            try {
                $provider->execute($legacyOperation);
                self::fail($legacyOperation . ' must not be exposed by the generic AI query gateway.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString($legacyOperation, $exception->getMessage());
            }
        }
    }
}
