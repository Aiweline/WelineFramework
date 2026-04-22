<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\OpenAiProvider;

class OpenAiProviderJsonResponseFormatTest extends TestCase
{
    public function testBuildMessagesAddsJsonMentionWhenJsonObjectModeRequiresIt(): void
    {
        $provider = new OpenAiProvider();

        $messages = $this->invokeBuildMessages($provider, '只输出结构化对象。', [
            'response_format' => ['type' => 'json_object'],
        ]);

        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('valid JSON', $messages[0]['content']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('只输出结构化对象。', $messages[1]['content']);
    }

    public function testBuildMessagesKeepsExistingJsonInstructionsUntouched(): void
    {
        $provider = new OpenAiProvider();

        $messages = $this->invokeBuildMessages($provider, 'Return JSON only.', [
            'response_format' => ['type' => 'json_object'],
        ]);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Return JSON only.', $messages[0]['content']);
    }

    public function testBuildMessagesAppendsJsonMentionToExistingSystemMessage(): void
    {
        $provider = new OpenAiProvider();

        $messages = $this->invokeBuildMessages($provider, '', [
            'messages' => [
                ['role' => 'system', 'content' => 'Be concise.'],
                ['role' => 'user', 'content' => '输出结构化对象。'],
            ],
            'response_format' => ['type' => 'json_object'],
        ]);

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame("Be concise.\n\nReturn the response as valid JSON.", $messages[0]['content']);
    }

    private function invokeBuildMessages(OpenAiProvider $provider, string $prompt, array $params): array
    {
        $reflectionMethod = new \ReflectionMethod($provider, 'buildMessages');
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($provider, $prompt, $params);
    }
}
