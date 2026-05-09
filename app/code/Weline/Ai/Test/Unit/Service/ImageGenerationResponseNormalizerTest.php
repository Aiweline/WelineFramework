<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\ImageGenerationResponseNormalizer;

class ImageGenerationResponseNormalizerTest extends TestCase
{
    public function testGeminiGenerateContentResponseNormalizesInlineImage(): void
    {
        $result = ImageGenerationResponseNormalizer::fromGeminiGenerateContentResponse([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [
                        ['text' => 'Generated image prompt'],
                        ['inlineData' => [
                            'mimeType' => 'image/png',
                            'data' => 'abc123',
                        ]],
                    ],
                ],
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
        ], 'gemini-3.1-flash-image-preview', ['contents' => []], 'https://example.test/generateContent');

        $this->assertSame('Generated image prompt', $result['content']);
        $this->assertSame('STOP', $result['finish_reason']);
        $this->assertSame('https://example.test/generateContent', $result['request_url']);
        $this->assertSame('abc123', $result['images'][0]['b64_json'] ?? null);
        $this->assertSame('image/png', $result['images'][0]['mime_type'] ?? null);
        $this->assertSame('Generated image prompt', $result['images'][0]['revised_prompt'] ?? null);
        $this->assertSame(15, $result['usage']['total_tokens'] ?? 0);
    }

    public function testOpenAiImageResponseNormalizesBase64AndMimeType(): void
    {
        $result = ImageGenerationResponseNormalizer::fromOpenAiImageResponse([
            'data' => [[
                'b64_json' => 'xyz789',
                'revised_prompt' => 'clean icon',
            ]],
            'usage' => [
                'prompt_tokens' => 3,
                'completion_tokens' => 0,
                'total_tokens' => 3,
            ],
            'model' => 'gpt-image-1',
        ], 'gpt-image-1', ['output_format' => 'webp'], 'https://example.test/images/generations');

        $this->assertSame('xyz789', $result['images'][0]['b64_json'] ?? null);
        $this->assertSame('image/webp', $result['images'][0]['mime_type'] ?? null);
        $this->assertSame('gpt-image-1', $result['model']);
        $this->assertSame('https://example.test/images/generations', $result['request_url']);
    }

    public function testOpenAiImageResponseConvertsDataUrlToBase64Payload(): void
    {
        $result = ImageGenerationResponseNormalizer::fromOpenAiImageResponse([
            'data' => [[
                'url' => 'data:image/jpeg;base64,' . \base64_encode('jpeg-bytes'),
            ]],
            'model' => 'image-model',
        ], 'image-model', [], 'https://example.test/images/generations');

        $this->assertSame(\base64_encode('jpeg-bytes'), $result['images'][0]['b64_json'] ?? null);
        $this->assertSame('image/jpeg', $result['images'][0]['mime_type'] ?? null);
        $this->assertArrayNotHasKey('url', $result['images'][0] ?? []);
    }
}
